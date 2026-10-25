<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mad\Support\VibePreview;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /__mad/preview-login?t=<token>&to=<path>` — o preview do Modo Vibe
 * abre o app do Teste Online já logado (item 3 do contrato).
 *
 * Antes o Studio entrava preenchendo o formulário de login (doLogin), e tela
 * nova dava 403 até relogar: `session('programs')` é calculada no login. Com
 * este SSO o relogin é um GET silencioso.
 *
 * Só existe com MAD_PREVIEW_LOGIN_SECRET no `.env` (a plataforma escreve um
 * segredo por projeto, só no Teste Online); sem ele a rota nem é registrada
 * (404).
 *
 * Token: `b64url(payload) . "." . b64url(hmac_sha256(b64url(payload), segredo))`,
 * payload `{"u": login, "exp": unix, "n": nonce}`. Recusa (403) assinatura
 * inválida, `exp` vencido ou a mais de 120 s, nonce repetido (5 min) e usuário
 * inexistente ou inativo. O login passa pelo mesmo serviço do login normal do
 * app (AuthenticationService::loadSessionVars), então `userid`, `programs` e o
 * resto da sessão saem como sempre. A rota fica fora do log de requisições:
 * o token não pode ir parar no banco.
 */
class PreviewLoginController
{
    /** `exp` aceito no máximo este tanto à frente do relógio do app. */
    public const MAX_TTL = 120;

    /** Janela do nonce de uso único (maior que MAX_TTL: cobre o token inteiro). */
    public const NONCE_TTL = 300;

    public const NONCE_MIN = 16;

    public function __invoke(Request $request): Response
    {
        $secret = VibePreview::previewLoginSecret();
        if ($secret === '') {
            abort(Response::HTTP_NOT_FOUND);
        }

        $payload = self::verify((string) $request->query('t', ''), $secret, time());
        if ($payload === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        // Depois da assinatura e do prazo: só quem tem o segredo gasta nonce.
        if (!Cache::add('mad:preview-login:nonce:' . hash('sha256', $payload['n']), 1, self::NONCE_TTL)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $user = self::user($payload['u']);
        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        try {
            self::login($user);
        } catch (\Throwable $e) {
            error_log('[preview-login] login recusado: ' . $e->getMessage());
            abort(Response::HTTP_FORBIDDEN);
        }

        // Location relativo: atrás do proxy do Teste Online, uma URL absoluta
        // montada com o host/esquema internos mandaria o iframe para fora.
        $response = new RedirectResponse(self::target($request->query('to')), 302);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    /**
     * Payload do token se a assinatura confere e o prazo vale; null se não.
     *
     * @return array{u: string, exp: int, n: string}|null
     */
    public static function verify(string $token, string $secret, int $now): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$body, $sig] = $parts;

        $given = self::b64urlDecode($sig);
        if ($given === null || !hash_equals(hash_hmac('sha256', $body, $secret, true), $given)) {
            return null;
        }

        $json = self::b64urlDecode($body);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data)
            || !is_string($data['u'] ?? null) || trim($data['u']) === ''
            || !is_int($data['exp'] ?? null)
            || !is_string($data['n'] ?? null) || strlen($data['n']) < self::NONCE_MIN) {
            return null;
        }
        if ($data['exp'] < $now || $data['exp'] > $now + self::MAX_TTL) {
            return null;
        }

        return ['u' => $data['u'], 'exp' => $data['exp'], 'n' => $data['n']];
    }

    /** Monta o token (o lado da plataforma faz o mesmo; aqui serve aos testes). */
    public static function sign(array $payload, string $secret): string
    {
        $body = self::b64url((string) json_encode($payload));
        return $body . '.' . self::b64url(hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * Destino do redirect: só caminho do próprio app. `//host` e `/\host` o
     * navegador lê como outro site; esquema, controle e vazio caem na raiz.
     */
    public static function target(mixed $to): string
    {
        if (!is_string($to) || $to === '' || $to[0] !== '/'
            || str_starts_with($to, '//') || str_contains($to, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $to)) {
            return '/';
        }
        // `/app` é diretório: o servidor web responde 301 para `/app/`, e um
        // 301 velho para http:// no cache do navegador barrava o iframe https
        // (fw#527). Vai direto para `/app/`.
        if (preg_match('#^/app(?=$|[?\#])#', $to)) {
            $to = '/app/' . substr($to, 4);
        }
        return $to;
    }

    private static function user(string $login): ?object
    {
        $model = \App\Models\Iam\User::class;
        if (!class_exists($model)) {
            return null;
        }
        $user = method_exists($model, 'newFromLogin')
            ? $model::newFromLogin($login)
            : $model::where('login', $login)->first();

        // Mesma régua do User::validate do login: inativo é 'N'.
        if ($user === null || (string) ($user->active ?? '') === 'N') {
            return null;
        }
        return $user;
    }

    /**
     * O trecho do login normal (LoginForm::doLogin) que vem depois da senha e
     * dos modais: a senha e o segundo fator aqui são o segredo do container,
     * que só a plataforma tem.
     */
    private static function login(object $user): void
    {
        $auth = \App\Service\Iam\AuthenticationService::class;
        if (!class_exists($auth)) {
            throw new \RuntimeException('app sem AuthenticationService');
        }

        $schedule = \App\Service\Iam\AccessScheduleService::class;
        if (class_exists($schedule) && !$schedule::userAllowed($user)) {
            throw new \RuntimeException('fora do horário de acesso');
        }
        $prefs = \App\Service\Sys\PreferenceService::class;
        if (class_exists($prefs)) {
            $prefs::verifyMaintenanceEnabled($user);
        }
        if (method_exists($auth, 'assertTenantDatabaseReady')) {
            $auth::assertTenantDatabaseReady(
                (string) ($user->unit?->tenant_id ?? ''),
                (string) ($user->unit?->id ?? ''),
            );
        }

        // Já logado (relogin depois de tela nova, ou outro usuário): a sessão
        // anterior acaba inteira, com os tokens do Copilot que ela segurava.
        $tokens = \App\Service\Mcp\McpTokenService::class;
        if (class_exists($tokens)) {
            $tokens::revokeSessionTokens();
        }
        session()->invalidate();

        $auth::loadSessionVars($user, true);
        try {
            $auth::setUnit($user->unit?->id);
        } catch (\Throwable) {
            // unidade principal inacessível: segue sem unidade ativa (como a personificação)
        }

        foreach ([\App\Service\Log\AccessLogService::class, \App\Service\Log\AccessNotificationLogService::class] as $log) {
            if (class_exists($log)) {
                try {
                    $log::registerLogin();
                } catch (\Throwable $e) {
                    error_log('[preview-login] log de acesso: ' . $e->getMessage());
                }
            }
        }

        if (config('mad.ai.enabled') && class_exists($tokens)) {
            try {
                $tokens::startSessionEmbed((int) $user->id, (string) $user->login);
            } catch (\Throwable $e) {
                error_log('[preview-login] token do Copilot: ' . $e->getMessage());
            }
        }
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
            return null;
        }
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }
}
