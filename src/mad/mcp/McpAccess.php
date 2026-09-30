<?php

namespace Mad\Mcp;

use Mad\Database\DataScope;
use Mad\Security\ProtectedData;

/**
 * McpAccess — o que o usuário atual pode LER pela IA, segundo o manifest MCP.
 *
 * É a mesma regra das tools ({@see McpPermissionResolver}) aplicada ao que NÃO
 * passa por tool: a SQL que o modelo escreve (widgets, `query_db`, re-execução
 * dos widgets salvos — {@see \Mad\Ai\WidgetSqlScope}) e o `db_schema`.
 *
 * Tabela legível = exposta no manifest (colunas com expose) E com uma tool
 * crud `list`/`read` dela concedida a um dos perfis do usuário (e o `program`
 * extra, se houver, e o bloco `scope`). Query salva sozinha NÃO abre a tabela
 * para SQL livre: ela entrega colunas e filtros fixos, e a SQL passaria por
 * cima dessa escolha.
 *
 * Colunas = as expostas (sem as de credencial do {@see ProtectedData}); PII
 * sai mascarada. Linhas = o plano do MCP row-scope (quando o app liga
 * MAD_MCP_ROW_SCOPE_ENABLED — a mesma chave das tools), a tenancy do app
 * (unit_id/tenant_id) e o soft delete do manifest.
 *
 * Sem manifest, sem concessão, sem `scope`: nada (fail-closed).
 */
final class McpAccess
{
    private function __construct(private ?McpManifest $manifest)
    {
    }

    /** Acesso do usuário atual ao manifest publicado (null = sem manifest). */
    public static function current(): self
    {
        try {
            return new self(McpManifestLoader::load());
        } catch (\Throwable) {
            return new self(null);
        }
    }

    public static function for(?McpManifest $manifest): self
    {
        return new self($manifest);
    }

    public function manifest(): ?McpManifest
    {
        return $this->manifest;
    }

    /**
     * Tabelas legíveis (chave em minúsculas → nome como está no manifest).
     *
     * @return array<string, string>
     */
    public function readableTables(): array
    {
        $manifest = $this->manifest;
        if ($manifest === null || ! McpCurrentUser::isAuthenticated()) {
            return [];
        }

        return DataScope::memo('mcp.access.readable', spl_object_hash($manifest) . '|' . (int) McpCurrentUser::id(), static function () use ($manifest): array {
            $perm = new McpPermissionResolver();
            $out  = [];
            foreach ($manifest->tools() as $spec) {
                $kind   = strtolower((string) ($spec['kind'] ?? 'crud'));
                $verb   = strtolower((string) ($spec['verb'] ?? ''));
                $entity = trim((string) ($spec['entity'] ?? ''));
                if ($kind !== 'crud' || ! in_array($verb, ['list', 'read'], true) || $entity === '') {
                    continue;
                }
                if (isset($out[strtolower($entity)]) || ProtectedData::isProtectedTable($entity)) {
                    continue;
                }
                if ($manifest->exposedFieldsFor($entity) === [] || ! $perm->canUseTool($manifest, $spec)) {
                    continue;
                }
                $out[strtolower($entity)] = $entity;
            }
            ksort($out);

            return $out;
        });
    }

    public function canRead(string $table): bool
    {
        return isset($this->readableTables()[strtolower(trim($table))]);
    }

    /**
     * Colunas expostas da tabela (spec do manifest), sem as de credencial.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(string $table): array
    {
        $name = $this->readableTables()[strtolower(trim($table))] ?? null;
        if ($name === null || $this->manifest === null) {
            return [];
        }

        return array_values(array_filter(
            $this->manifest->exposedFieldsFor($name),
            static fn (array $f): bool => trim((string) ($f['name'] ?? '')) !== ''
                && ! ProtectedData::isSecretColumn((string) $f['name']),
        ));
    }

    public function softDeleteColumn(string $table): ?string
    {
        $name = $this->readableTables()[strtolower(trim($table))] ?? null;

        return $name !== null ? $this->manifest?->softDeleteColumnFor($name) : null;
    }

    /**
     * Filtros de LINHA que a leitura da tabela tem que carregar, na mesma
     * ordem de camadas do gateway das tools ({@see McpScopedGateway}):
     * plano do MCP row-scope + tenancy do app + soft delete.
     *
     * @return array{ok: bool, filters?: list<array{0:string,1:string,2:mixed}>, reason?: string}
     */
    public function rowFilters(?string $connection, string $table): array
    {
        $name = $this->readableTables()[strtolower(trim($table))] ?? null;
        if ($name === null || $this->manifest === null) {
            return ['ok' => false, 'reason' => 'sem concessão'];
        }

        $filters = [];
        if ((bool) (\Mad\Core\AppConfig::get()['mcp']['row_scope_enabled'] ?? false)) {
            $plan = (new McpGrantResolver())->rowScopePlan($this->manifest, $name);
            if ($plan->denied) {
                return ['ok' => false, 'reason' => (string) ($plan->denyReason ?? 'fora de escopo')];
            }
            if ($plan->readFilter !== null) {
                $filters[] = $plan->readFilter;
            }
        }

        [$tenancy] = McpScopeContext::tenancyFor($connection, $name);
        foreach ($tenancy as $f) {
            $filters[] = $f;
        }

        $soft = $this->manifest->softDeleteColumnFor($name);
        if ($soft !== null) {
            $filters[] = [$soft, 'is null', null];
        }

        return ['ok' => true, 'filters' => $filters];
    }
}
