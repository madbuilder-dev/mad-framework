<?php

namespace Mad\Webhook;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porta de entrada dos webhooks recebidos:
 * `POST /api/webhooks/{slug}` e `POST /api/webhooks/{slug}/{key}`.
 *
 * Rota PÚBLICA, sem sessão e sem CSRF. O que a protege:
 *
 *  1. **Só existe o que foi declarado.** Slug fora de `app/config/webhooks.json`,
 *     desligado na definição, pausado no app ou com chave desconhecida = 404,
 *     igual a rota inexistente.
 *  2. **O corpo é lido uma vez** (`getContent()`) e esses mesmos bytes servem à
 *     assinatura e ao registro. Decodificar e recodificar o JSON antes de
 *     conferir mudaria os bytes e toda assinatura legítima falharia.
 *  3. **Falha fechada.** Assinatura ou token sem segredo definido no app = 503,
 *     nunca aceita. Tabelas ausentes (pacote atualizado antes da migration) =
 *     503: o remetente tenta de novo depois, e nenhum handler roda.
 *  4. **Idempotência antes do efeito.** A entrega é gravada com
 *     UNIQUE(endpoint, id do evento); a reentrega de um evento já processado
 *     responde `duplicate` sem chamar o handler.
 *  5. **Recusa não enche o banco.** Recusa incrementa contadores no endpoint e
 *     guarda uma amostra limitada de linhas SEM corpo.
 *  6. **Responde antes de processar** (exceto no modo `sync`): fila se houver
 *     worker vivo, senão o próprio processo depois de enviar a resposta.
 *
 * O motivo de uma recusa nunca vai no corpo da resposta.
 */
class WebhookController
{
    /**
     * Atributo de requisição que manda processar a entrega ANTES de responder.
     * Só o `mad:webhook test` o define (atributo não vem de fora: não é
     * cabeçalho, query nem corpo).
     */
    public const INLINE = 'mad.webhook.inline';

    public function __invoke(Request $request, string $slug, ?string $key = null): Response
    {
        $definition = WebhookRegistry::find($slug);
        if ($definition === null || ! $definition->enabled) {
            return self::deny(404);
        }

        if (! WebhookStore::tablesReady()) {
            return self::deny(503);
        }

        $endpoint = self::resolveEndpoint($definition, $key);
        if ($endpoint === null || (int) $endpoint->enabled !== 1) {
            return self::deny(404);
        }

        // Os bytes exatos recebidos. Lidos UMA vez; nada abaixo relê o corpo.
        $body = (string) $request->getContent();
        $inline = $request->attributes->get(self::INLINE) === true;

        $meta = [
            'method' => $request->getMethod(),
            'ip' => (string) $request->ip(),
            'headers' => $request->headers->all(),
            'mask' => array_filter([$definition->credentialHeader()]),
            'body_sha256' => hash('sha256', $body),
            'body_size' => strlen($body),
        ];
        $reject = static function (string $reason, int $status) use ($endpoint, $meta, $inline): Response {
            try {
                WebhookStore::recordRejection($endpoint, $reason, $meta, $inline);
            } catch (\Throwable $e) {
                // o registro da recusa não pode trocar a resposta por um 500
            }

            return self::deny($status);
        };

        if (strlen($body) > $definition->maxBodyBytes()) {
            return $reject('body_too_large', 413);
        }

        if (! WebhookVerifier::ipAllowed($definition->ipAllowlist, $meta['ip'])) {
            return $reject('ip_not_allowed', 403);
        }

        $reason = self::verify($definition, $endpoint, $body, $request, $key);
        if ($reason !== null) {
            return $reject($reason, $reason === WebhookVerifier::REASON_NO_SECRET ? 503 : 401);
        }

        $json = null;
        $fromJson = static function (string $path) use (&$json, $body): mixed {
            $json ??= is_array($decoded = json_decode($body, true)) ? $decoded : [];

            return Arr::get($json, $path);
        };

        $eventId = self::eventId($definition, $request, $body, $fromJson);
        $meta['event_type'] = self::eventType($definition, $request, $fromJson);

        $deliveryId = WebhookStore::insertDelivery($endpoint, $eventId, $body, $meta);
        if ($deliveryId === null) {
            $deliveryId = self::revive((int) $endpoint->id, $eventId);
            if ($deliveryId === null) {
                return self::json(['ok' => true, 'status' => 'duplicate']);
            }
            if ($deliveryId === 0) {
                return self::accepted(); // em andamento em outro processo
            }
        }

        if ($definition->mode === WebhookDefinition::MODE_SYNC) {
            $outcome = WebhookProcessor::process($deliveryId);
            self::afterResponse(null, $deliveryId, $inline);

            if (! $outcome['claimed']) {
                return self::accepted();
            }

            return $outcome['ok'] ? self::fromHandler($outcome['result']) : self::deny(500);
        }

        if ($inline) {
            WebhookProcessor::process($deliveryId);

            return self::accepted();
        }

        $queued = WebhookProcessor::shouldQueue($definition->mode) && WebhookProcessor::enqueue($deliveryId);
        self::afterResponse($queued ? null : $deliveryId, $deliveryId, false);

        return self::accepted();
    }

    // ── passos ──────────────────────────────────────────────────────────────

    /**
     * Endpoint que atende esta URL, ou `null` (404).
     *
     *  - por unidade: a chave da URL identifica o endpoint da unidade;
     *  - geral com token na URL: o endpoint é o geral, e a chave é conferida
     *    depois, como credencial;
     *  - geral nos demais casos: a URL não leva chave.
     */
    private static function resolveEndpoint(WebhookDefinition $definition, ?string $key): ?object
    {
        if ($definition->isUnitScoped()) {
            if ($key === null) {
                return null;
            }
            $endpoint = WebhookStore::endpointByKey($definition->slug, $key);

            return $endpoint !== null && $endpoint->unit_id !== null ? $endpoint : null;
        }

        if ($key !== null && ! $definition->tokenInUrl()) {
            return null;
        }

        return WebhookStore::globalEndpoint($definition->slug);
    }

    /** `null` = credencial confere; senão o motivo da recusa. */
    private static function verify(WebhookDefinition $definition, object $endpoint, string $body, Request $request, ?string $key): ?string
    {
        if ($definition->tokenInUrl() && ! $definition->isUnitScoped()) {
            $hash = (string) ($endpoint->key_hash ?? '');
            if ($hash === '') {
                return WebhookVerifier::REASON_NO_SECRET;
            }
            if ($key === null) {
                return WebhookVerifier::REASON_TOKEN_MISSING;
            }

            return hash_equals($hash, hash('sha256', $key)) ? null : WebhookVerifier::REASON_TOKEN_INVALID;
        }

        return WebhookVerifier::check(
            $definition,
            $body,
            static fn (string $name): ?string => $request->headers->get($name),
            $definition->needsSecret() ? WebhookStore::secrets($endpoint) : [],
        );
    }

    /** @param  \Closure(string): mixed  $fromJson */
    private static function eventId(WebhookDefinition $definition, Request $request, string $body, \Closure $fromJson): string
    {
        $value = match ($definition->eventId['from']) {
            'header' => $request->headers->get((string) $definition->eventId['key']),
            'json' => $fromJson((string) $definition->eventId['key']),
            'body_hash' => 'sha256:' . hash('sha256', $body),
            default => null,
        };

        $value = is_scalar($value) ? trim((string) $value) : '';

        // Remetente que não mandou o identificador: a entrega é única (sem como deduplicar).
        return $value !== '' ? WebhookStore::normalizeEventId($value) : WebhookStore::generatedEventId();
    }

    /** @param  \Closure(string): mixed  $fromJson */
    private static function eventType(WebhookDefinition $definition, Request $request, \Closure $fromJson): ?string
    {
        if ($definition->eventType === null) {
            return null;
        }

        $value = $definition->eventType['from'] === 'header'
            ? $request->headers->get($definition->eventType['key'])
            : $fromJson($definition->eventType['key']);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Reentrega de um evento que já tem linha.
     *
     * @return int|null `null` = já processado (duplicata); `0` = em andamento,
     *   nada a fazer; id = a entrega voltou para `received` e deve ser processada.
     */
    private static function revive(int $endpointId, string $eventId): ?int
    {
        $existing = WebhookStore::findDelivery($endpointId, $eventId);
        if ($existing === null) {
            return 0;
        }
        $id = (int) $existing->id;

        switch ($existing->status) {
            case WebhookStore::STATUS_PROCESSED:
                return null;

            case WebhookStore::STATUS_FAILED:
                return WebhookStore::release($id) ? $id : 0;

            case WebhookStore::STATUS_RECEIVED:
                return WebhookStore::isStale($existing) ? $id : 0;

            case WebhookStore::STATUS_PROCESSING:
                return WebhookStore::failInterrupted($id) && WebhookStore::release($id) ? $id : 0;
        }

        return 0;
    }

    /**
     * Trabalho de depois da resposta: processar a entrega que não foi para a
     * fila e retomar, no máximo, UMA entrega parada.
     *
     * Com FPM o `fastcgi_finish_request()` (chamado pelo `Response::send()`)
     * solta o remetente antes disto; no Octane o callback roda depois do envio.
     * Sem nenhum dos dois (mod_php, `artisan serve`), o `Content-Length` +
     * `Connection: close` de `accepted()` é o que encerra a resposta para o
     * remetente enquanto o processo continua.
     */
    private static function afterResponse(?int $processId, int $currentId, bool $skipSweep): void
    {
        if ($processId === null && $skipSweep) {
            return;
        }

        @ignore_user_abort(true);

        // Uma vez só: num processo que atende várias requisições com a mesma
        // aplicação, o callback registrado por uma não pode rodar de novo no
        // fim das seguintes.
        $done = false;

        app()->terminating(static function () use ($processId, $currentId, $skipSweep, &$done): void {
            if ($done) {
                return;
            }
            $done = true;

            try {
                if ($processId !== null) {
                    WebhookProcessor::process($processId);
                }
                if (! $skipSweep) {
                    WebhookProcessor::sweepOne($currentId);
                }
            } catch (\Throwable $e) {
                // a resposta já foi; o que sobrou fica para a varredura
            }
        });
    }

    // ── respostas ───────────────────────────────────────────────────────────

    private static function accepted(): Response
    {
        $response = self::json(['ok' => true, 'status' => 'accepted']);

        if (! function_exists('fastcgi_finish_request') && ! function_exists('litespeed_finish_request')) {
            $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
            $response->headers->set('Connection', 'close');
        }

        return $response;
    }

    /** Recusa: sempre `{"ok":false}`, sem dizer o motivo. */
    private static function deny(int $status): Response
    {
        return self::json(['ok' => false], $status, $status === 503 ? ['Retry-After' => '60'] : []);
    }

    /** Retorno do handler no modo `sync` vira a resposta. */
    private static function fromHandler(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if ($result === null) {
            return self::json(['ok' => true]);
        }
        if (is_string($result)) {
            return new \Illuminate\Http\Response($result, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($result instanceof Arrayable) {
            $result = $result->toArray();
        }

        return self::json($result);
    }

    /** @param  array<string, string>  $headers */
    private static function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers + ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
