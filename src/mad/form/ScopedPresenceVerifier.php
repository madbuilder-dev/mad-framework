<?php

namespace Mad\Form;

use Illuminate\Validation\DatabasePresenceVerifier;
use Mad\Database\AdminScope;
use Mad\Database\DataScope;
use Mad\Database\ScopedModels;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;

/**
 * ScopedPresenceVerifier — as regras `unique` e `exists` contam as MESMAS linhas
 * que o model enxerga: com Multi-unidade (ou tenant em pool) ligado, a conta
 * fica na unidade (e no tenant) corrente, como o global scope do
 * BelongsToUnit / BelongsToTenant.
 *
 * Antes a conta ia direto na tabela. Num SaaS Multi-unidade (lab "OS Fácil",
 * projeto 1032) a TecCell não conseguia cadastrar o status `ABERTA` porque a
 * Refrigeração Polar já tinha um — e o "já está sendo utilizado" contava que o
 * código existe em outro cliente; `exists` aceitava o id de um cliente de
 * outra unidade. O gerador de migrations passa a criar o índice único como
 * (unit_id, coluna) nessas tabelas; esta classe é o lado da validação.
 *
 * Regras (uma tabela é escopada quando um model com BelongsToUnit/
 * BelongsToTenant mora nela — {@see ScopedModels}):
 *  - escopo desligado, ou sem unidade/tenant corrente → conta como sempre (a
 *    leitura do model também não filtra);
 *  - a regra já fixa `unit_id`/`tenant_id` (`->where('unit_id', ...)`) → respeita
 *    a regra;
 *  - o BANCO ainda tem índice único GLOBAL só na coluna (app com o framework
 *    novo e a migration ainda por rodar, ou índice desenhado assim de
 *    propósito) → conta global: a validação espelha a constraint física — se o
 *    banco recusaria o INSERT, a tela avisa no campo em vez de estourar o erro
 *    do banco.
 *
 * Modo "todas as unidades" (mad.general.unit_scope = 'all'): a conta vai
 * sobre TODAS as unidades do usuário, como a leitura. `exists` aceita a
 * referência que o combo mostrou (cliente de outra filial do usuário);
 * `unique` fica um superconjunto conservador do índice (unit_id, coluna) —
 * pode recusar um código que existe noutra filial do usuário, nunca deixa
 * passar um que o banco recusaria.
 *
 * Visão do administrador do dono (mad.tenant.admin_scope = 'all',
 * {@see AdminScope}) — a leitura dele não filtra empresa nem unidade, mas o
 * índice físico continua (tenant_id|unit_id, coluna). A conta segue o
 * REGISTRO, não a visão:
 *  - `unique` na edição (há id a ignorar) → na empresa/unidade DO REGISTRO
 *    editado: editar o pedido da empresa B confere a numeração da B;
 *  - `unique` no cadastro novo de regra sobre model (`Rule::unique(Model::class)`,
 *    a que o MadBuilder gera) → na empresa/unidade ATIVA, onde o carimbo vai pôr
 *    o registro;
 *  - `exists` (e `unique` em string sem id) → sem filtro: o mesmo universo que o
 *    combo do administrador mostra. No unique é um superconjunto conservador do
 *    índice — pode recusar um código que só existe noutra empresa, nunca deixa
 *    passar um que o banco recusaria.
 *
 * Usado pelo MadValidator (formulários) e pela validação da API REST
 * (ApiResourceController). App sem tenancy: comportamento idêntico ao
 * DatabasePresenceVerifier do Laravel.
 */
class ScopedPresenceVerifier extends DatabasePresenceVerifier
{
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        return parent::getCount(
            $collection, $column, $value, $excludeId, $idColumn,
            $this->withScope((string) $collection, (string) $column, $extra, $excludeId, $idColumn),
        );
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        return parent::getMultiCount(
            $collection, $column, $values,
            $this->withScope((string) $collection, (string) $column, $extra),
        );
    }

    /**
     * Condições de escopo da tabela (unit_id/tenant_id) acrescidas às da regra.
     * Nunca lança: na dúvida, a regra segue como o Laravel faria.
     */
    private function withScope(string $table, string $column, array $extra, $excludeId = null, $idColumn = null): array
    {
        try {
            $model = ScopedModels::forTable($this->connection, $table);
            if ($model === null) {
                return $extra;
            }

            $scope = [];
            if (AdminScope::readsAll()) {
                $scope = $this->adminWriteScope($model, $table, $excludeId, $idColumn);
            } elseif (method_exists($model, 'madUnitScopeIds')) {
                if (($unitIds = $model::madUnitScopeIds()) !== null) {
                    $scope['unit_id'] = $unitIds;
                }
            } elseif (method_exists($model, 'madUnitScopeId') && ($unitId = $model::madUnitScopeId()) !== null) {
                $scope['unit_id'] = $unitId;
            }
            if (method_exists($model, 'madTenantScopeId') && ($tenantId = $model::madTenantScopeId()) !== null) {
                $scope['tenant_id'] = $tenantId;
            }
            if ($scope === [] || $this->globallyUnique($table, $column)) {
                return $extra;
            }

            foreach ($scope as $scopeColumn => $id) {
                if (self::ruleFixes($extra, $scopeColumn)) {
                    continue;
                }
                $extra['__mad_scope_' . $scopeColumn] = static function ($query) use ($scopeColumn, $id): void {
                    if (! is_array($id)) {
                        $query->where($scopeColumn, $id);
                    } elseif (count($id) === 1) {
                        $query->where($scopeColumn, $id[0]);
                    } else {
                        $query->whereIn($scopeColumn, $id);
                    }
                };
            }
        } catch (\Throwable) {
            // sem model/escopo resolvível — conta como antes
        }

        return $extra;
    }

    /**
     * Escopo do `unique` na visão do administrador: a empresa/unidade DO
     * REGISTRO editado (excludeId) ou, no cadastro novo de regra sobre model
     * (o Laravel só passa idColumn no `unique`), a ativa. `exists` e `unique`
     * em string sem id: [] (sem filtro). Nunca lança.
     *
     * @param  class-string  $model
     * @return array<string, int>
     */
    private function adminWriteScope(string $model, string $table, $excludeId, $idColumn): array
    {
        $columns = [];
        if (method_exists($model, 'madTenantScoped') && $model::madTenantScoped()) {
            $columns['tenant_id'] = TenantContext::id();
        }
        if (method_exists($model, 'madUnitScoped') && $model::madUnitScoped()) {
            $columns['unit_id'] = UnitContext::id();
        }
        if ($columns === []) {
            return [];
        }

        if ($excludeId !== null && $excludeId !== '' && strtoupper((string) $excludeId) !== 'NULL') {
            // Edição: conta onde o registro MORA (lido sem escopo, pela PK).
            try {
                $row = $this->table($table)
                    ->where($idColumn ?: 'id', $excludeId)
                    ->first(array_keys($columns));
            } catch (\Throwable) {
                $row = null;
            }
            if ($row === null) {
                return []; // registro não achado: conta sem filtro (conservador)
            }
            $own = [];
            foreach (array_keys($columns) as $col) {
                $v = $row->{$col} ?? null;
                if ($v !== null && $v !== '' && is_numeric($v)) {
                    $own[$col] = (int) $v;
                }
            }

            return $own;
        }

        if ($idColumn === null || $idColumn === '') {
            return []; // exists (ou unique em string sem id): sem filtro
        }

        // Cadastro novo: onde o carimbo vai pôr o registro.
        return array_filter($columns, static fn ($v) => $v !== null);
    }

    /** A regra já filtra por esta coluna (`->where('unit_id', ...)`)? */
    private static function ruleFixes(array $extra, string $scopeColumn): bool
    {
        foreach (array_keys($extra) as $key) {
            if (is_string($key) && strtolower($key) === $scopeColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * O banco tem índice único (não-PK) só nesta coluna? Memo por request — a
     * migration que troca o índice pode rodar com o processo vivo.
     */
    private function globallyUnique(string $table, string $column): bool
    {
        $connection = $this->connection;

        return (bool) DataScope::memo(
            'presence.global-unique',
            ($connection ?? '') . '|' . $table . '|' . $column,
            function () use ($connection, $table, $column): bool {
                try {
                    $schema = $this->db->connection($connection)->getSchemaBuilder();
                    foreach ($schema->getIndexes($table) as $index) {
                        $columns = array_map('strtolower', (array) ($index['columns'] ?? []));
                        if (! empty($index['unique']) && empty($index['primary']) && $columns === [strtolower($column)]) {
                            return true;
                        }
                    }
                } catch (\Throwable) {
                    // introspecção indisponível — escopa (a leitura do model também escopa)
                }

                return false;
            },
        );
    }
}
