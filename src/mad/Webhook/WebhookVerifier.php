<?php

namespace Mad\Webhook;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Confere a credencial de uma entrega: assinatura HMAC, token em cabeçalho e
 * lista de IPs. Sem estado e sem I/O — recebe o corpo exatamente como chegou
 * (os mesmos bytes que serão gravados) e os segredos já abertos.
 *
 * Toda comparação de assinatura e de token usa `hash_equals`.
 *
 * `check()` devolve `null` quando a entrega confere, ou o MOTIVO da recusa
 * (uma das constantes `REASON_*`). O motivo vai para o registro do endpoint,
 * nunca para a resposta HTTP.
 */
final class WebhookVerifier
{
    public const REASON_SIGNATURE_MISSING = 'signature_missing';
    public const REASON_SIGNATURE_INVALID = 'signature_invalid';
    public const REASON_TIMESTAMP = 'timestamp_out_of_tolerance';
    public const REASON_TOKEN_MISSING = 'token_missing';
    public const REASON_TOKEN_INVALID = 'token_invalid';
    public const REASON_NO_SECRET = 'secret_not_configured';

    /**
     * @param  \Closure(string): ?string  $header  leitura de cabeçalho por nome (sem diferenciar maiúsculas)
     * @param  string[]  $secrets  segredo atual e, durante uma troca, o anterior
     * @param  int|null  $now  relógio (testes)
     */
    public static function check(WebhookDefinition $definition, string $body, \Closure $header, array $secrets, ?int $now = null): ?string
    {
        $secrets = array_values(array_filter($secrets, static fn ($s) => is_string($s) && $s !== ''));

        return match ($definition->kind()) {
            WebhookDefinition::KIND_HMAC => self::checkHmac($definition->verification, $body, $header, $secrets, $now ?? time()),
            WebhookDefinition::KIND_TOKEN => $definition->tokenInUrl()
                ? null // a chave da URL já foi conferida ao localizar o endpoint
                : self::checkToken((string) $definition->verification['header'], $header, $secrets),
            default => null, // `ip`: quem decide é a lista de IPs
        };
    }

    /** O IP está na lista (IP exato ou CIDR, v4 e v6)? Lista vazia = sem filtro. */
    public static function ipAllowed(array $allowlist, string $ip): bool
    {
        if ($allowlist === []) {
            return true;
        }
        if ($ip === '') {
            return false;
        }

        try {
            return IpUtils::checkIp($ip, $allowlist);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cabeçalhos que um remetente legítimo mandaria para este corpo. É o que o
     * `mad:webhook test` usa para montar a requisição assinada.
     *
     * @return array<string, string>
     */
    public static function signedHeaders(WebhookDefinition $definition, string $body, string $secret, ?int $now = null): array
    {
        $v = $definition->verification;

        if ($definition->kind() === WebhookDefinition::KIND_TOKEN) {
            return $definition->tokenInUrl() ? [] : [(string) $v['header'] => $secret];
        }
        if ($definition->kind() !== WebhookDefinition::KIND_HMAC) {
            return [];
        }

        $now ??= time();

        if ($v['scheme'] === 't_v1') {
            return [$v['header'] => 't=' . $now . ',v1=' . self::mac($v, $now . '.' . $body, $secret)];
        }

        if ($v['timestamp_header'] !== null) {
            return [
                $v['timestamp_header'] => (string) $now,
                $v['header'] => $v['prefix'] . self::mac($v, $now . '.' . $body, $secret),
            ];
        }

        return [$v['header'] => $v['prefix'] . self::mac($v, $body, $secret)];
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  string[]  $secrets
     */
    private static function checkHmac(array $v, string $body, \Closure $header, array $secrets, int $now): ?string
    {
        if ($secrets === []) {
            return self::REASON_NO_SECRET;
        }

        $received = trim((string) $header($v['header']));
        if ($received === '') {
            return self::REASON_SIGNATURE_MISSING;
        }

        if ($v['scheme'] === 't_v1') {
            [$timestamp, $candidates] = self::parseTimestamped($received);
            if ($timestamp === null || $candidates === []) {
                return self::REASON_SIGNATURE_INVALID;
            }
            if (! self::withinTolerance($timestamp, (int) $v['tolerance'], $now)) {
                return self::REASON_TIMESTAMP;
            }

            return self::anyMatch($v, $timestamp . '.' . $body, $candidates, $secrets) ? null : self::REASON_SIGNATURE_INVALID;
        }

        $signed = $body;
        if ($v['timestamp_header'] !== null) {
            $timestamp = trim((string) $header($v['timestamp_header']));
            if ($timestamp === '' || ! ctype_digit($timestamp)) {
                return self::REASON_SIGNATURE_INVALID;
            }
            if (! self::withinTolerance($timestamp, (int) $v['tolerance'], $now)) {
                return self::REASON_TIMESTAMP;
            }
            $signed = $timestamp . '.' . $body;
        }

        $prefix = (string) $v['prefix'];
        if ($prefix !== '' && strncasecmp($received, $prefix, strlen($prefix)) === 0) {
            $received = substr($received, strlen($prefix));
        }

        return self::anyMatch($v, $signed, [$received], $secrets) ? null : self::REASON_SIGNATURE_INVALID;
    }

    /**
     * @param  \Closure(string): ?string  $header
     * @param  string[]  $secrets
     */
    private static function checkToken(string $headerName, \Closure $header, array $secrets): ?string
    {
        if ($secrets === []) {
            return self::REASON_NO_SECRET;
        }

        $received = (string) $header($headerName);
        // `Authorization: Bearer <token>` é a forma mais comum de token em cabeçalho.
        if (strncasecmp($received, 'Bearer ', 7) === 0) {
            $received = substr($received, 7);
        }
        $received = trim($received);
        if ($received === '') {
            return self::REASON_TOKEN_MISSING;
        }

        $ok = false;
        foreach ($secrets as $secret) {
            // sem `break`: o tempo não revela QUAL segredo conferiu
            $ok = hash_equals($secret, $received) || $ok;
        }

        return $ok ? null : self::REASON_TOKEN_INVALID;
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  string[]  $candidates  assinaturas recebidas
     * @param  string[]  $secrets
     */
    private static function anyMatch(array $v, string $signed, array $candidates, array $secrets): bool
    {
        $ok = false;
        foreach ($secrets as $secret) {
            $expected = self::mac($v, $signed, $secret);
            foreach ($candidates as $candidate) {
                // hexadecimal não diferencia maiúsculas; base64 diferencia
                $candidate = $v['encoding'] === 'hex' ? strtolower($candidate) : $candidate;
                $ok = hash_equals($expected, $candidate) || $ok;
            }
        }

        return $ok;
    }

    /** @param  array<string, mixed>  $v */
    private static function mac(array $v, string $signed, string $secret): string
    {
        $raw = hash_hmac((string) $v['algo'], $signed, $secret, true);

        return $v['encoding'] === 'base64' ? base64_encode($raw) : bin2hex($raw);
    }

    /**
     * `t=1700000000,v1=abc,v1=def,v0=…` → ['1700000000', ['abc', 'def']].
     *
     * @return array{0: ?string, 1: string[]}
     */
    private static function parseTimestamped(string $value): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $value) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$name, $content] = [trim($pair[0]), trim($pair[1])];
            if ($name === 't' && ctype_digit($content)) {
                $timestamp = $content;
            } elseif ($name === 'v1' && $content !== '') {
                $signatures[] = $content;
            }
        }

        return [$timestamp, $signatures];
    }

    /** `$tolerance` 0 = sem janela de tempo. */
    private static function withinTolerance(string $timestamp, int $tolerance, int $now): bool
    {
        return $tolerance <= 0 || abs($now - (int) $timestamp) <= $tolerance;
    }
}
