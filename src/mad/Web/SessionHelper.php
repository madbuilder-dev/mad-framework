<?php

namespace Mad\Web;

/**
 * Mad\Web\SessionHelper
 *
 * Objeto chainable retornado por session() quando chamado sem argumentos.
 * Permite API fluente tipo Laravel: session()->put('k', 'v'), session()->flash('k', 'v').
 *
 * Exemplo de uso:
 *   session()->put('user_id', 42);
 *   session()->flash('success', 'Salvo!');
 *   $msg = session('success');  // retorna o valor direto
 */
class SessionHelper
{
    public function put(string $key, $value): self
    {
        Session::put($key, $value);
        return $this;
    }

    public function get(string $key, $default = null)
    {
        return Session::get($key, $default);
    }

    public function has(string $key): bool
    {
        return Session::has($key);
    }

    public function forget(string $key): self
    {
        Session::forget($key);
        return $this;
    }

    public function flash(string $key, $value): self
    {
        Session::flash($key, $value);
        return $this;
    }

    public function getFlash(string $key, $default = null)
    {
        return Session::getFlash($key, $default);
    }

    public function hasFlash(string $key): bool
    {
        return Session::hasFlash($key);
    }

    public function token(): string
    {
        return Session::token();
    }

    public function regenerate(): self
    {
        Session::regenerate();
        return $this;
    }

    public function invalidate(): self
    {
        Session::invalidate();
        return $this;
    }

    public function all(): array
    {
        return Session::all();
    }
}
