<?php

namespace Mad\Web;

/**
 * Mad\Web\Session
 *
 * Wrapper fino sobre $_SESSION com suporte a flash messages, old input,
 * errors (MessageBag-style) e CSRF token — mesma semantica do Laravel
 * web.php, sem deps novas.
 *
 * Convive com a sessão do framework legado porque usa keys sob prefixo reservado
 * (_mad_web_*) enquanto o legado usa keys como 'logged', 'login', etc.
 */
class Session
{
    private const FLASH_NEW  = '_mad_web_flash_new';
    private const FLASH_OLD  = '_mad_web_flash_old';
    private const OLD_INPUT  = '_mad_web_old_input';
    private const ERRORS     = '_mad_web_errors';
    private const CSRF_TOKEN = '_mad_web_csrf_token';

    /**
     * Inicia a sessao PHP se ainda nao estiver ativa.
     *
     * Aplica flags seguras ao cookie ANTES do session_start (espelha o
     * sessão do framework legado): HttpOnly (bloqueia document.cookie via XSS),
     * Secure (so quando HTTPS — nao quebra localhost HTTP) e SameSite=Lax
     * (mitiga CSRF cross-site). Sem isso o PHPSESSID_* dependeria do php.ini.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            self::applySecureCookieParams();
            session_start();
        }
    }

    /**
     * Seta os flags seguros do cookie de sessao. Idempotente — preserva
     * lifetime/path/domain do php.ini, sobrescreve so as chaves de seguranca.
     */
    private static function applySecureCookieParams(): void
    {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        );

        $current = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $current['lifetime'] ?? 0,
            'path'     => $current['path']     ?? '/',
            'domain'   => $current['domain']   ?? '',
            'secure'   => $isHttps,    // HTTPS-only quando aplicavel
            'httponly' => true,        // sempre — bloqueia document.cookie via XSS
            'samesite' => 'Lax',       // mitiga CSRF cross-site
        ]);
    }

    // ─── Storage basico ──────────────────────────────────────────────────────

    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        // Ordem: flash antigo (disponivel nesta request) > session normal
        if (isset($_SESSION[self::FLASH_OLD][$key])) {
            return $_SESSION[self::FLASH_OLD][$key];
        }
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[self::FLASH_OLD][$key]) || array_key_exists($key, $_SESSION);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function all(): array
    {
        return $_SESSION;
    }

    // ─── Flash messages ──────────────────────────────────────────────────────

    /**
     * Grava um valor como flash — disponivel APENAS na proxima request.
     */
    public static function flash(string $key, $value): void
    {
        if (!isset($_SESSION[self::FLASH_NEW])) {
            $_SESSION[self::FLASH_NEW] = [];
        }
        $_SESSION[self::FLASH_NEW][$key] = $value;
    }

    /**
     * Retorna um valor do flash "antigo" (grava na request anterior).
     */
    public static function getFlash(string $key, $default = null)
    {
        return $_SESSION[self::FLASH_OLD][$key] ?? $default;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION[self::FLASH_OLD][$key]);
    }

    /**
     * Move flash_new → flash_old, limpando o flash_old anterior.
     * Deve ser chamado no INICIO de cada request (antes do controller rodar).
     */
    public static function rotateFlash(): void
    {
        $_SESSION[self::FLASH_OLD] = $_SESSION[self::FLASH_NEW] ?? [];
        $_SESSION[self::FLASH_NEW] = [];
    }

    // ─── Old input (pra re-exibir valores apos validacao falhar) ─────────────

    public static function putOldInput(array $input): void
    {
        $_SESSION[self::OLD_INPUT] = $input;
    }

    public static function getOldInput(string $key, $default = null)
    {
        return $_SESSION[self::OLD_INPUT][$key] ?? $default;
    }

    public static function getAllOldInput(): array
    {
        return $_SESSION[self::OLD_INPUT] ?? [];
    }

    public static function forgetOldInput(): void
    {
        unset($_SESSION[self::OLD_INPUT]);
    }

    // ─── Errors (MessageBag-like) ────────────────────────────────────────────

    public static function putErrors(array $errors): void
    {
        $_SESSION[self::ERRORS] = $errors;
    }

    public static function getErrors(): array
    {
        return $_SESSION[self::ERRORS] ?? [];
    }

    public static function forgetErrors(): void
    {
        unset($_SESSION[self::ERRORS]);
    }

    // ─── CSRF token ──────────────────────────────────────────────────────────

    /**
     * Retorna o token CSRF atual, gerando um novo se nao existir.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::CSRF_TOKEN])) {
            $_SESSION[self::CSRF_TOKEN] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_TOKEN];
    }

    /**
     * Forca a regeneracao do token (util apos login pra prevenir session fixation).
     */
    public static function regenerateToken(): string
    {
        $_SESSION[self::CSRF_TOKEN] = bin2hex(random_bytes(32));
        return $_SESSION[self::CSRF_TOKEN];
    }

    /**
     * Regenera o ID da sessao (pos-login, por seguranca).
     */
    public static function regenerate(bool $deleteOld = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOld);
        }
    }

    /**
     * Invalida completamente a sessao.
     */
    public static function invalidate(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
