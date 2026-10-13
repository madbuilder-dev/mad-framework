<?php

namespace Mad\Webhook;

/**
 * Uma entrada de `app/config/webhooks.json`, já validada e normalizada.
 *
 * O arquivo é gerado pela plataforma, mas chega ao app por vários canais
 * (publicação, sincronização do Teste Online, cópia manual). Por isso a leitura
 * é defensiva: valor fora da faixa cai no padrão, e uma entrada que não dá para
 * atender com segurança (slug inválido, classe vazia, tipo de verificação
 * desconhecido) NÃO vira definição: o endereço dela responde 404.
 *
 * Chaves desconhecidas são ignoradas (`handshake` fica reservada).
 */
final class WebhookDefinition
{
    public const SLUG_PATTERN = '[a-z0-9][a-z0-9-]{0,79}';
    public const KEY_PATTERN = '[A-Za-z0-9]{24,64}';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_UNIT = 'unit';

    public const KIND_HMAC = 'hmac';
    public const KIND_TOKEN = 'token';
    public const KIND_IP = 'ip';

    public const MODE_AUTO = 'auto';
    public const MODE_QUEUE = 'queue';
    public const MODE_SYNC = 'sync';

    /**
     * @param  array<string, mixed>  $verification
     * @param  string[]  $ipAllowlist
     * @param  array{from: string, key: ?string}  $eventId
     * @param  array{from: string, key: string}|null  $eventType
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly string $scope,
        public readonly array $verification,
        public readonly array $ipAllowlist,
        public readonly array $eventId,
        public readonly ?array $eventType,
        public readonly string $handlerClass,
        public readonly string $handlerMethod,
        public readonly string $handlerKind,
        public readonly string $mode,
        public readonly int $rateLimit,
        public readonly int $maxBodyKb,
        public readonly int $retentionDays,
    ) {
    }

    /** @param  mixed  $raw  uma entrada de `webhooks[]` */
    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $slug = is_string($raw['slug'] ?? null) ? $raw['slug'] : '';
        if (preg_match('/^' . self::SLUG_PATTERN . '$/', $slug) !== 1) {
            return null;
        }

        $handler = is_array($raw['handler'] ?? null) ? $raw['handler'] : [];
        $class = ltrim(trim((string) ($handler['class'] ?? '')), '\\');
        if ($class === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) !== 1) {
            return null;
        }
        $kind = ($handler['kind'] ?? 'service') === 'job' ? 'job' : 'service';
        $method = trim((string) ($handler['method'] ?? 'handle'));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) !== 1) {
            $method = 'handle';
        }

        $verification = self::normalizeVerification($raw['verification'] ?? null);
        if ($verification === null) {
            return null;
        }

        $allowlist = [];
        foreach ((array) ($raw['ip_allowlist'] ?? []) as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $allowlist[] = trim($entry);
            }
        }
        // `ip` sem lista deixaria o endereço aberto a qualquer um.
        if ($verification['kind'] === self::KIND_IP && $allowlist === []) {
            return null;
        }

        $scope = ($raw['scope'] ?? self::SCOPE_GLOBAL) === self::SCOPE_UNIT ? self::SCOPE_UNIT : self::SCOPE_GLOBAL;
        $mode = in_array($raw['mode'] ?? null, [self::MODE_QUEUE, self::MODE_SYNC], true) ? $raw['mode'] : self::MODE_AUTO;

        return new self(
            slug: $slug,
            name: trim((string) ($raw['name'] ?? '')) ?: $slug,
            enabled: ($raw['enabled'] ?? true) !== false,
            scope: $scope,
            verification: $verification,
            ipAllowlist: $allowlist,
            eventId: self::normalizeEventId($raw['event_id'] ?? null),
            eventType: self::normalizeEventType($raw['event_type'] ?? null),
            handlerClass: $class,
            handlerMethod: $method,
            handlerKind: $kind,
            mode: $mode,
            rateLimit: self::clamp($raw['rate_limit'] ?? null, 1, 6000, 120),
            maxBodyKb: self::clamp($raw['max_body_kb'] ?? null, 1, 5120, 512),
            retentionDays: self::clamp($raw['retention_days'] ?? null, 1, 365, 30),
        );
    }

    public function kind(): string
    {
        return (string) $this->verification['kind'];
    }

    public function isUnitScoped(): bool
    {
        return $this->scope === self::SCOPE_UNIT;
    }

    /** A credencial é a chave que vem na URL (`token` com `in: url`)? */
    public function tokenInUrl(): bool
    {
        return $this->kind() === self::KIND_TOKEN && ($this->verification['in'] ?? '') === 'url';
    }

    /** O endereço deste webhook leva o segmento `{key}`? */
    public function usesUrlKey(): bool
    {
        return $this->isUnitScoped() || $this->tokenInUrl();
    }

    /** Precisa de um segredo guardado no endpoint (assinatura ou token em cabeçalho)? */
    public function needsSecret(): bool
    {
        return $this->kind() === self::KIND_HMAC
            || ($this->kind() === self::KIND_TOKEN && ! $this->tokenInUrl());
    }

    /** Cabeçalho que carrega a credencial (assinatura ou token), se houver. */
    public function credentialHeader(): ?string
    {
        $header = $this->verification['header'] ?? null;

        return is_string($header) && $header !== '' ? $header : null;
    }

    public function maxBodyBytes(): int
    {
        return $this->maxBodyKb * 1024;
    }

    /** Resumo sem dado sensível (o arquivo nunca tem segredo): saída do `mad:webhook list`. */
    public function toSummary(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'scope' => $this->scope,
            'verification' => $this->kind(),
            'mode' => $this->mode,
            'handler' => $this->handlerClass . '@' . $this->handlerMethod,
            'handler_kind' => $this->handlerKind,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function normalizeVerification(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        switch ($raw['kind'] ?? null) {
            case self::KIND_HMAC:
                $header = trim((string) ($raw['header'] ?? ''));
                if (! self::validHeaderName($header)) {
                    return null;
                }
                $algo = strtolower((string) ($raw['algo'] ?? 'sha256'));
                if (! in_array($algo, ['sha1', 'sha256', 'sha512'], true)) {
                    return null;
                }
                $timestampHeader = trim((string) ($raw['timestamp_header'] ?? ''));
                if ($timestampHeader !== '' && ! self::validHeaderName($timestampHeader)) {
                    return null;
                }

                return [
                    'kind' => self::KIND_HMAC,
                    'scheme' => ($raw['scheme'] ?? 'plain') === 't_v1' ? 't_v1' : 'plain',
                    'header' => $header,
                    'algo' => $algo,
                    'encoding' => ($raw['encoding'] ?? 'hex') === 'base64' ? 'base64' : 'hex',
                    'prefix' => (string) ($raw['prefix'] ?? ''),
                    'timestamp_header' => $timestampHeader !== '' ? $timestampHeader : null,
                    'tolerance' => max(0, (int) ($raw['tolerance'] ?? 300)),
                ];

            case self::KIND_TOKEN:
                if (($raw['in'] ?? 'header') === 'url') {
                    return ['kind' => self::KIND_TOKEN, 'in' => 'url'];
                }
                $header = trim((string) ($raw['header'] ?? ''));
                if (! self::validHeaderName($header)) {
                    return null;
                }

                return ['kind' => self::KIND_TOKEN, 'in' => 'header', 'header' => $header];

            case self::KIND_IP:
                return ['kind' => self::KIND_IP];
        }

        return null;
    }

    /** @return array{from: string, key: ?string} */
    private static function normalizeEventId(mixed $raw): array
    {
        $from = is_array($raw) ? ($raw['from'] ?? 'none') : 'none';
        $key = is_array($raw) ? trim((string) ($raw['key'] ?? '')) : '';

        if (in_array($from, ['header', 'json'], true) && $key !== '') {
            return ['from' => $from, 'key' => $key];
        }
        if ($from === 'body_hash') {
            return ['from' => 'body_hash', 'key' => null];
        }

        return ['from' => 'none', 'key' => null];
    }

    /** @return array{from: string, key: string}|null */
    private static function normalizeEventType(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $from = $raw['from'] ?? null;
        $key = trim((string) ($raw['key'] ?? ''));

        return in_array($from, ['header', 'json'], true) && $key !== '' ? ['from' => $from, 'key' => $key] : null;
    }

    private static function validHeaderName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $name) === 1;
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
