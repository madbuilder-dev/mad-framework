<?php

namespace Mad\Mcp;

/**
 * McpCurrentUser
 *
 * Holder do usuario autenticado na requisicao MCP atual. Cada request HTTP do
 * MCP roda em um processo PHP proprio (MadServer.php, surface mcp), entao um estado
 * estatico nao vaza entre requisicoes.
 *
 * Preenchido pelo McpManifestAuthMiddleware apos resolver token -> SystemUser.
 * Fora dele (render das telas) fica vazio: quem precisa da regra do MCP para o
 * usuario LOGADO na tela usa {@see asSessionUser()}.
 */
final class McpCurrentUser
{
    private static ?int $userId = null;
    private static string $login = '';
    /** @var array<int,string> */
    private static array $groupIds = [];

    /** @param array<int,int|string> $groupIds */
    public static function set(int $userId, string $login, array $groupIds): void
    {
        self::$userId   = $userId;
        self::$login    = $login;
        self::$groupIds = array_values(array_map('strval', $groupIds));
    }

    public static function reset(): void
    {
        self::$userId   = null;
        self::$login    = '';
        self::$groupIds = [];
    }

    public static function isAuthenticated(): bool
    {
        return self::$userId !== null;
    }

    public static function id(): ?int
    {
        return self::$userId;
    }

    public static function login(): string
    {
        return self::$login;
    }

    /** @return array<int,string> */
    public static function groupIds(): array
    {
        return self::$groupIds;
    }

    /**
     * Roda $fn como o usuario da SESSAO web (userid, login, usergroupids) e
     * devolve o estado anterior no fim.
     *
     * O layout decide o botao do Copilot pela matriz do MCP (framework#431):
     * sem isto o resolvedor via o holder vazio do render da tela, negava tudo
     * (`unauthenticated`) e, com o MCP publicado, o botao sumia para todos.
     * Ignora de proposito um usuario ja preenchido: num worker persistente ele
     * pode ser o Bearer de uma requisicao anterior. Sem sessao, roda sem
     * usuario — fail-closed.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function asSessionUser(callable $fn): mixed
    {
        $previous = [self::$userId, self::$login, self::$groupIds];

        $userId = (int) (self::session('userid') ?? 0);
        if ($userId > 0) {
            self::set($userId, (string) (self::session('login') ?? ''), (array) (self::session('usergroupids') ?: []));
        } else {
            self::reset();
        }

        try {
            return $fn();
        } finally {
            [self::$userId, self::$login, self::$groupIds] = $previous;
        }
    }

    private static function session(string $key): mixed
    {
        try {
            return (function_exists('session') && app()->bound('session')) ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
