<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Mad\Webhook\WebhookController;
use Mad\Webhook\WebhookDefinition;
use Mad\Webhook\WebhookRegistry;
use Mad\Webhook\WebhookScope;
use Mad\Webhook\WebhookStore;
use Mad\Webhook\WebhookVerifier;

/**
 * mad:webhook — operação dos webhooks recebidos pelo terminal.
 *
 *   php artisan mad:webhook list
 *   php artisan mad:webhook secret {slug} [--unit=ID] [--generate | --value=TEXTO]
 *   php artisan mad:webhook test {slug} [--unit=ID] [--payload=JSON] [--event-id=ID] [--tamper]
 *
 * Com `--json` a saída é UMA linha JSON em stdout (é assim que a plataforma lê
 * o resultado do teste no Teste Online).
 *
 * `test` monta uma requisição assinada conforme a definição e a despacha pelo
 * kernel HTTP do próprio app: passa pela mesma rota, pelo mesmo limite e pela
 * mesma verificação de uma entrega real, e é processada antes de o comando
 * voltar. O segredo de teste, quando falta um, nasce e fica dentro do app.
 */
class WebhookCommand extends Command
{
    protected $signature = 'mad:webhook
        {action : list | secret | test}
        {slug? : Slug do webhook (secret e test)}
        {--unit= : Id da unidade (webhook por unidade)}
        {--generate : secret: gera um segredo novo e mostra uma vez}
        {--value= : secret: grava o segredo informado}
        {--payload= : test: corpo JSON a enviar}
        {--event-id= : test: identificador do evento (repita para testar a deduplicação)}
        {--tamper : test: altera o corpo depois de assinar (a entrega tem que ser recusada)}
        {--json : Saída em uma linha JSON}';

    protected $description = 'Webhooks recebidos: lista, define segredo e envia uma entrega de teste';

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->runList(),
            'secret' => $this->runSecret(),
            'test' => $this->runTest(),
            default => $this->refuse('Ação desconhecida. Use list, secret ou test.'),
        };
    }

    // ── list ────────────────────────────────────────────────────────────────

    private function runList(): int
    {
        $tables = WebhookStore::tablesReady();
        $webhooks = [];

        foreach (WebhookRegistry::all() as $definition) {
            $endpoints = [];
            foreach ($tables ? WebhookStore::endpointsFor($definition->slug) : [] as $endpoint) {
                $endpoints[] = [
                    'id' => (int) $endpoint->id,
                    'unit_id' => $endpoint->unit_id !== null ? (int) $endpoint->unit_id : null,
                    'enabled' => (int) $endpoint->enabled === 1,
                    'has_secret' => $definition->tokenInUrl() ? ($endpoint->key_hash ?? '') !== '' : WebhookStore::hasSecret($endpoint),
                    'rejected_count' => (int) $endpoint->rejected_count,
                    'last_received_at' => $endpoint->last_received_at,
                ];
            }
            $webhooks[] = $definition->toSummary() + ['endpoints' => $endpoints];
        }

        if ($this->option('json')) {
            return $this->emit(['ok' => true, 'tables' => $tables, 'webhooks' => $webhooks]);
        }

        if (! $tables) {
            $this->warn('As tabelas dos webhooks ainda não existem neste app. Rode: php artisan migrate');
        }
        if ($webhooks === []) {
            $this->line('Nenhum webhook definido (' . basename(\Mad\Webhook\WebhookConfig::definitionsPath()) . ').');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Nome', 'Ligado', 'Escopo', 'Verificação', 'Modo', 'Handler', 'Endpoints'],
            array_map(static fn (array $w) => [
                $w['slug'], $w['name'], $w['enabled'] ? 'sim' : 'não', $w['scope'],
                $w['verification'], $w['mode'], $w['handler'], count($w['endpoints']),
            ], $webhooks),
        );

        return self::SUCCESS;
    }

    // ── secret ──────────────────────────────────────────────────────────────

    private function runSecret(): int
    {
        [$definition, $endpoint, $error] = $this->target();
        if ($error !== null) {
            return $this->refuse($error);
        }

        $generate = (bool) $this->option('generate');
        $value = $this->option('value');
        $value = is_string($value) ? $value : null;
        if ($generate && $value !== null) {
            return $this->refuse('Use --generate ou --value, não os dois.');
        }

        $secret = null;
        $id = (int) $endpoint->id;

        if ($generate || $value !== null) {
            if ($definition->tokenInUrl()) {
                // A credencial é a chave da URL.
                try {
                    $value !== null ? WebhookStore::setKey($id, $value) : ($secret = WebhookStore::regenerateKey($id));
                } catch (\InvalidArgumentException $e) {
                    return $this->refuse($e->getMessage());
                }
            } elseif ($definition->needsSecret()) {
                if ($value === '') {
                    return $this->refuse('O segredo não pode ser vazio.');
                }
                $secret = $value === null ? WebhookStore::newSecret() : null;
                WebhookStore::setSecret($id, $value ?? $secret);
            } else {
                return $this->refuse('Este webhook confere só o IP de origem: não há segredo a definir.');
            }
            $endpoint = WebhookStore::endpoint($id);
        }

        $payload = [
            'ok' => true,
            'slug' => $definition->slug,
            'unit_id' => $endpoint->unit_id !== null ? (int) $endpoint->unit_id : null,
            'endpoint_id' => $id,
            'has_secret' => $definition->tokenInUrl() ? ($endpoint->key_hash ?? '') !== '' : WebhookStore::hasSecret($endpoint),
            'generated' => $secret !== null,
            'secret' => $secret,
            'url_path' => WebhookStore::urlPath($definition, $endpoint),
        ];

        if ($this->option('json')) {
            return $this->emit($payload);
        }

        $this->line('Endereço: ' . $payload['url_path']);
        if ($secret !== null) {
            $this->info('Segredo gerado (aparece só agora): ' . $secret);
        } elseif ($value !== null) {
            $this->info('Segredo gravado.');
        } else {
            $this->line($payload['has_secret'] ? 'Segredo definido.' : 'Sem segredo definido: as entregas são recusadas até definir um.');
        }

        return self::SUCCESS;
    }

    // ── test ────────────────────────────────────────────────────────────────

    private function runTest(): int
    {
        [$definition, $endpoint, $error] = $this->target();
        if ($error !== null) {
            return $this->failTest($error);
        }

        $payload = ['test' => true, 'sent_at' => date('c')];
        $custom = $this->option('payload');
        if (is_string($custom) && $custom !== '') {
            $payload = json_decode($custom, true);
            if (! is_array($payload)) {
                return $this->failTest('--payload precisa ser um objeto JSON.');
            }
        }

        // Segredo: sem um definido, nasce um de teste — dentro do app.
        $id = (int) $endpoint->id;
        $secretGenerated = false;
        if ($definition->needsSecret() && ! WebhookStore::hasSecret($endpoint)) {
            WebhookStore::setSecret($id, WebhookStore::newSecret());
            $secretGenerated = true;
        }
        if ($definition->usesUrlKey() && WebhookStore::urlKey($endpoint) === null) {
            WebhookStore::regenerateKey($id);
            $secretGenerated = $secretGenerated || $definition->tokenInUrl();
        }
        $endpoint = WebhookStore::endpoint($id);
        $secret = WebhookStore::secrets($endpoint)[0] ?? '';

        $eventId = (string) ($this->option('event-id') ?: 'test-' . bin2hex(random_bytes(8)));
        if ($definition->eventId['from'] === 'json') {
            Arr::set($payload, (string) $definition->eventId['key'], $eventId);
        }
        if ($definition->eventType !== null && $definition->eventType['from'] === 'json'
            && Arr::get($payload, $definition->eventType['key']) === null) {
            Arr::set($payload, $definition->eventType['key'], 'mad.test');
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = WebhookVerifier::signedHeaders($definition, $body, $secret);
        if ($definition->eventId['from'] === 'header') {
            $headers[(string) $definition->eventId['key']] = $eventId;
        }
        if ($definition->eventType !== null && $definition->eventType['from'] === 'header') {
            $headers[$definition->eventType['key']] = 'mad.test';
        }

        if ($this->option('tamper')) {
            $body .= ' '; // um byte a mais depois de assinar: JSON igual, assinatura diferente
        }

        $before = (int) WebhookStore::db()->table(WebhookStore::DELIVERIES)->max('id');
        $response = $this->dispatchRequest($definition, $endpoint, $headers, $body);
        $status = $response->getStatusCode();

        $delivery = WebhookStore::db()->table(WebhookStore::DELIVERIES)
            ->where('endpoint_id', $id)->where('id', '>', $before)->orderByDesc('id')->first()
            ?? ($status === 200 ? WebhookStore::findDelivery($id, WebhookStore::normalizeEventId($eventId)) : null);

        $ok = $this->option('tamper')
            ? $status === 401
            : $status === 200 && $delivery !== null && $delivery->status === WebhookStore::STATUS_PROCESSED;

        $result = [
            'ok' => $ok,
            'http_status' => $status,
            'response' => (string) $response->getContent(),
            'secret_generated' => $secretGenerated,
            'delivery' => $delivery === null ? null : [
                'id' => (int) $delivery->id,
                'status' => (string) $delivery->status,
                'signature_ok' => (int) $delivery->signature_ok === 1,
                'event_id' => (string) $delivery->event_id,
                'attempts' => (int) $delivery->attempts,
                'error' => $delivery->error ?? ($delivery->reject_reason ?? null),
            ],
        ];

        if ($this->option('json')) {
            $this->emit($result);

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line("HTTP {$status}  " . $result['response']);
        if ($secretGenerated) {
            $this->line('Um segredo de teste foi gerado e gravado neste app.');
        }
        if ($delivery !== null) {
            $this->line(sprintf(
                'Entrega #%d: %s (tentativas: %d)%s',
                $result['delivery']['id'],
                $result['delivery']['status'],
                $result['delivery']['attempts'],
                $result['delivery']['error'] ? ' — ' . $result['delivery']['error'] : '',
            ));
        }
        $ok
            ? $this->info($this->option('tamper') ? 'Corpo adulterado recusado, como esperado.' : 'Entrega processada.')
            : $this->error($this->option('tamper') ? 'O corpo adulterado NÃO foi recusado.' : 'A entrega não foi processada.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Despacha a requisição pelo kernel HTTP do app e devolve a resposta. O
     * `request` do container é restaurado depois: o comando pode estar rodando
     * dentro de uma requisição web (`Artisan::call`).
     *
     * @param  array<string, string>  $headers
     */
    private function dispatchRequest(WebhookDefinition $definition, object $endpoint, array $headers, string $body): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->sourceIp($definition)];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create(WebhookStore::urlPath($definition, $endpoint), 'POST', [], [], [], $server, $body);
        $request->attributes->set(WebhookController::INLINE, true);

        $app = $this->laravel;
        $original = $app->bound('request') ? $app->make('request') : null;

        try {
            return $app->make(HttpKernel::class)->handle($request);
        } finally {
            if ($original !== null) {
                $app->instance('request', $original);
                Facade::clearResolvedInstance('request');
            }
        }
    }

    /** IP de origem da entrega de teste: um que a lista de IPs do webhook aceite. */
    private function sourceIp(WebhookDefinition $definition): string
    {
        foreach ($definition->ipAllowlist as $entry) {
            $ip = explode('/', $entry, 2)[0];
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }

    // ── auxiliares ──────────────────────────────────────────────────────────

    /**
     * Definição + endpoint pedidos na linha de comando (o endpoint é criado se faltar).
     *
     * @return array{0: ?WebhookDefinition, 1: ?object, 2: ?string} o terceiro item é o erro
     */
    private function target(): array
    {
        $slug = (string) ($this->argument('slug') ?? '');
        if ($slug === '') {
            return [null, null, 'Informe o slug do webhook.'];
        }

        $definition = WebhookRegistry::find($slug);
        if ($definition === null) {
            return [null, null, "Webhook '{$slug}' não está definido neste app."];
        }
        if (! WebhookStore::tablesReady()) {
            return [null, null, 'As tabelas dos webhooks ainda não existem neste app. Rode: php artisan migrate'];
        }

        $unit = $this->option('unit');
        $unitId = is_numeric($unit) ? (int) $unit : null;

        if ($definition->isUnitScoped()) {
            if ($unitId === null || $unitId <= 0) {
                return [null, null, 'Este webhook é por unidade: informe --unit=ID.'];
            }
            // Sem endpoint ainda: id errado não pode virar endpoint órfão (com chave e segredo).
            if (WebhookStore::unitEndpoint($slug, $unitId) === null && ! WebhookScope::unitAvailable($unitId)) {
                return [null, null, "Unidade {$unitId} não existe ou está inativa."];
            }

            return [$definition, WebhookStore::createUnitEndpoint($slug, $unitId)[0], null];
        }
        if ($unitId !== null) {
            return [null, null, 'Este webhook é de escopo geral: não use --unit.'];
        }

        return [$definition, WebhookStore::globalEndpoint($slug), null];
    }

    /** @param  array<string, mixed>  $payload */
    private function emit(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->emit(['ok' => false, 'error' => $message]);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /** Falha antes de enviar: mesma forma da saída do teste, com `error`. */
    private function failTest(string $message): int
    {
        if ($this->option('json')) {
            $this->emit([
                'ok' => false, 'http_status' => 0, 'response' => '', 'secret_generated' => false,
                'delivery' => null, 'error' => $message,
            ]);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
