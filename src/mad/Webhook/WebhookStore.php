<?php

namespace Mad\Webhook;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mad\Security\SecretMasker;
use Mad\Security\StoredSecret;

/**
 * Persistência dos webhooks recebidos: endpoints (`mad_sys_webhook_endpoint`) e
 * entregas (`mad_sys_webhook_delivery`).
 *
 * Tudo por query builder, na conexão `config('mad.webhook.connection') ?: 'iam'`
 * — o pacote não depende dos models do app (eles servem só às telas) e a conexão
 * é control-plane: nunca é repontada para o banco de uma empresa, então a
 * entrega de uma unidade com banco dedicado continua registrada no banco
 * principal.
 *
 * Situações da entrega:
 *
 *   received ──claim()──► processing ──► processed
 *                              └───────► failed ──release()──► received …
 *   rejected  (recusa: sem corpo, fora da idempotência)
 *
 * `next_attempt_at` tem um papel por situação: em `failed` é quando tentar de
 * novo (nulo = desistiu); em `processing` é até quando a reivindicação vale
 * (passou disso, o processo morreu no meio); em `received` é quando a entrega
 * foi devolvida à fila (conta para saber se está parada).
 */
final class WebhookStore
{
    public const ENDPOINTS = 'mad_sys_webhook_endpoint';
    public const DELIVERIES = 'mad_sys_webhook_delivery';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    /** Segundos que uma reivindicação (`processing`) vale antes de ser dada como interrompida. */
    public const LEASE_SECONDS = 900;

    /** Recuo entre tentativas (segundos), pela quantidade de tentativas já feitas. */
    private const BACKOFF = [60, 300, 900, 3600, 21600];

    /** Intervalo mínimo entre duas amostras de recusa quando a amostra está cheia. */
    private const SAMPLE_INTERVAL = 60;

    private const EVENT_ID_MAX = 190;

    /** @var array<string, true> conexões em que as tabelas já foram vistas */
    private static array $ready = [];

    // ── infraestrutura ──────────────────────────────────────────────────────

    public static function db(): ConnectionInterface
    {
        return DB::connection(WebhookConfig::connection());
    }

    /**
     * As duas tabelas existem? Um app já publicado recebe o pacote antes da
     * migration: aí o endereço responde 503 em vez de estourar. O "sim" é
     * memorizado por processo; o "não" é conferido de novo a cada chamada.
     */
    public static function tablesReady(): bool
    {
        $connection = WebhookConfig::connection();
        if (isset(self::$ready[$connection])) {
            return true;
        }

        try {
            $schema = Schema::connection($connection);
            if ($schema->hasTable(self::ENDPOINTS) && $schema->hasTable(self::DELIVERIES)) {
                self::$ready[$connection] = true;

                return true;
            }
        } catch (\Throwable $e) {
            // sem conexão = sem tabelas
        }

        return false;
    }

    /** Esquece o que foi memorizado (testes). */
    public static function flush(): void
    {
        self::$ready = [];
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function at(int $offsetSeconds): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    private static function endpoints(): Builder
    {
        return self::db()->table(self::ENDPOINTS);
    }

    private static function deliveries(): Builder
    {
        return self::db()->table(self::DELIVERIES);
    }

    // ── endpoints ───────────────────────────────────────────────────────────

    public static function endpoint(int $id): ?object
    {
        return self::endpoints()->where('id', $id)->first();
    }

    /**
     * Endpoint geral do webhook (linha real, `unit_id` nulo), criado sob
     * demanda. Índice único não segura duas linhas com `unit_id` nulo, então a
     * corrida entre duas criações é resolvida aqui: fica a de menor id.
     */
    public static function globalEndpoint(string $slug, bool $create = true): ?object
    {
        $find = static fn () => self::endpoints()->where('webhook', $slug)->whereNull('unit_id')->orderBy('id')->first();

        $row = $find();
        if ($row !== null || ! $create) {
            return $row;
        }

        $id = (int) self::endpoints()->insertGetId([
            'webhook' => $slug,
            'unit_id' => null,
            'enabled' => 1,
            'rejected_count' => 0,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ]);

        $row = $find();
        if ($row !== null && (int) $row->id !== $id) {
            self::endpoints()->where('id', $id)->delete(); // perdeu a corrida
        }

        return $row;
    }

    public static function unitEndpoint(string $slug, int $unitId): ?object
    {
        return self::endpoints()->where('webhook', $slug)->where('unit_id', $unitId)->orderBy('id')->first();
    }

    /**
     * Cria o endpoint de uma unidade já com a chave da URL.
     *
     * @return array{0: object, 1: string} a linha e a chave em claro
     */
    public static function createUnitEndpoint(string $slug, int $unitId): array
    {
        $existing = self::unitEndpoint($slug, $unitId);
        if ($existing !== null) {
            return [$existing, self::urlKey($existing) ?? self::regenerateKey((int) $existing->id)];
        }

        $key = self::newKey();
        $id = (int) self::endpoints()->insertGetId([
            'webhook' => $slug,
            'unit_id' => $unitId,
            'key_hash' => hash('sha256', $key),
            'key_sealed' => StoredSecret::seal($key),
            'enabled' => 1,
            'rejected_count' => 0,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ]);

        return [self::endpoint($id), $key];
    }

    /**
     * Endpoint dono de uma chave de URL. A busca é pelo sha256 (a chave em
     * claro nunca é comparada pelo banco) e o resultado ainda passa por
     * `hash_equals`.
     */
    public static function endpointByKey(string $slug, string $key): ?object
    {
        $hash = hash('sha256', $key);
        $row = self::endpoints()->where('key_hash', $hash)->where('webhook', $slug)->first();

        return $row !== null && hash_equals((string) $row->key_hash, $hash) ? $row : null;
    }

    public static function endpointsFor(string $slug): array
    {
        return self::endpoints()->where('webhook', $slug)->orderBy('id')->get()->all();
    }

    /** Chave nova para a URL: 40 caracteres de `[A-Za-z0-9]`. */
    public static function newKey(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key = '';
        for ($i = 0; $i < 40; $i++) {
            $key .= $alphabet[random_int(0, 61)];
        }

        return $key;
    }

    /** Segredo novo de assinatura ou token. */
    public static function newSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    /** Troca a chave da URL; a anterior deixa de valer na hora. Devolve a chave em claro. */
    public static function regenerateKey(int $endpointId): string
    {
        $key = self::newKey();
        self::setKey($endpointId, $key);

        return $key;
    }

    /** Grava uma chave de URL escolhida (formato de `WebhookDefinition::KEY_PATTERN`). */
    public static function setKey(int $endpointId, string $key): void
    {
        if (preg_match('/^' . WebhookDefinition::KEY_PATTERN . '$/', $key) !== 1) {
            throw new \InvalidArgumentException('A chave do endereço precisa ter de 24 a 64 letras e números.');
        }

        self::endpoints()->where('id', $endpointId)->update([
            'key_hash' => hash('sha256', $key),
            'key_sealed' => StoredSecret::seal($key),
            'updated_at' => self::now(),
        ]);
    }

    /** Caminho público do endpoint (`/api/webhooks/<slug>[/<chave>]`); a chave entra quando a definição a usa. */
    public static function urlPath(WebhookDefinition $definition, object $endpoint): string
    {
        $path = '/api/webhooks/' . $definition->slug;
        if (! $definition->usesUrlKey()) {
            return $path;
        }
        $key = self::urlKey($endpoint);

        return $key !== null ? $path . '/' . $key : $path . '/{chave}';
    }

    /** Chave da URL em claro (para montar o endereço na tela); nula se não há ou não dá para ler. */
    public static function urlKey(object $endpoint): ?string
    {
        $key = StoredSecret::open($endpoint->key_sealed ?? null, 'Webhooks › chave do endereço');

        return $key !== '' ? $key : null;
    }

    /**
     * Grava um segredo novo. O atual vira o anterior e continua valendo até a
     * próxima troca (ou até `dropPreviousSecret()`): o remetente pode ser
     * reconfigurado sem perder entrega no meio.
     */
    public static function setSecret(int $endpointId, string $plain): void
    {
        $row = self::endpoint($endpointId);
        if ($row === null || $plain === '') {
            return;
        }

        $current = StoredSecret::open($row->secret ?? null, 'Webhooks › segredo');
        if ($current !== '' && hash_equals($current, $plain)) {
            return; // o mesmo segredo: nada a trocar (e não apaga o anterior)
        }

        self::endpoints()->where('id', $endpointId)->update([
            'secret' => StoredSecret::seal($plain),
            'secret_previous' => $current !== '' ? StoredSecret::seal($current) : null,
            'updated_at' => self::now(),
        ]);
    }

    public static function dropPreviousSecret(int $endpointId): void
    {
        self::endpoints()->where('id', $endpointId)->update(['secret_previous' => null, 'updated_at' => self::now()]);
    }

    /** @return string[] segredos que valem agora (atual e, se houver, o anterior) */
    public static function secrets(object $endpoint): array
    {
        return array_values(array_filter([
            StoredSecret::open($endpoint->secret ?? null, 'Webhooks › segredo'),
            StoredSecret::open($endpoint->secret_previous ?? null, 'Webhooks › segredo anterior'),
        ], static fn (string $s) => $s !== ''));
    }

    public static function hasSecret(object $endpoint): bool
    {
        return StoredSecret::usable($endpoint->secret ?? null);
    }

    public static function setEnabled(int $endpointId, bool $enabled): void
    {
        self::endpoints()->where('id', $endpointId)->update(['enabled' => $enabled ? 1 : 0, 'updated_at' => self::now()]);
    }

    public static function deleteEndpoint(int $endpointId): void
    {
        self::deliveries()->where('endpoint_id', $endpointId)->delete();
        self::endpoints()->where('id', $endpointId)->delete();
    }

    // ── recusa ──────────────────────────────────────────────────────────────

    /**
     * Registra uma recusa: contador e motivo no endpoint e, dentro do limite da
     * amostra, uma linha `rejected` SEM corpo. Com a amostra cheia, a mais
     * antiga dá lugar à nova no máximo uma vez por minuto — uma enxurrada de
     * requisições forjadas custa um UPDATE cada, não uma linha cada.
     *
     * @param  array<string, mixed>  $meta  method, ip, headers, body_sha256, body_size, unit_id, signature_ok
     * @return int|null id da linha de amostra, quando gravada
     */
    public static function recordRejection(object $endpoint, string $reason, array $meta, bool $forceSample = false): ?int
    {
        $now = self::now();
        $endpointId = (int) $endpoint->id;

        self::endpoints()->where('id', $endpointId)->update([
            'rejected_count' => self::db()->raw('rejected_count + 1'),
            'last_rejected_at' => $now,
            'last_reject_reason' => $reason,
        ]);

        $max = WebhookConfig::rejectedSample();
        if ($max <= 0) {
            return null;
        }

        $samples = self::deliveries()->where('endpoint_id', $endpointId)->where('status', self::STATUS_REJECTED);
        $count = (int) (clone $samples)->count();
        if ($count >= $max) {
            $newest = (string) (clone $samples)->max('received_at');
            if (! $forceSample && $newest !== '' && strtotime($newest) > time() - self::SAMPLE_INTERVAL) {
                return null;
            }
            $drop = (clone $samples)->orderBy('id')->limit($count - $max + 1)->pluck('id')->all();
            if ($drop !== []) {
                self::deliveries()->whereIn('id', $drop)->delete();
            }
        }

        return (int) self::deliveries()->insertGetId([
            'endpoint_id' => $endpointId,
            'webhook' => (string) $endpoint->webhook,
            'event_id' => 'rejected-' . bin2hex(random_bytes(12)),
            'event_type' => null,
            'status' => self::STATUS_REJECTED,
            'signature_ok' => ! empty($meta['signature_ok']) ? 1 : 0,
            'reject_reason' => $reason,
            'method' => (string) ($meta['method'] ?? 'POST'),
            'ip' => self::clip((string) ($meta['ip'] ?? ''), 45),
            'headers' => self::encodeHeaders((array) ($meta['headers'] ?? []), (array) ($meta['mask'] ?? [])),
            'body' => null,
            'body_encoding' => 'utf8',
            'body_sha256' => $meta['body_sha256'] ?? null,
            'body_size' => (int) ($meta['body_size'] ?? 0),
            'attempts' => 0,
            'unit_id' => $endpoint->unit_id !== null ? (int) $endpoint->unit_id : null,
            'received_at' => $now,
        ]);
    }

    // ── entregas ────────────────────────────────────────────────────────────

    public static function delivery(int $id): ?object
    {
        return self::deliveries()->where('id', $id)->first();
    }

    public static function findDelivery(int $endpointId, string $eventId): ?object
    {
        return self::deliveries()->where('endpoint_id', $endpointId)->where('event_id', $eventId)->first();
    }

    public static function latestRejection(int $endpointId, int $afterId = 0): ?object
    {
        return self::deliveries()->where('endpoint_id', $endpointId)->where('status', self::STATUS_REJECTED)
            ->where('id', '>', $afterId)->orderByDesc('id')->first();
    }

    /** Id de evento que cabe na coluna (id longo demais vira o hash dele). */
    public static function normalizeEventId(string $eventId): string
    {
        return strlen($eventId) > self::EVENT_ID_MAX ? 'sha256:' . hash('sha256', $eventId) : $eventId;
    }

    /** Id para entrega sem identificador do remetente: toda entrega é única. */
    public static function generatedEventId(): string
    {
        return 'auto-' . bin2hex(random_bytes(16));
    }

    /**
     * Grava a entrega aceita (situação `received`). O corpo vai byte a byte:
     * UTF-8 válido fica como texto; qualquer outra coisa (binário, Latin-1,
     * byte nulo, que o Postgres não aceita em texto) vai em base64.
     *
     * `null` = já existe entrega com este id de evento neste endpoint (a
     * UNIQUE(endpoint_id, event_id) é a idempotência; o catch cobre a corrida
     * entre duas entregas simultâneas).
     *
     * @param  array<string, mixed>  $meta  event_type, method, ip, headers, mask
     */
    public static function insertDelivery(object $endpoint, string $eventId, string $body, array $meta): ?int
    {
        $endpointId = (int) $endpoint->id;
        if (self::findDelivery($endpointId, $eventId) !== null) {
            return null;
        }

        $utf8 = ! str_contains($body, "\0") && mb_check_encoding($body, 'UTF-8');
        $now = self::now();

        try {
            $id = (int) self::deliveries()->insertGetId([
                'endpoint_id' => $endpointId,
                'webhook' => (string) $endpoint->webhook,
                'event_id' => $eventId,
                'event_type' => isset($meta['event_type']) ? self::clip((string) $meta['event_type'], 120) : null,
                'status' => self::STATUS_RECEIVED,
                'signature_ok' => 1,
                'reject_reason' => null,
                'method' => (string) ($meta['method'] ?? 'POST'),
                'ip' => self::clip((string) ($meta['ip'] ?? ''), 45),
                'headers' => self::encodeHeaders((array) ($meta['headers'] ?? []), (array) ($meta['mask'] ?? [])),
                'body' => $utf8 ? $body : base64_encode($body),
                'body_encoding' => $utf8 ? 'utf8' : 'base64',
                'body_sha256' => hash('sha256', $body),
                'body_size' => strlen($body),
                'attempts' => 0,
                'unit_id' => $endpoint->unit_id !== null ? (int) $endpoint->unit_id : null,
                'received_at' => $now,
            ]);
        } catch (\Throwable $e) {
            if (self::findDelivery($endpointId, $eventId) !== null) {
                return null; // corrida perdida: a outra entrega é a que vale
            }

            throw $e;
        }

        self::endpoints()->where('id', $endpointId)->update(['last_received_at' => $now]);

        return $id;
    }

    /** Corpo exatamente como chegou. */
    public static function rawBody(object $delivery): string
    {
        $body = (string) ($delivery->body ?? '');
        if (($delivery->body_encoding ?? 'utf8') === 'base64') {
            $decoded = base64_decode($body, true);

            return $decoded === false ? '' : $decoded;
        }

        return $body;
    }

    /** @return array<string, string> cabeçalhos gravados (nomes em minúsculas, segredos mascarados) */
    public static function headers(object $delivery): array
    {
        $headers = json_decode((string) ($delivery->headers ?? ''), true);

        return is_array($headers) ? array_map('strval', $headers) : [];
    }

    // ── ciclo de processamento ──────────────────────────────────────────────

    /**
     * Reivindicação ATÔMICA: só um processo leva a entrega de `received` para
     * `processing`. Quem não afetou linha nenhuma não processa.
     */
    public static function claim(int $deliveryId): bool
    {
        return self::deliveries()->where('id', $deliveryId)->where('status', self::STATUS_RECEIVED)->update([
            'status' => self::STATUS_PROCESSING,
            'attempts' => self::db()->raw('attempts + 1'),
            'next_attempt_at' => self::at(self::LEASE_SECONDS),
        ]) === 1;
    }

    public static function markProcessed(int $deliveryId, int $durationMs): void
    {
        self::deliveries()->where('id', $deliveryId)->where('status', self::STATUS_PROCESSING)->update([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => self::now(),
            'duration_ms' => $durationMs,
            'error' => null,
            'next_attempt_at' => null,
        ]);
    }

    /** Falha: agenda a próxima tentativa com recuo, ou desiste ao atingir o máximo. */
    public static function markFailed(int $deliveryId, string $error, ?int $durationMs = null): void
    {
        $row = self::delivery($deliveryId);
        if ($row === null) {
            return;
        }

        $attempts = (int) $row->attempts;
        $retry = $attempts < WebhookConfig::maxAttempts();
        $delay = self::BACKOFF[min(max($attempts, 1), count(self::BACKOFF)) - 1];

        self::deliveries()->where('id', $deliveryId)->where('status', self::STATUS_PROCESSING)->update([
            'status' => self::STATUS_FAILED,
            'error' => self::clip(mb_scrub($error, 'UTF-8'), 1000),
            'duration_ms' => $durationMs,
            'next_attempt_at' => $retry ? self::at($delay) : null,
        ]);
    }

    /**
     * Devolve à fila uma entrega que falhou (nova tentativa, reentrega do
     * remetente ou o botão Reprocessar). Também atômico: duas devoluções
     * simultâneas não geram dois processamentos.
     */
    public static function release(int $deliveryId): bool
    {
        return self::deliveries()->where('id', $deliveryId)->where('status', self::STATUS_FAILED)->update([
            'status' => self::STATUS_RECEIVED,
            'next_attempt_at' => self::now(),
        ]) === 1;
    }

    /** A entrega `received` está parada (ninguém a processou no prazo)? */
    public static function isStale(object $delivery): bool
    {
        $since = max(
            (int) strtotime((string) $delivery->received_at),
            (int) strtotime((string) ($delivery->next_attempt_at ?? '')),
        );

        return $since <= time() - WebhookConfig::staleSeconds();
    }

    /** A reivindicação de uma entrega `processing` venceu (o processo morreu no meio)? */
    public static function leaseExpired(object $delivery): bool
    {
        $until = (int) strtotime((string) ($delivery->next_attempt_at ?? ''));

        return $until > 0 && $until < time();
    }

    /** Dá por interrompida uma entrega `processing` com a reivindicação vencida. */
    public static function failInterrupted(int $deliveryId): bool
    {
        $row = self::delivery($deliveryId);
        if ($row === null || $row->status !== self::STATUS_PROCESSING || ! self::leaseExpired($row)) {
            return false;
        }

        $retry = (int) $row->attempts < WebhookConfig::maxAttempts();

        return self::deliveries()->where('id', $deliveryId)->where('status', self::STATUS_PROCESSING)
            ->where('next_attempt_at', $row->next_attempt_at)->update([
                'status' => self::STATUS_FAILED,
                'error' => 'Processamento interrompido antes de terminar.',
                'next_attempt_at' => $retry ? self::now() : null,
            ]) === 1;
    }

    /** @return int[] entregas `received` paradas, da mais antiga para a mais nova */
    public static function staleIds(int $limit, int $exceptId = 0): array
    {
        $cutoff = self::at(-WebhookConfig::staleSeconds());

        return self::deliveries()->where('status', self::STATUS_RECEIVED)
            ->where('received_at', '<=', $cutoff)
            ->where(static fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $cutoff))
            ->where('id', '!=', $exceptId)
            ->orderBy('id')->limit($limit)->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /** @return int[] entregas `failed` com nova tentativa vencida */
    public static function dueRetryIds(int $limit, int $exceptId = 0): array
    {
        return self::deliveries()->where('status', self::STATUS_FAILED)
            ->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', self::now())
            ->where('attempts', '<', WebhookConfig::maxAttempts())
            ->where('id', '!=', $exceptId)
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /** @return int[] entregas `processing` com a reivindicação vencida */
    public static function expiredLeaseIds(int $limit): array
    {
        return self::deliveries()->where('status', self::STATUS_PROCESSING)
            ->whereNotNull('next_attempt_at')->where('next_attempt_at', '<', self::now())
            ->orderBy('id')->limit($limit)->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * Apaga o que passou do prazo de guarda de cada webhook (`retention_days`
     * da definição; 30 dias para o que não tem mais definição).
     *
     * @param  array<string, int>  $retentionBySlug
     */
    public static function prune(array $retentionBySlug, int $defaultDays = 30): int
    {
        $deleted = 0;
        $finished = [self::STATUS_PROCESSED, self::STATUS_FAILED, self::STATUS_REJECTED];

        foreach ($retentionBySlug as $slug => $days) {
            $deleted += self::deliveries()->where('webhook', $slug)->whereIn('status', $finished)
                ->where('received_at', '<', self::at(-max(1, (int) $days) * 86400))->delete();
        }

        $orphans = self::deliveries()->whereIn('status', $finished)
            ->where('received_at', '<', self::at(-max(1, $defaultDays) * 86400));
        if ($retentionBySlug !== []) {
            $orphans->whereNotIn('webhook', array_map('strval', array_keys($retentionBySlug)));
        }

        return $deleted + $orphans->delete();
    }

    // ── auxiliares ──────────────────────────────────────────────────────────

    /**
     * Cabeçalhos como serão guardados: nomes em minúsculas, um valor por nome,
     * credenciais mascaradas pelo `SecretMasker` (Authorization, Cookie, token,
     * assinatura…) e, além dele, os cabeçalhos que a definição usa como
     * credencial, tenham o nome que tiverem.
     *
     * @param  array<string, mixed>  $headers
     * @param  string[]  $alsoMask
     */
    public static function encodeHeaders(array $headers, array $alsoMask = []): string
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[strtolower((string) $name)] = self::clip(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value, 2000);
        }

        $flat = SecretMasker::maskHeaders($flat);
        foreach ($alsoMask as $name) {
            $name = strtolower((string) $name);
            if (isset($flat[$name])) {
                $flat[$name] = SecretMasker::MASK;
            }
        }
        ksort($flat);

        return (string) json_encode($flat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
