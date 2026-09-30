<?php

namespace Mad\Security;

/**
 * SecretMasker — tira o VALOR de senhas, tokens, chaves e cookies do que vai
 * para log e diagnóstico, mantendo a chave (o registro continua mostrando que o
 * campo veio, só não o que veio).
 *
 * Usado por:
 *  - log de requisições (App\Service\Log\RequestLogService → mad_log_request):
 *    corpo, query string, URI e cabeçalhos. O log é LIGADO por padrão e gravava
 *    o POST do login com a senha em texto, e o Cookie da sessão (lab 1041,
 *    27/09/2026: 37 registros com senha em claro e 704 com o Cookie);
 *  - telemetria (MadTrace::collectRequest): URL, query e corpo dos eventos;
 *  - página de erro em modo debug (MadErrorRenderer::toMarkdown).
 *
 * Nomes conferidos nos formulários REAIS do framework: login (`password`), 2FA
 * (`two_factor_code`, `google_code`, `email_code`), troca/redefinição de senha
 * (`new_password`, `new_password2`, `password1`, `password2`, `repassword`,
 * `password_confirmation`, `token`), link público de documento
 * (`link_password`), configurações (`smtp_pass`, `google_recaptcha_secret_key`,
 * `ai_openrouter_api_key`), cobrança (BillingSettings::SECRET_KEYS:
 * `billing_mp_access_token`, `billing_*_secret`, `billing_inter_cert`,
 * `billing_inter_key`), CSRF (`_token`, X-CSRF-TOKEN/X-XSRF-TOKEN), sessão
 * (cookie `*_session`), cartão (o token do provedor chega como argumento
 * posicional do MadWire em `onPayWithCard`/`onSaveCard`).
 *
 * Fica de fora de propósito: `code` (código de plano/perfil/módulo nos
 * cadastros), `codigo`, `login`, `email` e `cpf` — dado de negócio que o log
 * de auditoria precisa mostrar.
 */
final class SecretMasker
{
    public const MASK = '••••';

    /** Pedaços que, em qualquer lugar do nome, marcam segredo. */
    private const SUBSTRINGS = [
        'password', 'passwd', 'passphrase', 'senha', 'secret', 'token', 'api_key', 'apikey',
        'access_key', 'private_key', 'privatekey', 'credential', 'authorization', 'cookie',
        'csrf', 'xsrf', 'captcha', 'turnstile', 'two_factor', 'twofactor', 'session', 'cartao', 'signature',
    ];

    /** Partes do nome (separadas por _ - . espaço) que marcam segredo sozinhas. */
    private const PARTS = ['pass', 'pwd', 'pin', 'cvv', 'cvv2', 'cvc', 'otp', 'totp', '2fa', 'cert'];

    /** Começos de nome de dado de cartão. */
    private const PREFIXES = ['card_'];

    /** Nomes exatos que as regras acima não pegam. */
    private const EXACT = [
        'google_code', 'email_code', 'sms_code', 'auth_code', 'otp_code', 'verification_code',
        'recovery_code', 'recovery_codes', 'backup_code', 'backup_codes',
        'billing_inter_key', 'db_config', 'numero_cartao',
    ];

    /** Ação do MadWire cujos argumentos POSICIONAIS carregam segredo (token do cartão, senha). */
    private const SECRET_ACTION_RE = '/pass|senha|secret|token|card|cartao|cvv|pin|two_?factor|otp|2fa/i';

    /** Profundidade máxima de estrutura aninhada percorrida. */
    private const MAX_DEPTH = 12;

    /** Maior string com cara de JSON que é aberta para mascarar por dentro. */
    private const MAX_JSON_BYTES = 1_048_576;

    public static function isSecretKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        $k = strtolower(trim($key));
        $k = (string) preg_replace('/\[\]$/', '', $k);
        if ($k === '') {
            return false;
        }
        $norm = str_replace('-', '_', $k);

        if (in_array($norm, self::EXACT, true)) {
            return true;
        }
        foreach (self::SUBSTRINGS as $s) {
            if (str_contains($norm, $s)) {
                return true;
            }
        }
        foreach (self::PREFIXES as $p) {
            if (str_starts_with($norm, $p)) {
                return true;
            }
        }
        foreach (preg_split('/[^a-z0-9]+/', $norm) ?: [] as $part) {
            if (in_array($part, self::PARTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mascara recursivamente: chave de segredo → MASK; estrutura
     * `{name|field|prop|key: <segredo>, value: …}` → value mascarado; string com
     * JSON dentro (o `mad_params` do MadWire) é aberta, mascarada e fechada; ação
     * do MadWire com segredo posicional (`onPayWithCard`) → argumentos mascarados.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function maskArray(array $data, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $data;
        }

        $secretAction = false;
        foreach (['mad_action', 'method'] as $actionKey) {
            if (isset($data[$actionKey]) && is_string($data[$actionKey]) && preg_match(self::SECRET_ACTION_RE, $data[$actionKey])) {
                $secretAction = true;
            }
        }

        $pairName = null;
        foreach (['name', 'field', 'prop', 'key'] as $nameKey) {
            if (isset($data[$nameKey]) && is_string($data[$nameKey]) && array_key_exists('value', $data)) {
                $pairName = $data[$nameKey];
                break;
            }
        }

        foreach ($data as $k => $v) {
            if (self::isSecretKey($k) || ($k === 'value' && $pairName !== null && self::isSecretKey($pairName))) {
                $data[$k] = self::MASK;
                continue;
            }
            if ($secretAction && in_array($k, ['mad_params', 'params', 'args'], true)) {
                $data[$k] = self::maskAllScalars($v, $depth + 1);
                continue;
            }
            if (is_array($v)) {
                $data[$k] = self::maskArray($v, $depth + 1);
            } elseif (is_object($v)) {
                $data[$k] = self::maskArray((array) json_decode((string) json_encode($v), true), $depth + 1);
            } elseif (is_string($v)) {
                $data[$k] = self::maskJsonString($v, $depth + 1);
            }
        }

        return $data;
    }

    /**
     * Cabeçalhos HTTP: Cookie, Authorization, Proxy-Authorization, tokens CSRF e
     * qualquer cabeçalho com token/segredo/assinatura/sessão/auth no nome ficam
     * registrados só como presentes (MASK). Os demais mantêm o valor.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            $n = strtolower(str_replace('_', '-', (string) $name));
            if (in_array($n, ['cookie', 'set-cookie', 'authorization', 'proxy-authorization', 'x-csrf-token', 'x-xsrf-token'], true)
                || str_contains($n, 'auth') || self::isSecretKey($n)) {
                $headers[$name] = is_array($value) ? array_fill(0, count($value), self::MASK) : self::MASK;
            }
        }

        return $headers;
    }

    /**
     * Query string: `token=abc&page=2` → `token=••••&page=2`. Mantém o texto
     * original do que não é segredo (sem reordenar nem recodificar).
     */
    public static function maskQueryString(string $query): string
    {
        if ($query === '' || ! str_contains($query, '=')) {
            return $query;
        }

        $parts = explode('&', $query);
        foreach ($parts as $i => $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $key = urldecode(substr($pair, 0, $eq));
            // `mad_model[password]` → olha o último nome entre colchetes também
            $leaf = preg_match('/\[([^\[\]]*)\]$/', $key, $m) ? $m[1] : $key;
            if (self::isSecretKey($key) || self::isSecretKey($leaf)) {
                $parts[$i] = substr($pair, 0, $eq) . '=' . self::MASK;
            }
        }

        return implode('&', $parts);
    }

    /** URI/URL com query: mascara só a parte depois do `?`. */
    public static function maskUri(string $uri): string
    {
        $q = strpos($uri, '?');
        if ($q === false) {
            return $uri;
        }
        $frag = '';
        $rest = substr($uri, $q + 1);
        if (($h = strpos($rest, '#')) !== false) {
            $frag = substr($rest, $h);
            $rest = substr($rest, 0, $h);
        }

        return substr($uri, 0, $q + 1) . self::maskQueryString($rest) . $frag;
    }

    /** String com JSON (objeto/lista) dentro: mascara por dentro; senão, devolve igual. */
    private static function maskJsonString(string $value, int $depth): string
    {
        $t = ltrim($value);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[') || strlen($value) > self::MAX_JSON_BYTES) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $value;
        }
        $masked = self::maskArray($decoded, $depth);
        if ($masked === $decoded) {
            return $value;
        }

        return (string) json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Todo valor escalar (inclusive dentro de JSON em string) vira MASK. */
    private static function maskAllScalars(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::MASK;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return (string) json_encode(self::maskAllScalars($decoded, $depth + 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            return self::MASK;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::maskAllScalars($v, $depth + 1);
            }

            return $value;
        }

        return $value === null ? null : self::MASK;
    }
}
