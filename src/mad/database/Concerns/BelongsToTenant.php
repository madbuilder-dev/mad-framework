<?php

namespace Mad\Database\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mad\Database\AdminScope;
use Mad\Database\ScopedReferences;
use Mad\Database\TenantContext;
use Mad\Database\TenantScopeViolation;

/**
 * BelongsToTenant — F6 (modo POOL). Isolação por LINHA em tabelas data-plane que
 * compartilham o MESMO DB (N tenants num banco só).
 *
 * Ligado pela flag mad.tenant.row_scope_enabled (default OFF = comportamento de
 * hoje, sem filtro). Quando ON e há tenant corrente (TenantContext::id()):
 *   - leitura: global scope adiciona WHERE tenant_id = <atual>;
 *   - escrita: preenche tenant_id vazio no save, preservando o escopo original no update;
 *     tenant_id explícito diferente da empresa ativa é recusado (TenantScopeViolation, fw#535).
 *
 * Visão do administrador do dono (mad.tenant.admin_scope = 'all',
 * {@see AdminScope}): a LEITURA dele não filtra ({@see TenantContext::readId()});
 * o carimbo continua sendo o tenant ativo, e um registro de OUTRA empresa
 * editado por ele não muda de empresa (TenantScopeViolation).
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
        return config('mad.tenant.row_scope_enabled') ? TenantContext::readId() : null;
    }

    /**
     * O escopo por tenant está ligado para esta tabela (independe de quem lê)?
     * O verificador de unique/exists usa na visão do administrador, quando
     * madTenantScopeId() é null mas o índice físico continua (tenant_id, coluna).
     */
    public static function madTenantScoped(): bool
    {
        return (bool) config('mad.tenant.row_scope_enabled');
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
            $tid = TenantContext::readId();
            if ($tid === null) {
                // Usuário logado sem empresa (unidade sem tenant): só as linhas
                // sem empresa, nunca as de todas (fw#501). Sem login, contexto
                // fixado por código ou visão do administrador => sem filtro.
                if (TenantContext::loggedWithoutTenant()) {
                    $builder->whereNull($builder->getModel()->getTable() . '.tenant_id');
                }

                return;
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

                return;
            }

            // Olha só o valor que MUDA: editar outro campo de uma linha não trava.
            if ($model->exists && ! $model->isDirty('tenant_id')) {
                return;
            }
            $shown = is_int($value) ? $value : (string) $value;

            // Visão do administrador: ele edita registros de outras empresas,
            // mas não os muda de empresa — só a leitura amplia.
            if ($model->exists && AdminScope::readsAll()) {
                $original = $model->getRawOriginal('tenant_id');
                if ($original !== null && $original !== '' && (int) $original !== (int) TenantContext::id()) {
                    throw new TenantScopeViolation($shown);
                }
            }

            // tenant_id explícito (form, API, código que atribui do getData()):
            // só a empresa ativa (fw#535). Sem empresa ativa não há o que
            // conferir; contexto fixado por código (comando, fila, seed) grava
            // onde o código mandou; com token (API REST, webhook), só a do token.
            $active = TenantContext::id();
            if ($active === null || (TenantContext::overridden() && ! TenantContext::fromToken())) {
                return;
            }
            if (! is_numeric($value) || (int) $value !== $active) {
                throw TenantScopeViolation::foreign($shown);
            }
        });

        // Chave para outro cadastro (paciente_id, profissional_id...): tem de
        // apontar para um registro que quem grava enxerga — o número que vem
        // do combo é dado do navegador. Depois do carimbo acima: a empresa
        // conferida é a do registro. Ver ScopedReferences.
        static::saving(static fn ($model) => ScopedReferences::guard($model));
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
