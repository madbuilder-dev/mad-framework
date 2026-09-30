<?php

namespace Mad\Database\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mad\Database\UnitContext;

/**
 * BelongsToUnit — isolação por LINHA por UNIDADE (filial) em tabelas data-plane.
 * Espelho do BelongsToTenant, mas o eixo é a FILIAL dentro do mesmo cliente.
 *
 * Ligado pela flag mad.general.multiunit ('1' = ON; default '0' = sem filtro,
 * comportamento de hoje). Quando ON e há unidade ativa (UnitContext::id()):
 *   - leitura: global scope adiciona WHERE unit_id = <atual> — ou, no modo
 *     mad.general.unit_scope = 'all', WHERE unit_id IN (<unidades do usuário>)
 *     ({@see UnitContext::readIds()});
 *   - escrita: preenche unit_id vazio no save (com a ATIVA, nos dois modos),
 *     preservando o escopo original no update;
 *   - conferência: unit_id explícito que muda para uma unidade fora das do
 *     usuário ({@see UnitContext::allowedIds()}) lança UnitScopeViolation.
 *
 * FLAG POR MODEL (opcional): um model pode ter a SUA flag sobrescrevendo
 * unitScopeFlagKey() (ex.: GED → 'mad.ged.multiunit'). A flag específica vence
 * quando setada ('1'/'0'); unset/'' herda a global mad.general.multiunit. Assim
 * dá pra ter o resto do sistema per-unidade e um módulo nível-empresa (ou o inverso).
 *
 * Aplicado só nos models data-plane (gerados com coluna unit_id). Control (iam/log)
 * NUNCA usa — identidade/auditoria são globais ao install. Combinável com
 * BelongsToTenant (tenant = empresa; unit = filial dentro da empresa).
 */
trait BelongsToUnit
{
    /**
     * Config key da flag que liga o row-scope por unidade NESTE model. Override
     * em models de módulo p/ flag própria (ex.: GED → 'mad.ged.multiunit').
     * null = usa só a flag global mad.general.multiunit.
     */
    protected static function unitScopeFlagKey(): ?string
    {
        return null;
    }

    /**
     * Resolve se o row-scope por unidade está ligado p/ este model: a flag
     * específica (unitScopeFlagKey) vence quando setada ('1'/'0'); unset/''
     * herda a flag global mad.general.multiunit. Default '0' (OFF).
     */
    protected static function unitScopeEnabled(): bool
    {
        $key = static::unitScopeFlagKey();
        if ($key !== null) {
            $v = config($key);
            if ($v !== null && $v !== '') {
                return (string) $v === '1';
            }
        }

        return (string) config('mad.general.multiunit', '0') === '1';
    }

    /**
     * Unidade que o escopo desta tabela usa AGORA — a mesma do global scope
     * `mad_unit` abaixo: null com o escopo desligado (flag) ou sem unidade
     * corrente. Público para quem conta linhas FORA da query Eloquent: as regras
     * `unique`/`exists` ({@see \Mad\Form\ScopedPresenceVerifier}).
     */
    public static function madUnitScopeId(): ?int
    {
        return static::unitScopeEnabled() ? UnitContext::id() : null;
    }

    /**
     * Unidades que o escopo desta tabela enxerga AGORA (a ativa, ou todas as do
     * usuário no modo 'all'). null = sem filtro. É o que as regras
     * `unique`/`exists` usam para contar as mesmas linhas que a leitura.
     *
     * @return list<int>|null
     */
    public static function madUnitScopeIds(): ?array
    {
        return static::unitScopeEnabled() ? UnitContext::readIds() : null;
    }

    public static function bootBelongsToUnit(): void
    {
        // Tabela escopada: o verificador de unique/exists só recebe o NOME da
        // tabela e precisa achar o model para aplicar a unidade.
        \Mad\Database\ScopedModels::register(static::class);

        static::addGlobalScope('mad_unit', function (Builder $builder): void {
            $model = $builder->getModel();
            if (! $model::unitScopeEnabled()) {
                return; // flag OFF (específica ou global) => sem filtro
            }
            $ids = UnitContext::readIds();
            if ($ids === null) {
                return; // sem unidade corrente => sem filtro
            }
            // Uma unidade só (modo 'active', ou usuário de uma filial): o mesmo
            // `=` de sempre — SQL, plano e chave de cache idênticos.
            if (count($ids) === 1) {
                $builder->where($model->getTable() . '.unit_id', $ids[0]);
            } else {
                $builder->whereIn($model->getTable() . '.unit_id', $ids);
            }
        });

        static::saving(function ($model): void {
            if (! $model::unitScopeEnabled()) {
                return;
            }
            // MAD forms post an unselected combo as ''. Treat it as absent,
            // including on edits: a blank field must not orphan an existing row.
            $value = $model->unit_id ?? null;
            if ($value === null || $value === '') {
                $original = $model->exists ? $model->getRawOriginal('unit_id') : null;
                $scope = ($original !== null && $original !== '') ? $original : UnitContext::id();
                if ($scope !== null) {
                    $model->unit_id = $scope;
                }

                return; // carimbo: ativa ou a unidade que já era da linha
            }

            // unit_id explícito (form, import, código): só pode ir para uma
            // unidade do usuário. Olha apenas o valor que MUDA — editar outro
            // campo de uma linha legada não trava. Sem lista na sessão (job,
            // console, API com override) não há como conferir.
            if ($model->exists && ! $model->isDirty('unit_id')) {
                return;
            }
            $allowed = UnitContext::allowedIds();
            if ($allowed === null || ! is_numeric($value)) {
                return;
            }
            if (! in_array((int) $value, $allowed, true)) {
                throw new \Mad\Database\UnitScopeViolation(is_int($value) ? $value : (string) $value);
            }
        });
    }

    /**
     * Relação p/ a unidade dona da linha — permite `$row->unit->name` e o chain
     * `{unit->name}` nas listagens sem geração por-model (o db-fk lógico de escopo
     * usa esta relação; o ModelGenerator NÃO emite belongsTo p/ unit_id por isso).
     * Cross-conexão: Iam\Unit tem $connection próprio → query separada, sem JOIN.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Iam\Unit::class, 'unit_id');
    }
}
