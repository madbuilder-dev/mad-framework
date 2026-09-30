<?php

namespace Mad\Web;

/**
 * Mad\Web\Csrf
 *
 * Helpers finos para CSRF token. Delega storage para Mad\Web\Session.
 */
class Csrf
{
    /**
     * Retorna o token CSRF atual (gera se nao existir).
     */
    public static function token(): string
    {
        return Session::token();
    }

    /**
     * Valida um token contra o salvo na sessao.
     * Usa hash_equals para evitar timing attacks.
     */
    public static function verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        return hash_equals(Session::token(), $token);
    }

    /**
     * Regenera o token (chame apos login).
     */
    public static function regenerate(): string
    {
        return Session::regenerateToken();
    }
}
