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
 * extra, se houver, e o bloco `scope`), fora das que o app tirou do SQL em
 * `mad.ai.sql_excluded_tables`. Query salva sozinha NÃO abre a tabela
 * para SQL livre: ela entrega colunas e filtros fixos, e a SQL passaria por
 * cima dessa escolha.
 *
 * Colunas = as expostas (sem as de credencial do {@see ProtectedData}); PII
 * sai mascarada. Linhas = o plano do MCP row-scope (quando o app liga
 * MAD_MCP_ROW_SCOPE_ENABLED — a mesma chave das tools), a tenancy do app
 * (unit_id/tenant_id), o gancho de filtro do app (`mad.mcp.row_filter_hook`,
 * só na SQL) e o soft delete do manifest.
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
     * As de `mad.ai.sql_excluded_tables` ficam de fora: o app as tirou do SQL
     * livre e elas só se leem pelas tools (fw#648).
     *
     * @return array<string, string>
     */
    public function readableTables(): array
    {
        return array_filter($this->grantedTables(), static fn (string $t): bool => ! self::listed('mad.ai.sql_excluded_tables', $t));
    }

    /**
     * Tabelas concedidas que o app tirou do SQL livre (`mad.ai.sql_excluded_tables`):
     * o usuário as lê só pelas tools list_/read_ (fw#648).
     *
     * @return array<string, string>
     */
    public function toolOnlyTables(): array
    {
        return array_filter($this->grantedTables(), static fn (string $t): bool => self::listed('mad.ai.sql_excluded_tables', $t));
    }

    /**
     * A tabela está na lista da chave de config? Aceita lista ou texto separado
     * por vírgula (como no .env); '*' = todas; sem diferenciar maiúsculas.
     * Chave ausente ou vazia = nenhuma.
     */
    public static function listed(string $configKey, string $table): bool
    {
        $raw   = config($configKey, []);
        $table = strtolower(trim($table));
        foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $item) {
            $item = strtolower(trim((string) $item));
            if ($item === '*' || ($item !== '' && $item === $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tabelas com list_/read_ concedida (chave em minúsculas → nome no manifest).
     *
     * @return array<string, string>
     */
    private function grantedTables(): array
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
     * plano do MCP row-scope + tenancy do app + gancho de filtro do app (só
     * aqui, fw#647 — {@see appRowFilters()}) + soft delete.
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

        $app = self::appRowFilters($name);
        if ($app === null) {
            return ['ok' => false, 'reason' => 'gancho de filtro de linha do app falhou'];
        }
        foreach ($app as $f) {
            $filters[] = $f;
        }

        $soft = $this->manifest->softDeleteColumnFor($name);
        if ($soft !== null) {
            $filters[] = [$soft, 'is null', null];
        }

        return ['ok' => true, 'filters' => $filters];
    }

    /**
     * Filtros de linha do APP na SQL livre (fw#647): `mad.mcp.row_filter_hook`
     * (env MAD_MCP_ROW_FILTER_HOOK) = classe com
     * `filtros(string $tabela, int $userId): ?array`, estático ou de instância.
     * Recebe a tabela como está no manifest e o usuário do token; devolve null
     * (não cobre a tabela) ou [[coluna, 'in', [ids inteiros]], …] — lista de ids
     * vazia = nenhuma linha. Vale para o query_db, os widgets e o painel (tudo
     * que passa por {@see \Mad\Ai\WidgetSqlScope}); as tools do MCP não passam
     * por ele. Sem gancho: [].
     *
     * É filtro de segurança: gancho que lança ou devolve outro formato = null
     * (a tabela é recusada, com o motivo no log).
     *
     * @return list<array{0:string,1:string,2:list<int>}>|null
     */
    private static function appRowFilters(string $table): ?array
    {
        $class = trim((string) config('mad.mcp.row_filter_hook', ''));
        if ($class === '') {
            return [];
        }

        try {
            if (! class_exists($class) || ! method_exists($class, 'filtros')) {
                throw new \RuntimeException("{$class}::filtros() não existe");
            }
            $userId = (int) McpCurrentUser::id();
            $out    = (new \ReflectionMethod($class, 'filtros'))->isStatic()
                ? $class::filtros($table, $userId)
                : app($class)->filtros($table, $userId);
            if ($out === null) {
                return [];
            }

            $filters = [];
            foreach (is_array($out) ? $out : [$out] as $f) {
                [$col, $op, $ids] = is_array($f) && count($f) === 3 ? array_values($f) : [null, null, null];
                if (! is_string($col) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)
                    || ! is_string($op) || strtolower(trim($op)) !== 'in' || ! is_array($ids)) {
                    throw new \UnexpectedValueException("{$class}::filtros('{$table}') fora do formato [[coluna, 'in', ids]]");
                }
                $clean = [];
                foreach ($ids as $v) {
                    if (! is_int($v) && ! (is_string($v) && preg_match('/^-?\d+$/', $v))) {
                        throw new \UnexpectedValueException("{$class}::filtros('{$table}') com id que não é inteiro");
                    }
                    $clean[] = (int) $v;
                }
                $filters[] = [$col, 'in', $clean];
            }

            return $filters;
        } catch (\Throwable $e) {
            error_log('[McpAccess] row_filter_hook: ' . $e->getMessage());

            return null;
        }
    }
}
