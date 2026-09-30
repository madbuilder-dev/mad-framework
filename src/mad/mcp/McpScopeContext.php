<?php

namespace Mad\Mcp;

/**
 * McpScopeContext
 *
 * Identidade que viaja com TODA chamada de tool MCP. Fonte da verdade = o token
 * Bearer resolvido p/ um SystemUser pelo McpManifestAuthMiddleware — NUNCA os
 * args do LLM. E uma leitura fina sobre McpCurrentUser (id/login/groupIds) +
 * as chaves de sessao que ApplicationAuthenticationService::loadSessionVars()
 * ja populou.
 *
 * Eixo do MCP row-scope: o sujeito e o USUARIO (e, opcional, as unidades) —
 * intra-install, opt-in (MAD_MCP_ROW_SCOPE_ENABLED). A camada de TENANCY do app
 * (Multi-unidade `unit_id` / tenant em pool `tenant_id`, a mesma dos traits
 * BelongsToUnit/BelongsToTenant) e outra, e vale SEMPRE: o gateway do agente e
 * PDO cru, nao passa pelo escopo global do Eloquent, entao ela e reaplicada
 * aqui ({@see tenancyFor()}) em qualquer modo. Ver docs/mcp-scope-contract.md
 * e docs/multi-tenancy.md.
 */
final class McpScopeContext
{
    /**
     * Filtros e carimbo da camada de tenancy do APP para uma tabela do agente:
     *   - Multi-unidade ligado + tabela com `unit_id`   => unit_id = unidade ativa
     *   - tenant em pool ligado + tabela com `tenant_id` => tenant_id = tenant ativo
     * Unidade/tenant vem do contexto do TOKEN (o middleware restaura a sessao da
     * emissao) — nunca dos args do LLM. Sem unidade/tenant corrente => id
     * impossivel (-1): fail-closed, o agente nao le a tabela inteira.
     *
     * @return array{0: list<array{0:string,1:string,2:mixed}>, 1: array<string,int>}
     */
    public static function tenancyFor(?string $connection, string $table): array
    {
        $filters = [];
        $stamp   = [];

        $dims = [];
        if (\Mad\Database\DataScope::unitScopeActive()) {
            $dims['unit_id'] = \Mad\Database\UnitContext::id();
        }
        if (\Mad\Database\DataScope::tenantScopeActive()) {
            $dims['tenant_id'] = \Mad\Database\TenantContext::id();
        }

        foreach ($dims as $col => $id) {
            if (! self::tableHasColumn($connection, $table, $col)) {
                continue;
            }
            $ok        = $id !== null && $id > 0;
            $filters[] = [$col, 'in', [$ok ? $id : -1]];
            if ($ok) {
                $stamp[$col] = $id;
            }
        }

        return [$filters, $stamp];
    }

    /** Colunas reais da tabela (1 introspecção por tabela/request). Falha => assume que tem (filtra). */
    private static function tableHasColumn(?string $connection, string $table, string $column): bool
    {
        $cols = \Mad\Database\DataScope::memo('mcp.columns', ($connection ?? '') . '|' . $table, static function () use ($connection, $table): ?array {
            try {
                return array_map('strtolower', \Illuminate\Support\Facades\Schema::connection($connection)->getColumnListing($table));
            } catch (\Throwable) {
                return null;
            }
        });

        return $cols === null || in_array(strtolower($column), $cols, true);
    }

    public static function userId(): ?int
    {
        return McpCurrentUser::id(); // setado pelo middleware a partir do token
    }

    public static function login(): string
    {
        return McpCurrentUser::login();
    }

    /** @return array<int,string> ids dos grupos (ja no holder) */
    public static function groupIds(): array
    {
        return McpCurrentUser::groupIds();
    }

    /** Unidade ATIVA (single) p/ carimbar no insert — session('userunitid'). */
    public static function unitId(): ?int
    {
        $v = self::session('userunitid');

        return ($v !== null && (int) $v > 0) ? (int) $v : null;
    }

    /** Tenant ATIVO (modo pool) — o da emissao do token, restaurado na sessao pelo middleware. */
    public static function tenantId(): ?int
    {
        $v = \Mad\Database\TenantContext::id();

        return ($v !== null && $v > 0) ? $v : null;
    }

    /** Unidades intra-install p/ FILTRAR no nivel 'unit' — session('userunitids'). */
    public static function unitIds(): array
    {
        $ids = (array) (self::session('userunitids') ?: []);
        if ($ids === [] && ($one = self::session('userunitid'))) {
            $ids = [$one];
        }

        return array_values(array_map('intval', $ids));
    }

    /** True so quando ha uma requisicao MCP autenticada de fato. */
    public static function isResolved(): bool
    {
        return McpCurrentUser::isAuthenticated() && (int) McpCurrentUser::id() > 0;
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
