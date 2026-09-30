<?php

namespace Mad\Web;

/**
 * Mad\Web\AuthHelper
 *
 * Objeto chainable retornado por auth() helper. Representa o usuario publico
 * autenticado (portal de cliente). Convencao: armazena em session nas keys
 * 'user_public_id', 'user_public_name' e, opcionalmente, 'user_public_data'.
 *
 * Uso em controllers:
 *   if (auth()->check()) { ... }
 *   $id = auth()->id();
 *   $name = auth()->name();
 *
 * Uso em views Blade:
 *   @auth ... @endauth
 *   @guest ... @endguest
 *   {{ auth()->name() }}
 */
class AuthHelper
{
    /**
     * Retorna true se ha um usuario publico logado.
     */
    public function check(): bool
    {
        return Session::has('user_public_id');
    }

    /**
     * Inverso de check() — sem auth.
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * ID do usuario logado (ou null).
     */
    public function id()
    {
        return Session::get('user_public_id');
    }

    /**
     * Nome do usuario logado (ou null).
     */
    public function name(): ?string
    {
        $name = Session::get('user_public_name');
        return $name !== null ? (string) $name : null;
    }

    /**
     * Retorna todos os dados do usuario armazenados na session
     * (se foram salvos via login via login()).
     */
    public function user(): ?array
    {
        $data = Session::get('user_public_data');
        return is_array($data) ? $data : null;
    }

    /**
     * Faz login do usuario (grava na session).
     *
     * @param int|string $id
     * @param string $name
     * @param array $extraData
     */
    public function login($id, string $name, array $extraData = []): void
    {
        Session::put('user_public_id', $id);
        Session::put('user_public_name', $name);
        if (!empty($extraData)) {
            Session::put('user_public_data', $extraData);
        }
        // Regenera o ID da sessao pra prevenir session fixation
        Session::regenerate(true);
    }

    /**
     * Faz logout (limpa keys de auth mas mantem o resto da session).
     */
    public function logout(): void
    {
        Session::forget('user_public_id');
        Session::forget('user_public_name');
        Session::forget('user_public_data');
    }
}
