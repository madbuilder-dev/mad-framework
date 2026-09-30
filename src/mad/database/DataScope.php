<?php

namespace Mad\Database;

/**
 * DataScope — "de quem são estes dados" no request corrente, para o que fica
 * FORA da query Eloquent: chaves de cache/memo e os caminhos crus (MCP, SQL de
 * widget, log de alterações, conciliação).
 *
 * Por que existe: o escopo de unidade/tenant (BelongsToUnit / BelongsToTenant)
 * só vale DENTRO da query. Um memo em `static` sobrevive ao request num worker
 * persistente (Octane) e devolvia à unidade B as opções de filtro, os rótulos
 * de FK e o painel cacheado que a query escopada tinha carregado para a
 * unidade A — a query nem chegava a rodar. Ver docs/multi-tenancy.md.
 *
 *  - {@see active()}  : Multi-unidade (mad.general.multiunit) ou tenant em pool
 *                       (mad.tenant.row_scope_enabled) ligados.
 *  - {@see cacheKey()}: segmento de chave para cache PERSISTENTE (facade Cache).
 *                       Vazio com a tenancy desligada — a chave de um app de
 *                       unidade única não muda.
 *  - {@see memo()}    : memo POR REQUEST (binding `scoped` do container: o
 *                       Octane e o queue worker descartam a cada request/job),
 *                       com a chave já prefixada pelo escopo — trocar de
 *                       unidade no meio do processo (job, teste) não reaproveita.
 */
final class DataScope
{
    private const MEMO_BINDING = 'mad.data_scope.memo';

    /** Row-scope por unidade ligado no app (flag global do BelongsToUnit). */
    public static function unitScopeActive(): bool
    {
        return (string) self::config('mad.general.multiunit', '0') === '1';
    }

    /** Row-scope por tenant (modo pool) ligado no app. */
    public static function tenantScopeActive(): bool
    {
        return (bool) self::config('mad.tenant.row_scope_enabled', false);
    }

    public static function active(): bool
    {
        return self::unitScopeActive() || self::tenantScopeActive();
    }

    /**
     * Segmento de chave do escopo corrente: `t{tenant}.u{unidade}` (+ `.us{usuário}`
     * com $withUser). String vazia quando a tenancy está desligada e $force é
     * false — assim nenhuma chave de app de unidade única muda.
     */
    public static function cacheKey(bool $withUser = false, bool $force = false): string
    {
        if (! $force && ! self::active()) {
            return '';
        }

        // `.u` = unidades que a leitura enxerga: o id da ativa (a chave de
        // sempre) ou, no modo "todas as unidades", o hash da lista do usuário.
        $key = 't' . (TenantContext::id() ?? '-') . '.u' . UnitContext::scopeKey();
        if ($withUser) {
            $key .= '.us' . (self::userId() ?? '-');
        }

        return $key;
    }

    /**
     * Memo por request, isolado por escopo (tenant/unidade/usuário). Fora de um
     * app Laravel (sem container) só computa.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public static function memo(string $bucket, string $key, callable $compute): mixed
    {
        $store = self::store();
        if ($store === null) {
            return $compute();
        }

        $k = $bucket . '|' . self::cacheKey(true, true) . '|' . $key;
        if (! array_key_exists($k, $store->items)) {
            $store->items[$k] = $compute();
        }

        return $store->items[$k];
    }

    /** Esvazia um bucket do memo (ou todos) — testes e quem invalida na mão. */
    public static function flushMemo(?string $bucket = null): void
    {
        $store = self::store();
        if ($store === null) {
            return;
        }
        if ($bucket === null) {
            $store->items = [];

            return;
        }
        foreach (array_keys($store->items) as $k) {
            if (str_starts_with((string) $k, $bucket . '|')) {
                unset($store->items[$k]);
            }
        }
    }

    /** Usuário do request (o app gerado identifica por session('userid'), não pelo guard). */
    public static function userId(): ?int
    {
        try {
            $v = (function_exists('session') && app()->bound('session')) ? session('userid') : null;
        } catch (\Throwable) {
            $v = null;
        }

        return ($v === null || $v === '') ? null : (int) $v;
    }

    private static function store(): ?object
    {
        try {
            if (! function_exists('app')) {
                return null;
            }
            $app = app();
            if (! $app->bound(self::MEMO_BINDING)) {
                $app->scoped(self::MEMO_BINDING, static fn () => new class {
                    /** @var array<string, mixed> */
                    public array $items = [];
                });
            }

            return $app->make(self::MEMO_BINDING);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function config(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
