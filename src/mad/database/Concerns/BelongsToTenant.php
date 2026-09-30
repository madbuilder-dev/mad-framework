<?php

namespace Mad\Database\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mad\Database\TenantContext;

/**
 * BelongsToTenant — F6 (modo POOL). Isolação por LINHA em tabelas data-plane que
 * compartilham o MESMO DB (N tenants num banco só).
 *
 * Ligado pela flag mad.tenant.row_scope_enabled (default OFF = comportamento de
 * hoje, sem filtro). Quando ON e há tenant corrente (TenantContext::id()):
 *   - leitura: global scope adiciona WHERE tenant_id = <atual>;
 *   - escrita: preenche tenant_id vazio no save, preservando o escopo original no update.
 *
 * Aplicado só nos models data-plane (business/comm/ged/ai). Control (iam/log)
 * NUNCA usa — identidade/auditoria são globais.
 */
trait BelongsToTenant
{
    /**
     * Tenant que o escopo desta tabela usa AGORA — o mesmo do global scope
     * `mad_tenant` abaixo: null com o row-scope desligado ou sem tenant
     * corrente. Público para quem conta linhas FORA da query Eloquent: as regras
     * `unique`/`exists` ({@see \Mad\Form\ScopedPresenceVerifier}).
     */
    public static function madTenantScopeId(): ?int
    {
        return config('mad.tenant.row_scope_enabled') ? TenantContext::id() : null;
    }

    public static function bootBelongsToTenant(): void
    {
        // Tabela escopada: o verificador de unique/exists só recebe o NOME da
        // tabela e precisa achar o model para aplicar o tenant.
        \Mad\Database\ScopedModels::register(static::class);

        static::addGlobalScope('mad_tenant', function (Builder $builder): void {
            if (! config('mad.tenant.row_scope_enabled')) {
                return; // flag OFF => sem filtro (comportamento de hoje)
            }
            $tid = TenantContext::id();
            if ($tid === null) {
                return; // sem tenant corrente => sem filtro
            }
            $builder->where($builder->getModel()->getTable() . '.tenant_id', $tid);
        });

        static::saving(function ($model): void {
            if (! config('mad.tenant.row_scope_enabled')) {
                return;
            }
            // MAD forms post an unselected combo as ''. Treat it as absent,
            // including on edits: a blank field must not orphan an existing row.
            $value = $model->tenant_id ?? null;
            if ($value === null || $value === '') {
                $original = $model->exists ? $model->getRawOriginal('tenant_id') : null;
                $scope = ($original !== null && $original !== '') ? $original : TenantContext::id();
                if ($scope !== null) {
                    $model->tenant_id = $scope;
                }
            }
        });
    }

    /**
     * Relação p/ o tenant (cliente/empresa) dono da linha — permite `$row->tenant->name`
     * e o chain `{tenant->name}` nas listagens sem geração por-model (o db-fk lógico de
     * escopo usa esta relação; o ModelGenerator NÃO emite belongsTo p/ tenant_id por isso).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Iam\Tenant::class, 'tenant_id');
    }
}
