<?php

namespace Mad\Database;

/**
 * UnitContext — unidade (filial) ATIVA p/ o row-scope por unidade.
 *
 * Fonte padrão: session('userunitid') (populada no login / troca de unidade por
 * AuthenticationService::loadSessionVars/setUnit — a unidade ATIVA, não a lista
 * userunitids). Override explícito p/ jobs/console/testes via set(). null = sem
 * unidade (scope não filtra).
 *
 * Multi-unidade ≠ multi-tenant: aqui é a FILIAL dentro do MESMO cliente; a
 * isolação é por LINHA (unit_id) via trait BelongsToUnit, ligada pela flag
 * mad.general.multiunit. Namespace Mad\Database (mesmo prefixo de TenantContext).
 *
 * name(): nome da unidade ativa — lê session('userunitname') (gravada junto com
 * o id no login e na troca de unidade) e cai no model do esqueleto quando a
 * sessão só tem o id (login por token/JWT, jobs com set()). Nunca lança: sem
 * unidade, sem sessão ou sem DB devolve ''.
 *
 * MODO DE LEITURA (mad.general.unit_scope, env MAD_UNIT_SCOPE):
 *   - 'active' (padrão): a leitura enxerga só a unidade ativa — id();
 *   - 'all': enxerga TODAS as unidades do usuário (session('userunitids'), com a
 *     ativa sempre incluída). A gravação continua indo para a ativa.
 * readIds() é o que o global scope do BelongsToUnit usa; id() segue sendo a
 * unidade ativa (carimbo, licença, telas de admin da unidade). Override set()
 * é sempre estrito: job/API/teste que fixa uma unidade enxerga só ela.
 *
 * VISÃO DO ADMINISTRADOR (mad.tenant.admin_scope = 'all', {@see AdminScope}):
 * para o administrador do dono do app, readIds() devolve null (sem filtro de
 * unidade). id() e allowedIds() não mudam — a gravação não amplia.
 */
class UnitContext
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
        $sid = function_exists('session') ? session('userunitid') : null;

        return ($sid === null || $sid === '') ? null : (int) $sid;
    }

    /** Contexto fixado por set() (job, console, API REST, teste)? */
    public static function overridden(): bool
    {
        return self::$hasOverride;
    }

    /** 'all' só com o valor exato; qualquer outro (inclusive vazio) = 'active'. */
    public static function mode(): string
    {
        try {
            $v = function_exists('config') ? config('mad.general.unit_scope', 'active') : 'active';
        } catch (\Throwable) {
            $v = 'active';
        }

        return (string) $v === 'all' ? 'all' : 'active';
    }

    /**
     * Unidades que a LEITURA enxerga agora (ordenadas, sem repetição). null =
     * sem unidade (o scope não filtra — fail-open, como id()) ou visão de
     * todas as empresas do administrador do dono ({@see AdminScope::readsAll()}).
     *
     * @return list<int>|null
     */
    public static function readIds(): ?array
    {
        // Visão de todas as empresas do administrador do dono: sem filtro de
        // unidade (null, como "sem unidade"). Gravação segue na ativa (id()).
        if (AdminScope::readsAll()) {
            return null;
        }

        $active = self::id();
        if (self::$hasOverride || self::mode() !== 'all') {
            return $active === null ? null : [$active];
        }

        $ids = self::sessionUnitIds() ?? [];
        if ($active !== null) {
            $ids[] = $active;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids === [] ? null : $ids;
    }

    /**
     * Unidades em que o usuário do request pode GRAVAR: as dele (userunitids)
     * mais a ativa. null = não há como saber (override de job/API, console,
     * sessão sem a lista) e quem chama não confere — o código que fixou a
     * unidade responde por ela.
     *
     * @return list<int>|null
     */
    public static function allowedIds(): ?array
    {
        if (self::$hasOverride) {
            return null;
        }
        $ids = self::sessionUnitIds();
        if ($ids === null || $ids === []) {
            return null;
        }
        if (($active = self::id()) !== null) {
            $ids[] = $active;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Segmento de chave de cache/memo da leitura corrente: o id da ativa quando a
     * leitura é de uma unidade só (a chave de sempre); 'a' + hash da lista no
     * modo 'all' com mais de uma — dois usuários com a mesma ativa e listas
     * diferentes não podem dividir cache. '-' sem unidade e na visão de todas
     * as empresas do administrador (o DataScope::cacheKey() acrescenta `.adm`).
     */
    public static function scopeKey(): string
    {
        $ids = self::readIds();
        if ($ids === null) {
            return '-';
        }
        if (count($ids) === 1) {
            return (string) $ids[0];
        }

        return 'a' . substr(sha1(implode(',', $ids)), 0, 12);
    }

    /** @return list<int>|null lista de unidades do usuário na sessão (null sem sessão/lista) */
    private static function sessionUnitIds(): ?array
    {
        $raw = self::session('userunitids');
        if (is_string($raw) && $raw !== '') {
            $raw = explode(',', $raw); // getSystemUserUnitIds(true) grava CSV
        }
        if (! is_array($raw)) {
            return null;
        }
        $ids = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }

    /** Nome da unidade ativa; '' sem unidade, sem sessão ou sem DB (nunca lança). */
    public static function name(): string
    {
        // Override (jobs/testes) ignora a sessão de propósito: o nome gravado
        // ali é da unidade do request, não da que o job assumiu.
        if (!self::$hasOverride) {
            $fromSession = self::session('userunitname');
            if (is_string($fromSession) && trim($fromSession) !== '') {
                return trim($fromSession);
            }
        }

        $id = self::id();
        if ($id === null) {
            return '';
        }

        // Model do esqueleto (App\Models\Iam\Unit), não do package — guardado
        // pra rodar em teste/console sem o app.
        if (!class_exists(\App\Models\Iam\Unit::class)) {
            return '';
        }
        try {
            return trim((string) (\App\Models\Iam\Unit::query()->find($id)?->name ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Leitura de sessão que não lança fora de request (console, testes sem kernel). */
    private static function session(string $key): mixed
    {
        try {
            return (function_exists('session') && app()->bound('session')) ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
