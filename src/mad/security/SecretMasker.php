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
 * (`link_password`), instalador (`adm_pass`, `adm_pass2`), configurações
 * (`smtp_pass`, `google_recaptcha_secret_key`, `ai_openrouter_api_key`), cobrança (BillingSettings::SECRET_KEYS:
 * `billing_mp_access_token`, `billing_*_secret`, `billing_inter_cert`,
 * `billing_inter_key`), CSRF (`_token`, X-CSRF-TOKEN/X-XSRF-TOKEN), sessão
 * (cookie `*_session`), cartão (o token do provedor chega como argumento
 * posicional do MadWire em `onPayWithCard`/`onSaveCard`).
 *
 * Fica de fora de propósito: `code` (código de plano/perfil/módulo nos
 * cadastros), `codigo`, `login`, `email` e `cpf` — dado de negócio que o log
 * de auditoria precisa mostrar.
 *
 * Corpo de requisição ({@see maskBody()}, fórum #313, 14/11/2026): o log de
 * requisições mostra o corpo inteiro na tela de detalhe, então ali também saem
 * CPF, CNPJ e cartão — pelo nome do campo (`cpf`, `cliente_cnpj`, `numero_cartao`,
 * `card_number`…) e pelo valor quando é um número de cartão válido (Luhn, 13 a 19
 * dígitos), em qualquer nível do JSON e no form-data.
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
    private const PARTS = ['pass', 'pwd', 'pin', 'cvv', 'cvv2', 'cvc', 'otp', 'totp', '2fa', 'cert', 'chave'];

    /** Começos de nome de dado de cartão. */
    private const PREFIXES = ['card_'];

    /** Nomes exatos que as regras acima não pegam. */
    private const EXACT = [
        'google_code', 'email_code', 'sms_code', 'auth_code', 'otp_code', 'verification_code',
        'recovery_code', 'recovery_codes', 'backup_code', 'backup_codes',
        'billing_inter_key', 'db_config', 'numero_cartao',
        // chave da tela pública (`?chave=`, `?key=`) e assinaturas na URL (fw#428)
        'key', 'sig', 'hash',
    ];

    /** Ação do MadWire cujos argumentos POSICIONAIS carregam segredo (token do cartão, senha). */
    private const SECRET_ACTION_RE = '/pass|senha|secret|token|card|cartao|cvv|pin|two_?factor|otp|2fa/i';

    /** Corpo de requisição: dado pessoal/financeiro que também sai ({@see maskBody()}). */
    private const PERSONAL_SUBSTRINGS = ['cpf', 'cnpj', 'cartao', 'cartão', 'cardnumber', 'card_number', 'creditcard', 'credit_card'];

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
            // `adm_pass2`, `pwd1`: a confirmação da senha leva um número no fim
            // e é tão segredo quanto a senha (a parte sozinha não casava).
            if (in_array($part, self::PARTS, true) || in_array(rtrim($part, '0123456789'), self::PARTS, true)) {
                return true;
            }
        }

        return false;
    }

    /** CPF, CNPJ ou cartão no nome do campo (só no modo corpo de requisição). */
    public static function isPersonalKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        $norm = str_replace('-', '_', mb_strtolower(trim($key)));
        foreach (self::PERSONAL_SUBSTRINGS as $s) {
            if (str_contains($norm, $s)) {
                return true;
            }
        }

        return false;
    }

    /** Número de cartão (13 a 19 dígitos, espaço/hífen/ponto aceitos) que passa no Luhn. */
    public static function looksLikeCardNumber(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }
        $raw = trim((string) $value);
        if (! preg_match('/^\d(?:[ .-]?\d){12,18}$/', $raw)) {
            return false;
        }
        $digits = (string) preg_replace('/\D/', '', $raw);
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    /**
     * Corpo de requisição para o log e a tela de detalhe: o {@see maskArray()} de
     * sempre mais CPF, CNPJ e cartão (nome do campo ou número de cartão válido).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function maskBody(array $data): array
    {
        return self::maskArray($data, 0, true);
    }

    /**
     * Mascara recursivamente: chave de segredo → MASK; estrutura
     * `{name|field|prop|key: <segredo>, value: …}` → value mascarado; string com
     * JSON dentro (o `mad_params` do MadWire) é aberta, mascarada e fechada; ação
     * do MadWire com segredo posicional (`onPayWithCard`) → argumentos mascarados.
     *
     * `$personal`: modo corpo de requisição ({@see maskBody()}).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function maskArray(array $data, int $depth = 0, bool $personal = false): array
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
            $pairSecret = $k === 'value' && $pairName !== null
                && (self::isSecretKey($pairName) || ($personal && self::isPersonalKey($pairName)));
            if (self::isSecretKey($k) || ($personal && self::isPersonalKey($k)) || $pairSecret) {
                $data[$k] = self::MASK;
                continue;
            }
            if ($secretAction && in_array($k, ['mad_params', 'params', 'args'], true)) {
                $data[$k] = self::maskAllScalars($v, $depth + 1);
                continue;
            }
            if ($personal && self::looksLikeCardNumber($v)) {
                $data[$k] = self::MASK;
                continue;
            }
            if (is_array($v)) {
                $data[$k] = self::maskArray($v, $depth + 1, $personal);
            } elseif (is_object($v)) {
                $data[$k] = self::maskArray((array) json_decode((string) json_encode($v), true), $depth + 1, $personal);
            } elseif (is_string($v)) {
                $data[$k] = self::maskJsonString($v, $depth + 1, $personal);
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
            } elseif (in_array($n, ['referer', 'referrer', 'origin', 'x-original-url', 'x-rewrite-url'], true)) {
                // O Referer é a URL da página anterior: o token dela (query ou
                // caminho de página site) vai junto (mad-framework#349).
                $headers[$name] = is_array($value)
                    ? array_map(static fn ($v) => is_string($v) ? self::maskUri($v) : $v, $value)
                    : (is_string($value) ? self::maskUri($value) : $value);
            }
        }

        return $headers;
    }

    /**
     * Query string: `token=abc&page=2` → `token=••••&page=2`. Mantém o texto
     * original do que não é segredo (sem reordenar nem recodificar).
     * `$personal`: modo do log de requisições — CPF, CNPJ e cartão também saem.
     */
    public static function maskQueryString(string $query, bool $personal = false): string
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
            $hit = self::isSecretKey($key) || self::isSecretKey($leaf);
            if (! $hit && $personal) {
                $hit = self::isPersonalKey($key) || self::isPersonalKey($leaf)
                    || self::looksLikeCardNumber(urldecode(substr($pair, $eq + 1)));
            }
            if ($hit) {
                $parts[$i] = substr($pair, 0, $eq) . '=' . self::MASK;
            }
        }

        return implode('&', $parts);
    }

    /**
     * URI/URL: mascara a query (a parte depois do `?`), a chave do endereço de
     * um webhook recebido (`/api/webhooks/<slug>/<chave>`), que identifica o
     * endpoint da unidade e, na verificação por token na URL, É a credencial,
     * e os parâmetros do caminho de uma página `site` (`link/{slug}`), que
     * carregam o token do link de convite/avaliação (mad-framework#349).
     * `$personal`: modo do log de requisições (CPF, CNPJ e cartão na query).
     */
    public static function maskUri(string $uri, bool $personal = false): string
    {
        $uri = self::maskSitePath($uri);

        if (str_contains($uri, '/api/webhooks/')) {
            $uri = (string) preg_replace(
                '#(/api/webhooks/[a-z0-9][a-z0-9-]{0,79}/)[A-Za-z0-9]{24,64}(?=$|[/?\#])#',
                '$1' . self::MASK,
                $uri,
            );
        }

        // Link da pessoa externa do workflow (fw#456): o token É a credencial.
        if (str_contains($uri, 'wf/decide/')) {
            $uri = (string) preg_replace('#(wf/decide/)[A-Za-z0-9]{8,128}(?=$|[/?\#])#', '$1' . self::MASK, $uri);
        }

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

        return substr($uri, 0, $q + 1) . self::maskQueryString($rest, $personal) . $frag;
    }

    /**
     * Caminho de página `site` com parâmetro (`link/{slug}` → `link/••••`).
     * Aceita caminho sem barra (`$request->path()`), URI (`/link/x?a=1`) e URL
     * absoluta (o `Referer`). O valor do parâmetro é tratado como credencial:
     * a página pública não tem como dizer se ele é slug de conteúdo ou token.
     */
    public static function maskSitePath(string $uri): string
    {
        // Memória no fim (relatório de um estouro, fw#438): os padrões das
        // páginas do site percorrem as rotas — caro demais para esse momento.
        if (\Mad\Support\MadOutOfMemory::tight()) {
            return $uri;
        }
        if ($uri === '' || ! preg_match('#^((?:[a-z][a-z0-9+.\-]*:)?//[^/?\#]*)?([^?\#]*)(.*)$#is', $uri, $m)) {
            return $uri;
        }
        [, $origin, $path, $rest] = $m + [1 => '', 2 => '', 3 => ''];
        $trim = trim($path, '/');
        if ($trim === '') {
            return $uri;
        }
        $segs = explode('/', $trim);

        foreach (self::sitePathPatterns() as $pattern) {
            $pat = explode('/', $pattern);
            if (count($pat) !== count($segs)) {
                continue;
            }
            $out = [];
            foreach ($pat as $i => $p) {
                if (preg_match('/^\{[^}]+\}$/', $p)) {
                    $out[] = self::MASK;
                } elseif ($p === $segs[$i]) {
                    $out[] = $p;
                } else {
                    continue 2;
                }
            }
            $lead  = str_starts_with($path, '/') ? '/' : '';
            $trail = (strlen($path) > 1 && str_ends_with($path, '/')) ? '/' : '';

            return $origin . $lead . implode('/', $out) . $trail . $rest;
        }

        return $uri;
    }

    /**
     * Caminhos de página `site` com parâmetro: os registrados nesta execução
     * (SiteRegistry) e, com as rotas em cache (sem o routes/web.php rodar), os
     * das rotas `site.*` do roteador.
     *
     * @return list<string>
     */
    /** @var array{0: ?string, 1: list<string>} [chave da coleção de rotas, caminhos site.*] */
    private static array $siteRouteMemo = [null, []];

    private static function sitePathPatterns(): array
    {
        $out = [];
        if (class_exists(\Mad\Site\SiteRegistry::class)) {
            foreach (\Mad\Site\SiteRegistry::all() as $page) {
                $out[] = trim((string) $page['path'], '/');
            }
        }
        try {
            if (function_exists('app') && app()->bound('router')) {
                // Memo por coleção de rotas: o log e a telemetria chamam isto
                // algumas vezes por requisição, e a coleção não muda nela.
                $routes = app('router')->getRoutes();
                $key    = spl_object_id($routes) . ':' . $routes->count();
                if (self::$siteRouteMemo[0] !== $key) {
                    $found = [];
                    foreach ($routes->getRoutes() as $route) {
                        if (str_starts_with((string) $route->getName(), 'site.')) {
                            $found[] = trim($route->uri(), '/');
                        }
                    }
                    self::$siteRouteMemo = [$key, $found];
                }
                array_push($out, ...self::$siteRouteMemo[1]);
            }
        } catch (\Throwable) {
            // sem roteador (script avulso): fica com o SiteRegistry
        }

        return array_values(array_unique(array_filter($out, static fn (string $p): bool => str_contains($p, '{'))));
    }

    /** String com JSON (objeto/lista) dentro: mascara por dentro; senão, devolve igual. */
    private static function maskJsonString(string $value, int $depth, bool $personal = false): string
    {
        $t = ltrim($value);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[') || strlen($value) > self::MAX_JSON_BYTES) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $value;
        }
        $masked = self::maskArray($decoded, $depth, $personal);
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
