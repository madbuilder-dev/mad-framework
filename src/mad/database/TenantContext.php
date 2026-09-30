<?php

namespace Mad\Database;

/**
 * TenantContext — F6 (modo POOL). Tenant atual p/ o row-scope.
 *
 * Fonte padrão: session('tenant_id') (populada no login por
 * ApplicationAuthenticationService::loadSessionVars). Override explícito p/ jobs/
 * console/testes via set(). null = sem tenant (scope não filtra).
 *
 * Pool ≠ bridge: aqui NÃO se repointa conexão (todos os tenants no MESMO DB); a
 * isolação é por LINHA (tenant_id) via trait BelongsToTenant, ligada pela flag
 * mad.tenant.row_scope_enabled.
 *
 * Namespace Mad\Database (não Mad\Tenant): mesmo prefixo PSR-4 de Transaction/
 * ConnectionRegistry — autoload portável (Linux case-sensitive) sem novo prefixo.
 *
 * name(): nome do tenant atual. A sessão só guarda o id (não existe
 * 'tenantname'), então é sempre uma leitura no model do esqueleto; sem
 * tenant_id na sessão deriva da unidade ativa (mesma regra do ChangeTenantForm).
 * Nunca lança: sem tenant, sem sessão ou sem DB devolve ''.
 */
class TenantContext
{
    private static ?int $override = null;
    private static bool $hasOverride = false;

    public static function set(?int $id): void
    {
        self::$override = $id;
        self::$hasOverride = true;
    }

    public static function clear(): void
    {
        self::$override = null;
        self::$hasOverride = false;
    }

    public static function id(): ?int
    {
        if (self::$hasOverride) {
            return self::$override;
        }
        $sid = function_exists('session') ? session('tenant_id') : null;
        return ($sid === null || $sid === '') ? null : (int) $sid;
    }

    /** Nome do tenant atual; '' sem tenant, sem sessão ou sem DB (nunca lança). */
    public static function name(): string
    {
        if (!class_exists(\App\Models\Iam\Tenant::class)) {
            return '';
        }
        try {
            $id = self::id();
            // Sem tenant_id (app sem tenancy explícita, login por token): o
            // tenant é o da unidade ativa — mesma derivação do ChangeTenantForm.
            if ($id === null && !self::$hasOverride && class_exists(\App\Models\Iam\Unit::class)) {
                $unitId = UnitContext::id();
                $id = $unitId === null ? null : \App\Models\Iam\Unit::query()->find($unitId)?->tenant_id;
                $id = ($id === null || $id === '') ? null : (int) $id;
            }
            if ($id === null) {
                return '';
            }
            return trim((string) (\App\Models\Iam\Tenant::query()->find($id)?->name ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }
}
