<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * QuerySource — normaliza a fonte de dados dos componentes MAD.
 *
 * Componentes (metric cards, charts, grids) aceitam:
 *  - Eloquent Builder (novo padrão): Pessoa::where(...) — já carrega model,
 *    connection e global scopes; o componente só agrega/executa.
 *  - Query Builder: DB::table(...)->where(...)
 *  - Fallback string database/entity → DB::connection()->table()
 *
 * Nunca muta a fonte — agregações sempre trabalham em clone, então o mesmo
 * Builder pode ser passado para mais de um componente.
 */
class QuerySource
{
    /**
     * @internal Guard instanceof de 1 linha — corpo é nativo
     * (`$v instanceof EloquentBuilder || $v instanceof QueryBuilder`). Existe só
     * pra definir a união Eloquent|Query num ponto só (~23 call-sites). Não é
     * algoritmo: é contrato de tipo centralizado.
     */
    public static function isQuery($value): bool
    {
        return $value instanceof EloquentBuilder || $value instanceof QueryBuilder;
    }

    /**
     * Query\Builder base (WHERE aplicado, sem select) pronta p/ agregação.
     *
     * @param mixed       $source   Eloquent Builder | Query Builder | null
     * @param string|null $database Conexão (apenas fallback string database/entity)
     * @param string|null $entity   Tabela (apenas fallback string database/entity)
     */
    public static function toBaseQuery($source, ?string $database = null, ?string $entity = null): QueryBuilder
    {
        if ($source instanceof EloquentBuilder) {
            return (clone $source)->toBase(); // wheres + global scopes + connection do model
        }
        if ($source instanceof QueryBuilder) {
            return clone $source;
        }

        // Fallback: tabela crua via database/entity (100% Query Builder).
        return DB::connection($database)->table($entity);
    }

    /**
     * Query\Builder de um MODEL para agregação/leitura: sempre pelo Eloquent,
     * com os global scopes do model — unidade/tenant do Multi-unidade e soft
     * delete. Nunca `DB::table()` a partir do nome da tabela: foi assim que os
     * gráficos e o pivot com `model=` somavam registros de todas as unidades
     * (e os excluídos) num SaaS.
     *
     * `$database` declarado escolhe a conexão (Model::on), como antes; vazio =
     * a conexão do próprio model. Colunas sem tabela deixadas pelos scopes (o
     * soft delete filtra `deleted_at` cru) são qualificadas com a tabela do
     * model: com JOIN, uma tabela juntada que também tenha a coluna deixaria o
     * WHERE ambíguo.
     */
    public static function modelBaseQuery(string $model, ?string $database = null): QueryBuilder
    {
        $eloquent = ($database !== null && $database !== '') ? $model::on($database) : $model::query();
        $table    = $eloquent->getModel()->getTable();

        $q = $eloquent->toBase();   // aplica os global scopes
        foreach ((array) $q->wheres as $i => $where) {
            $col = $where['column'] ?? null;
            if (is_string($col) && $col !== '' && strpos($col, '.') === false) {
                $q->wheres[$i]['column'] = $table . '.' . $col;
            }
        }

        return $q;
    }

    /** Tabela da fonte (p/ qualificar colunas em agregações). */
    public static function entity($source, ?string $fallback = null): ?string
    {
        if ($source instanceof EloquentBuilder) {
            return $source->getModel()->getTable();
        }
        if ($source instanceof QueryBuilder) {
            return is_string($source->from) ? $source->from : $fallback;
        }
        return $fallback;
    }

    /**
     * Compila um Builder em [sql, bindings] PARAMETRIZADO (binds separados, NÃO
     * inlined). String + escalares → serializa no token AJAX onde um Builder vivo
     * (PDO+closures) não caberia. O service re-aplica como derived table
     * (fromRaw("(sql) as q", bindings)) — builder-native.
     *
     * Usa toBaseQuery() (= toBase() = applyScopes()->getQuery()) pra que global
     * scopes (soft-delete/tenant) entrem na SQL ANTES de serializar.
     *
     * CONTRATO DE VERSÃO (canário: tests/Feature/QuerySourceCompileSqlScopeTest):
     *  - `$builder->getQuery()->toSql()` PULA o scope (getQuery = query crua, sem
     *    applyScopes — Builder.php:2015) → bypass inseguro, NUNCA serializar dele.
     *  - `$builder->toSql()` cru é seguro HOJE só porque 'tosql' é passthru e o
     *    __call roteia por toBase() (Illuminate\…\Eloquent\Builder.php:2270-2271).
     *    Se um upgrade tirar toSql do passthru, o toSql cru passa a VAZAR. Por isso
     *    compileSql() chama toBase() EXPLÍCITO via toBaseQuery() — não depende desse
     *    detalhe; o teste-canário falha e avisa se a premissa do Laravel mudar.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function compileSql($query): array
    {
        if (! self::isQuery($query)) {
            throw new \InvalidArgumentException('QuerySource::compileSql requer Eloquent/Query Builder.');
        }
        $base = self::toBaseQuery($query);   // scopes aplicados + clone (não muta a fonte)

        // Vira subquery `(...) as mad_q` no service. ORDER/LIMIT/OFFSET no inner
        // impedem o flattener do MySQL/Postgres (materializa = lesma) e são
        // irrelevantes — o service aplica ordem+limite por cima. SQLite achata
        // de qualquer forma (verificado via EXPLAIN), mas tira pra portabilidade.
        $base->orders  = null;
        $base->limit   = null;
        $base->offset  = null;

        return [$base->toSql(), $base->getBindings()];
    }

    /**
     * Aplica filtros array-DSL `[[campo, op, val(, binds)], ...]` DIRETO num
     * Eloquent/Query Builder — builder-native. É o caminho único do :filters dos
     * db* render-time.
     *
     * Subselect PARAMETRIZADO (não inline): op 'in'/'not in' + val 'SELECT ...'
     * + binds posicionais (?). Demais ops mapeiam pra where/whereIn/whereNull.
     *
     * @internal O CORPO é nativo (switch ~25 linhas sobre where/whereIn/whereNull/
     * whereRaw) — em render-time daria pra chamar o Builder direto. O PAPEL não:
     * este é o SINK SERVER-SIDE do array-DSL que viaja DENTRO do token cifrado
     * (depends-on, paginação, edição de grid). Chamada fluente/closure NÃO
     * serializa no token; só o array-DSL sobrevive ao round-trip e é re-aplicado
     * aqui no decode. Por isso não dá pra inlinar.
     *
     * SEGURANÇA (defense-in-depth):
     *  - `$field` passa por uma allowlist estrita de identificador (`col` ou
     *    `tabela.col`); qualquer outra coisa é REJEITADA (throw) — nenhum caller
     *    legítimo usa nome de coluna com caractere especial, e o ramo subselect
     *    interpola `$field` cru num whereRaw.
     *  - O ramo subselect (val string começando com SELECT → whereRaw) só é
     *    habilitado quando `$allowSubselect === true` — o padrão para filtros
     *    definidos pelo DESENVOLVEDOR no componente (:filters do blade). Filtros
     *    vindos do REQUEST (MadRecordService::queryFromParam) passam `false`, o que
     *    faz um valor "SELECT ..." cair no ramo literal parametrizado abaixo (nunca
     *    em whereRaw) — fechando a injeção via valor.
     *
     * @param bool $allowSubselect Habilita o ramo subselect raw (só p/ filtros
     *                             confiáveis/definidos pelo dev; false p/ request).
     */
    public static function applyArrayFilters($query, array $filters, bool $allowSubselect = true): void
    {
        foreach ($filters as $k => $f) {
            // Guard de FORMA. O contrato é LISTA DE TRIPLAS [campo, op, valor].
            // Um mapa associativo ['status' => 'novo'] entrega a STRING 'novo'
            // aqui, e $f[0]/$f[1] viram OFFSET DE STRING ('n'/'o'); o Laravel
            // ainda rebaixa o operador inválido 'o' a valor e a query sai como
            // `where "n" = 'o'` — silenciosamente errada (ou 42703, se a coluna
            // de 1 caractere não existir). Falhar alto é o que impede isso de
            // passar batido num card de métrica.
            if (! is_array($f)) {
                throw new \InvalidArgumentException(sprintf(
                    'QuerySource: cada filtro deve ser [campo, operador, valor]; recebido %s na chave "%s". '
                    ."Mapa associativo não é suportado — use [['%s', '=', <valor>]].",
                    get_debug_type($f), $k, is_string($k) ? $k : 'campo'
                ));
            }
            $field = (string) ($f[0] ?? '');
            if ($field === '') {
                continue;
            }
            // Allowlist estrita de identificador — bloqueia injeção via nome de
            // coluna (o subselect abaixo interpola $field cru em whereRaw).
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $field)) {
                throw new \InvalidArgumentException("QuerySource: identificador de coluna inválido em filtro: {$field}");
            }
            $op  = strtolower(trim((string) ($f[1] ?? '=')));
            $val = $f[2] ?? '';

            // Subselect: val string começando com SELECT (binds parametrizados).
            // Só p/ filtros CONFIÁVEIS ($allowSubselect). Request-driven → literal.
            if ($allowSubselect && is_string($val) && stripos(trim($val), 'SELECT') === 0) {
                $sub = trim($val);
                // Hardening extra mesmo em filtro confiável: rejeita stacked queries
                // (`;`) e comentários SQL (`--`, `/*`) — sem uso legítimo num
                // subselect parametrizado.
                if (preg_match('/;|--|\/\*/', $sub)) {
                    throw new \InvalidArgumentException('QuerySource: subselect inseguro rejeitado.');
                }
                $binds = $f[3] ?? [];
                if (! is_array($binds)) {
                    $binds = [$binds];
                }
                $neg = ($op === 'not in') ? 'not ' : '';
                $query->whereRaw("{$field} {$neg}in ({$sub})", $binds);
                continue;
            }

            switch ($op) {
                case 'in':          $query->whereIn($field, (array) $val); break;
                case 'not in':      $query->whereNotIn($field, (array) $val); break;
                case 'is null':     $query->whereNull($field); break;
                case 'is not null': $query->whereNotNull($field); break;
                default:            $query->where($field, $op, $val); break;
            }
        }
    }

    /** Nome da conexão de um Builder (p/ o service rodar no banco certo). */
    public static function connectionName($query): ?string
    {
        if (self::isQuery($query)) {
            return $query->getConnection()->getName();
        }
        return null;
    }

    /**
     * Aplica order/limit num Eloquent/Query
     * Builder JÁ filtrado e retorna os objetos. É o caminho :query/:filters
     * builder-native. Eloquent: get() preserva global scopes
     * (soft-delete). Não muta a fonte (clona). Aceita order 'col' ou 'col DESC'.
     *
     * @internal O ALGORITMO é nativo (clone + orderBy + limit + get()->all()).
     * O que segura a remoção é o CONTRATO, não o algoritmo: (1) clone-no-mutate —
     * o mesmo Builder vai pra vários helpers/componentes; mutar a fonte vaza
     * estado entre eles; (2) a DSL de order 'col DESC' (string única) é convenção
     * MAD, não nativa — inlinar duplica o parser nos ~27 call-sites. Fica aqui.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @return array<object>
     */
    public static function recordsFromQuery($query, ?string $order = null, ?int $limit = null): array
    {
        if (! self::isQuery($query)) {
            throw new \InvalidArgumentException('QuerySource::recordsFromQuery requer Eloquent/Query Builder.');
        }
        $q = clone $query;

        if ($order !== null && $order !== '') {
            $parts = preg_split('/\s+/', trim($order));
            $col   = $parts[0] ?? '';
            $dir   = (isset($parts[1]) && strtolower($parts[1]) === 'desc') ? 'desc' : 'asc';
            if ($col !== '') {
                $q->orderBy($col, $dir);
            }
        }
        if ($limit !== null && $limit > 0) {
            $q->limit($limit);
        }

        return $q->get()->all();
    }

    /**
     * Executa agregação ($total: count|sum|avg|min|max) sobre a fonte.
     *
     * @return array [float $valor, string $sql, array $binds]
     */
    public static function aggregate($source, string $total, string $field, ?string $database = null, ?string $entity = null): array
    {
        $q = self::toBaseQuery($source, $database, $entity);

        $entityName = self::entity($source, $entity ?: (is_string($q->from) ? $q->from : null));

        $fieldRef = $field;
        if ($entityName && strpos($fieldRef, '.') === false && strpos($fieldRef, '(') === false) {
            $fieldRef = "{$entityName}.{$fieldRef}";
        }

        $q->columns = null; // agregação substitui qualquer select vindo da fonte
        $q->selectRaw("{$total}({$fieldRef}) as total");

        $sql   = $q->toSql();
        $binds = $q->getBindings();
        // Cache opcional por conexão+SQL+bindings (mad.chart.cache_ttl; 0 = off).
        $row   = QueryCache::remember(
            (string) $q->getConnection()->getName(),
            $sql,
            $binds,
            fn () => $q->first(),
        );

        return [(float) ($row->total ?? 0), $sql, $binds];
    }
}
