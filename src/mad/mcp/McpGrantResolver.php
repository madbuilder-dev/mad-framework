<?php

namespace Mad\Mcp;

use Mad\Security\PermissionGate;

/**
 * McpGrantResolver
 *
 * Acesso efetivo = (grant do manifest) INTERSECAO (RBAC do usuario).
 * Decisao a NIVEL DE TOOL delegada ao McpPermissionResolver.
 *
 * ROW-SCOPE dinamico (framework-generico). Cruza:
 *   - o que a TABELA suporta (manifest: owner_column? unit_column? exempt?), e
 *   - o NIVEL do usuario (own | unit | all), resolvido por programa/permissao.
 *
 * Niveis (default 'own', o mais restritivo):
 *   all  = pode acessar `mcp.row_scope_bypass_program`  -> sem filtro
 *   unit = pode acessar `mcp.row_scope_unit_program`    -> filtra por unidade
 *   own  = caso contrario                               -> filtra por dono
 *
 * Matriz nivel × colunas (fail-closed quando nao da p/ honrar):
 *   own : owner_column -> dono=eu ; so unit_column -> DENY (salvo allow_coarsen)
 *   unit: unit_column  -> unidade ; so owner_column -> dono=eu (mais estreito, ok)
 *   all : sem filtro
 *   nenhuma coluna e nao-exempt -> DENY (tabela exposta sem politica)
 */
final class McpGrantResolver
{
    public function __construct(
        private McpPermissionResolver $perm = new McpPermissionResolver()
    ) {
    }

    /** Gate da tool (contrato inalterado): null = permitido, senao motivo. */
    public function toolDenyReason(McpManifest $manifest, array $spec): ?string
    {
        return $this->perm->denyReason($manifest, $spec); // piso INT matriz INT admin
    }

    /** Plano de row-scope p/ a entidade e o usuario atual. */
    public function rowScopePlan(McpManifest $manifest, string $entity): McpScopePlan
    {
        $owner = $manifest->ownerColumnFor($entity);
        $unit  = $manifest->unitColumnFor($entity);

        // Carimbo de proveniencia: SEMPRE seta as colunas que a tabela tem, da
        // identidade do token — assim a linha fica filtravel em qualquer nivel.
        $stamp = [];
        $uid = (int) McpScopeContext::userId();
        if ($owner !== null && $uid > 0) {
            $stamp[$owner] = $uid;
        }
        $unitId = McpScopeContext::unitId();
        if ($unit !== null && $unitId !== null) {
            $stamp[$unit] = $unitId;
        }

        if (! $manifest->hasScopeBlock($entity)) {
            // FAIL-CLOSED: exposta sem bloco de scope e sem isencao.
            return McpScopePlan::deny("entidade '{$entity}' exposta sem politica de row-scope");
        }
        if ($manifest->isScopeExempt($entity)) {
            return McpScopePlan::unscoped($stamp); // lookup/referencia revisada
        }

        $level = $this->userLevel();

        if ($level === 'all') {
            McpScopeAudit::bypass($entity); // leitura sem filtro e auditada
            return McpScopePlan::unscoped($stamp);
        }

        if ($level === 'unit') {
            if ($unit !== null) {
                return McpScopePlan::allow([$unit, 'in', $this->ids(McpScopeContext::unitIds())], $stamp);
            }
            if ($owner !== null) {
                return McpScopePlan::allow([$owner, 'in', [$this->one($uid)]], $stamp); // mais estreito, seguro
            }
            return McpScopePlan::deny("nivel unit mas '{$entity}' nao tem coluna de escopo");
        }

        // own (default)
        if ($owner !== null) {
            return McpScopePlan::allow([$owner, 'in', [$this->one($uid)]], $stamp);
        }
        if ($unit !== null) {
            if ($manifest->allowsCoarsen($entity)) {
                return McpScopePlan::allow([$unit, 'in', $this->ids(McpScopeContext::unitIds())], $stamp);
            }
            return McpScopePlan::deny("nivel own mas '{$entity}' so tem coluna de unidade (use allow_coarsen)");
        }

        return McpScopePlan::deny("'{$entity}' exposta sem coluna de escopo");
    }

    /** Nivel de visao de dados do usuario atual (own < unit < all). */
    private function userLevel(): string
    {
        $mcp = (array) (\Mad\Core\AppConfig::get()['mcp'] ?? []);

        $all = trim((string) ($mcp['row_scope_bypass_program'] ?? ''));
        if ($all !== '' && PermissionGate::canAccess($all)) {
            return 'all';
        }
        $unit = trim((string) ($mcp['row_scope_unit_program'] ?? ''));
        if ($unit !== '' && PermissionGate::canAccess($unit)) {
            return 'unit';
        }

        return 'own';
    }

    /** Fail-closed: lista vazia vira id impossivel (-1), nunca a tabela toda. */
    private function ids(array $vals): array
    {
        $v = array_values(array_filter(array_map('intval', $vals), static fn ($x): bool => $x > 0));

        return $v !== [] ? $v : [-1];
    }

    private function one(int $v): int
    {
        return $v > 0 ? $v : -1;
    }
}
