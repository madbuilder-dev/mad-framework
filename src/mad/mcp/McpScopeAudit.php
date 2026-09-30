<?php

namespace Mad\Mcp;

/**
 * McpScopeAudit
 *
 * Auditoria de LEITURA/QUERY do agente — o unico path de auditoria que faltava
 * (escritas ja gravam via McpChangeAudit). Reusa mad_log_change pelo writer
 * existente, no precedente free-text do McpCustomTool (operation tag + pk='-' +
 * payload JSON). Best-effort: NUNCA lanca.
 *
 * So grava quando row-scope esta ligado (flag), p/ nao mudar o path legado.
 */
final class McpScopeAudit
{
    /** @param array<string,mixed> $params args do caller (apenas p/ trilha) */
    public static function query(string $table, string $toolId, array $params): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            McpChangeAudit::record($table, '-', $toolId, 'query', [], ['scope' => json_encode([
                'actor'  => McpScopeContext::login(),
                'user'   => McpScopeContext::userId(),
                'params' => $params,
            ], JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            error_log('[MCP scope-audit] ' . $e->getMessage());
        }
    }

    /** Leitura sem escopo via bypass dedicado — registrada p/ forense. */
    public static function bypass(string $table): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            McpChangeAudit::record($table, '-', McpScopeContext::login() ?: '?', 'scope-bypass', [], []);
        } catch (\Throwable $e) {
            error_log('[MCP scope-audit] ' . $e->getMessage());
        }
    }

    /** Tentativa negada de escopo/acesso -> reusa o sink de negacao existente. */
    public static function denied(string $tool, string $reason): void
    {
        try {
            McpAccessAudit::denied($tool, $reason); // error_log + MadTrace + LogAccess mode=mcp
        } catch (\Throwable $e) {
            error_log('[MCP scope-audit] ' . $e->getMessage());
        }
    }

    private static function enabled(): bool
    {
        return (bool) (\Mad\Core\AppConfig::get()['mcp']['row_scope_enabled'] ?? false);
    }
}
