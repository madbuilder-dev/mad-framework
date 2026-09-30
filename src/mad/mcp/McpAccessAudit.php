<?php

namespace Mad\Mcp;

/**
 * McpAccessAudit
 *
 * Trilha de auditoria de TENTATIVAS NEGADAS por permissao no MCP. Tres sinks:
 *   1. error_log() do PHP (log local).
 *   2. MadTrace (APM/observabilidade) — captureMessage nivel warning (quando presente).
 *   3. LogAccess em modo 'mcp' (SystemAccessLogService::registerMcpDenied).
 *
 * Best-effort: nunca lanca.
 */
final class McpAccessAudit
{
    public static function denied(string $tool, string $reason): void
    {
        $login = McpCurrentUser::login();
        if ($login === '' && function_exists('session')) {
            try {
                $login = (string) (session('login') ?: '-');
            } catch (\Throwable) {
                $login = '-';
            }
        }
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $msg = "[MCP-DENIED] login={$login} tool={$tool} reason={$reason} ip={$ip}";

        // 1. error_log do PHP
        error_log($msg);

        // 2. MadTrace (APM) — ainda nao portado; guard mantem o sink pronto.
        if (class_exists('MadTrace') && method_exists('MadTrace', 'captureMessage')) {
            \MadTrace::captureMessage($msg, 'warning', [
                'event'  => 'mcp_permission_denied',
                'login'  => $login,
                'tool'   => $tool,
                'reason' => $reason,
                'ip'     => $ip,
            ]);
        }

        // 3. LogAccess modo 'mcp'
        if (class_exists('SystemAccessLogService')) {
            try {
                \SystemAccessLogService::registerMcpDenied($login, $tool, $reason);
            } catch (\Throwable $e) {
                error_log('[MCP-DENIED] access-log fail: ' . $e->getMessage());
            }
        }
    }
}
