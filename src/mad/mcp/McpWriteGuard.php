<?php

namespace Mad\Mcp;

use Mad\Security\PermissionGate;

/**
 * McpWriteGuard
 *
 * Read-only por DEFAULT. Qualquer mutacao pode exigir (a) permissao admin e
 * (b) confirmacao humana explicita (confirm=true vindo da UI, nao do LLM).
 * Reusa o precedente del=confirm do McpCrudTool e a fila de aprovacao do embed.
 *
 * Ambos os gates sao config-gated e default OFF (write_requires_admin /
 * write_requires_approval), p/ nao quebrar tools de escrita ja existentes; um
 * install endurece ligando as flags.
 */
final class McpWriteGuard
{
    /** @param array<string,mixed> $spec */
    public static function assertAllowed(array $spec, bool $confirmed): void
    {
        $ini = \Mad\Core\AppConfig::get();

        if ((bool) ($ini['mcp']['write_requires_admin'] ?? false)) {
            $admin = (string) (($ini['mcp']['admin_program'] ?? '') ?: 'ProgramForm');
            if (! PermissionGate::canAccess($admin)) {
                McpScopeAudit::denied((string) ($spec['id'] ?? '?'), 'write-not-admin');
                throw new McpScopeException('Escrita exige permissao administrativa.');
            }
        }

        if ((bool) ($ini['mcp']['write_requires_approval'] ?? false) && ! $confirmed) {
            McpScopeAudit::denied((string) ($spec['id'] ?? '?'), 'write-unconfirmed');
            throw new McpScopeException('Operacao de escrita pendente de aprovacao humana (confirm=true).');
        }
    }
}
