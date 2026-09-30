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
}
