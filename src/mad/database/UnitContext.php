<?php

namespace Mad\Database;

/**
 * UnitContext — unidade (filial) ATIVA p/ o row-scope por unidade.
 *
 * Fonte padrão: session('userunitid') (populada no login / troca de unidade por
 * AuthenticationService::loadSessionVars/setUnit — a unidade ATIVA, não a lista
 * userunitids). Override explícito p/ jobs/console/testes via set(); p/ a API
 * REST e o webhook por unidade via setFromToken(). null = sem unidade (scope não
 * filtra).
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
 *
 * SEM UNIDADE (mad.general.unit_scope_empty, env MAD_UNIT_SCOPE_EMPTY):
 *   - 'open' (padrão): pedido sem unidade lê as linhas de todas — o histórico;
 *   - 'closed': pedido HTTP sem unidade (visitante de uma página do site, rota
 *     sem login) não lê linha nenhuma — readIds() devolve []. A página define a
 *     unidade com set($id); quem quer mesmo ler todas diz isso com set(null) ou
 *     com `->withoutGlobalScope('mad_unit')` na consulta. Console, agendador e
 *     fila seguem abertos ({@see closedWhenEmpty()}).
 */
class UnitContext
{
    private static ?int $override = null;
    private static bool $hasOverride = false;
    private static bool $byToken = false;

    public static function set(?int $id): void
    {
        self::$override = $id;
        self::$hasOverride = true;
        self::$byToken = false;
    }

    /**
     * Fixa a unidade a partir de uma credencial — token da API REST, chave do
     * webhook por unidade ({@see TenantContext::setFromToken()}). Estrita como
     * o set(); a diferença é que a gravação é conferida ({@see fromToken()}).
     */
    public static function setFromToken(?int $id): void
    {
        self::set($id);
        self::$byToken = true;
    }

    public static function clear(): void
    {
        self::$override = null;
        self::$hasOverride = false;
        self::$byToken = false;
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

    /** Unidade fixada por setFromToken() (API REST, webhook) — e não por código? */
    public static function fromToken(): bool
    {
        return self::$hasOverride && self::$byToken;
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
     * O pedido sem unidade lê NADA em vez de tudo? Só com a opção
     * mad.general.unit_scope_empty = 'closed' (qualquer outro valor = aberto, o
     * histórico) e só em pedido HTTP: comando, agendador e fila não têm sessão
     * e seguem lendo como sempre. Quem fixou o contexto com set() — inclusive
     * set(null), "sem unidade de propósito" — responde por ele.
     */
    public static function closedWhenEmpty(): bool
    {
        if (self::$hasOverride) {
            return false;
        }
        try {
            if (! function_exists('config') || (string) config('mad.general.unit_scope_empty', 'open') !== 'closed') {
                return false;
            }
            $app = app();

            return ! ($app->runningInConsole() && ! $app->runningUnitTests());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Unidades que a LEITURA enxerga agora (ordenadas, sem repetição). null =
     * sem unidade (o scope não filtra — fail-open, como id()) ou visão de
     * todas as empresas do administrador do dono ({@see AdminScope::readsAll()}).
     * [] = sem unidade com a leitura fechada ({@see closedWhenEmpty()}): nenhuma
     * linha.
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
            return $active === null ? self::emptyRead() : [$active];
        }

        $ids = self::sessionUnitIds() ?? [];
        if ($active !== null) {
            $ids[] = $active;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids === [] ? self::emptyRead() : $ids;
    }

    /**
     * Registra que a leitura de $model foi fechada por falta de unidade — um
     * aviso no log por tabela e por pedido, com o caminho de quem leu. Sem ele
     * a página só mostra uma lista vazia. Nunca lança.
     */
    public static function noteClosedRead(string $model): void
    {
        try {
            DataScope::memo('unit_scope_closed', $model, static function () use ($model): bool {
                $path = null;
                try {
                    $path = app()->bound('request') ? request()->path() : null;
                } catch (\Throwable) {
                }
                \Illuminate\Support\Facades\Log::warning('mad.unit_scope.closed', [
                    'model' => $model,
                    'path'  => $path,
                    'hint'  => 'Sem unidade no pedido: defina com UnitContext::set($id) antes de ler, ou UnitContext::set(null) para ler todas de propósito.',
                ]);

                return true;
            });
        } catch (\Throwable) {
        }
    }

    /** O que readIds() devolve sem unidade: null (aberto) ou [] (fechado). */
    private static function emptyRead(): ?array
    {
        return self::closedWhenEmpty() ? [] : null;
    }

    /**
     * Unidades em que o usuário do request pode GRAVAR: as dele (userunitids)
     * mais a ativa; com token (API REST, webhook por unidade), só a do token —
     * a mesma que a leitura enxerga. null = não há como saber (override de
     * job/console, sessão sem a lista) e quem chama não confere — o código que
     * fixou a unidade responde por ela.
     *
     * @return list<int>|null
     */
    public static function allowedIds(): ?array
    {
        if (self::$hasOverride) {
            return self::$byToken && self::$override !== null ? [self::$override] : null;
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
        if ($ids === []) {
            return 'x'; // sem unidade, leitura fechada: não divide cache com a aberta
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
