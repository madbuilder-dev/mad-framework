<?php

namespace Mad\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mad\Core\AppConfig;
use Mad\Security\SecretMasker;

/**
 * Log de requisições da API REST do app (fórum #313).
 *
 * O tipo `rest` de `MAD_REQUEST_LOG_TYPES` aparecia na configuração e na tela,
 * mas ninguém gravava: o `App\Http\Middleware\LogRequest` só está no grupo
 * `web` e só confere o tipo `web`. As rotas do `routes/api.php` (grupo `api`,
 * token Bearer pelo `mad.api`) não passavam por ele.
 *
 * O MadServiceProvider põe este middleware no grupo `api`: vale para o app que
 * só atualizou o framework, sem mexer no `bootstrap/app.php`. Rota do grupo
 * `web` sob `/api` (chat, importação) continua com o log do tipo `web`.
 *
 * Gate igual ao do web: `general.request_log == '1'` (MAD_REQUEST_LOG) e `rest`
 * em `general.request_log_types` (lista vazia = todos os tipos).
 *
 * Grava em `mad_log_request` na conexão `log` — conexão do sistema, que o
 * modo multiempresa nunca aponta para o banco de uma empresa. Como no web:
 * corpo, query, URI e cabeçalhos passam pelo SecretMasker (Authorization,
 * senha, access/refresh token ficam `••••`). Na coluna Programa da tela sai
 * `REST <Controller@metodo>`; o login é o do token da requisição.
 */
class LogApiRequest
{
    /** Teto do corpo gravado: o `text` do MySQL guarda 64 KB. */
    private const MAX_BODY_BYTES = 60_000;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            if (! self::enabled()) {
                return;
            }

            DB::connection('log')->table('mad_log_request')->insert(self::row($request));
        } catch (\Throwable $e) {
            // Logging nunca pode derrubar a requisição.
            error_log('[request-log:rest] ' . $e->getMessage());
        }
    }

    /** MAD_REQUEST_LOG ligado e o tipo `rest` em MAD_REQUEST_LOG_TYPES (vazio = todos). */
    public static function enabled(): bool
    {
        $ini = AppConfig::get();
        if ((string) ($ini['general']['request_log'] ?? '0') !== '1') {
            return false;
        }
        $types = array_filter(array_map('trim', explode(',', (string) ($ini['general']['request_log_types'] ?? ''))));

        return $types === [] || in_array('rest', $types, true);
    }

    /** @return array<string, mixed> a linha de `mad_log_request` desta requisição */
    public static function row(Request $request): array
    {
        $now = date('Y-m-d H:i:s');
        $body = json_encode(SecretMasker::maskArray($request->input()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            $body = '{}';
        } elseif (strlen($body) > self::MAX_BODY_BYTES) {
            $body = (string) json_encode(['_truncated' => true, '_bytes' => strlen($body)]);
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', array_map('strval', (array) $values));
        }

        $user = $request->attributes->get('mad_api_user');
        $login = is_object($user) && isset($user->login) ? (string) $user->login : null;

        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        return [
            'endpoint'         => SecretMasker::maskUri('/' . ltrim($request->path(), '/')),
            'logdate'          => $now,
            'log_year'         => date('Y'),
            'log_month'        => date('m'),
            'log_day'          => date('d'),
            // Requisição REST não tem sessão de navegador: o id seria aleatório.
            'session_id'       => null,
            'login'            => $login,
            'access_ip'        => $request->ip(),
            'class_name'       => self::programName($request),
            'http_host'        => $request->getHost(),
            'server_port'      => (string) $request->getPort(),
            'request_uri'      => SecretMasker::maskUri($request->getRequestUri()),
            'request_method'   => $request->method(),
            'query_string'     => SecretMasker::maskQueryString((string) $request->getQueryString()),
            'request_headers'  => json_encode(SecretMasker::maskHeaders($headers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'request_body'     => $body,
            'request_duration' => max(0, (int) round((microtime(true) - $start) * 1000)),
        ];
    }

    /** `REST OrderApiController@index` (ou só `REST`, numa rota closure). */
    public static function programName(Request $request): string
    {
        $action = $request->route()?->getActionName();
        if (! is_string($action) || $action === '' || $action === 'Closure') {
            return 'REST';
        }
        $short = preg_replace('/^.*\\\\/', '', $action) ?? $action;

        return mb_substr('REST ' . $short, 0, 200);
    }
}
