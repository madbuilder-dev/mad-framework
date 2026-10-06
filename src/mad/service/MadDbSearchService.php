<?php

namespace Mad\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mad\Component\MadComponent;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadStateCrypt;

/**
 * MadDbSearchService — endpoint AJAX para busca server-side em dbunique-search
 * e dbmulti-search.
 *
 * O MAD Select do componente chama este endpoint a cada digitacao (debounced).
 * A config da query (model, database, key, display, query_sql) NUNCA vem do
 * cliente: e criptografada server-side no render do Blade via
 * MadStateCrypt::encrypt() e embutida no DOM em data-mad-search-token.
 * O cliente envia apenas { token, q, depValue? }.
 *
 * Estende MadComponent apenas pelo contrato do dispatcher (MadAppController
 * so roda subclasses); o unico entry point e o metodo estatico via ?static=1.
 *
 * Chamado via: /app/MadDbSearchService/onSearch?static=1
 */
class MadDbSearchService extends MadComponent
{
    public static function onSearch(array $param = []): void
    {
        $token    = (string) ($param['token'] ?? '');
        $q        = trim((string) ($param['q'] ?? ''));
        $depValue = (string) ($param['depValue'] ?? ''); // valor do campo pai (depends-on)

        $results = [];
        $error   = null; // aviso da falha, só com APP_DEBUG (\Mad\Form\OptionsLoadError)

        if ($token !== '') {
            // Só token de busca (ou sem finalidade, cunhado antes): o de cascata
            // de um combo, colado aqui, listava o model sem o filtro do combo.
            $config = MadStateCrypt::decryptFor('db-search', $token);
            if (is_array($config)) {
                $database = (string)($config['database'] ?? (config('mad.main_database') ?: config('database.default', 'default')));
                $model    = (string)($config['model']    ?? '');
                $keyField = (string)($config['key']      ?? 'id');
                $display  = (string)($config['display']  ?? 'nome');
                $order    = (string)($config['order']    ?? '');
                // Direção do order (fw ≥ 5.18). Tokens antigos não têm o campo
                // → asc, comportamento idêntico ao anterior.
                $orderDir = strtolower((string)($config['order_dir'] ?? 'asc'));
                if (! in_array($orderDir, ['asc', 'desc'], true)) {
                    $orderDir = 'asc';
                }
                $column   = (string)($config['column']   ?? '');  // depends-on column
                $limit    = (int)($config['limit']       ?? 500);

                $querySql      = (string)($config['query_sql'] ?? '');
                $queryBindings = (array)($config['query_bindings'] ?? []);

                if ($querySql !== '' || $model !== '') {
                    try {
                        $dbType     = strtolower(DB::connection($database)->getDriverName());
                        $useILike   = ($dbType === 'pgsql');
                        $useLowerFn = in_array($dbType, ['mysql', 'oracle', 'mssql', 'dblib', 'sqlsrv'], true);
                        // search_columns (fw ≥ 5.19): colunas do LIKE independentes
                        // do label. Token antigo/sem a chave → colunas do display.
                        //
                        // Coluna de chain ('estado->sigla') NUNCA entra no LIKE
                        // direto: no where() o Eloquent lê '->' como JSON path e
                        // um '{estado->sigla}' literal viraria identificador —
                        // SQL inválido, exceção engolida no catch abaixo e lista
                        // VAZIA sem uma linha de log. Ela é separada aqui e vira
                        // whereHas na relação (só no caminho model/Eloquent).
                        $rawColumns    = self::sanitizeColumns($config['search_columns'] ?? []);
                        $searchColumns = $rawColumns
                            ? self::plainOnly($rawColumns)
                            : self::extractColumns($display);
                        $chainColumns  = $rawColumns
                            ? self::chainOnly($rawColumns)
                            : ModelOptionsLoader::chainTokens($display);

                        $terms      = self::searchTerms($q);
                        $isTemplate = (strpos($display, '{') !== false);
                        // display nu em forma de chain: 'estado->sigla'
                        $displayChain = ! $isTemplate && strpos($display, '->') !== false;
                        $displayChains = ModelOptionsLoader::chainTokens($display);

                        if ($querySql !== '') {
                            // Caminho :query — derived table (fromRaw), 100% Query Builder.
                            // A SQL do dev (com soft-delete já aplicado via toBase) vira
                            // subquery; busca LIKE + depends-on + limit por cima.
                            //
                            // LIMITAÇÃO ASSUMIDA: aqui as linhas são stdClass (não há
                            // model por trás da derived table), então chain de relação
                            // não resolve nem no label nem na busca — o :query precisa
                            // trazer a coluna já achatada no SELECT (alias 'estado_sigla'
                            // ou 'estado->sigla', que o ModelOptionsLoader aceita).
                            $qb = DB::connection($database)->query()
                                ->fromRaw("({$querySql}) as mad_q", $queryBindings);

                            if ($depValue !== '' && $column !== '') {
                                $qb->where($column, $depValue);
                            }
                            if ($q !== '' && $searchColumns) {
                                $qb->where(function ($w) use ($searchColumns, $terms, $useILike, $useLowerFn) {
                                    $first = true;
                                    foreach ($searchColumns as $col) {
                                        foreach ($terms as $t) {
                                            self::applyLike($w, $col, $t, $useILike, $useLowerFn, $first);
                                            $first = false;
                                        }
                                    }
                                });
                            }
                            if ($order !== '') {
                                $qb->orderBy($order, $orderDir);
                            }
                            $collection = $qb->limit($limit)->get();
                        } else {
                            // F5: fallback model (sem :query) — busca LIKE + depends-on no builder.
                            $__cls      = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                            $__eloquent = $database ? $__cls::on($database) : $__cls::query();

                            // getQuery() (Query\Builder) devolve stdClass: rápido, mas
                            // sem relação nenhuma pra percorrer. Se o display OU a busca
                            // têm chain, ficamos no Eloquent\Builder e iteramos MODELS.
                            // O get() do Eloquent roda applyScopes() igual ao toBase(),
                            // então soft-delete/tenant continuam valendo.
                            $useModels = ($displayChains || $chainColumns);
                            // toBase(), não getQuery(): getQuery() pula os global
                            // scopes (unidade/tenant, soft delete). Um token sem
                            // query_sql — o de dependência de um combo, colado
                            // nesta URL — listava os registros de todas as unidades.
                            $qb = $useModels ? $__eloquent : $__eloquent->toBase();

                            if ($useModels && ($with = ModelOptionsLoader::eagerPathsFor($__eloquent->getModel(), $displayChains))) {
                                $qb->with($with); // sem N+1 no label
                            }

                            if ($depValue !== '' && $column !== '') {
                                $qb->where($column, $depValue);
                            }
                            if ($q !== '' && ($searchColumns || ($useModels && $chainColumns))) {
                                $qb->where(function ($w) use ($searchColumns, $chainColumns, $terms, $useILike, $useLowerFn, $useModels) {
                                    $first = true;
                                    foreach ($searchColumns as $col) {
                                        foreach ($terms as $t) {
                                            self::applyLike($w, $col, $t, $useILike, $useLowerFn, $first);
                                            $first = false;
                                        }
                                    }
                                    if ($useModels) {
                                        $first = self::applyChainLike($w, $chainColumns, $terms, $useILike, $useLowerFn, $first);
                                    }
                                });
                            }
                            if ($order !== '') {
                                $qb->orderBy($order, $orderDir);
                            }
                            $collection = $qb->limit($limit)->get();
                        }

                        foreach ($collection as $object) {
                            $k = $object->{$keyField} ?? null;
                            if ($isTemplate || $displayChain) {
                                // display nu em chain vira token pra reusar o
                                // resolvedor de máscara (que sabe percorrer relação)
                                $v = ModelOptionsLoader::mask($object, $displayChain ? '{' . $display . '}' : $display);
                            } else {
                                $v = (string) ($object->{$display} ?? '');
                            }
                            if ($k !== null && $v !== '') {
                                $results[] = ['value' => (string)$k, 'text' => $v];
                            }
                        }
                    } catch (\Throwable $e) {
                        // Lista vazia para o usuário, como antes; o motivo vai
                        // para o log em vez de sumir (fórum #41).
                        $results = [];
                        $error = \Mad\Form\OptionsLoadError::handle($e, 'mad-db*-search-field (busca)', [
                            'model'    => $model !== '' ? $model : 'consulta (:query)',
                            'database' => $database,
                            'display'  => $display,
                            'order_by' => $order,
                        ]);
                    }
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode($error !== null ? ['results' => $results, 'error' => $error] : ['results' => $results]);
    }

    /** Aplica LIKE/ILIKE num Query\Builder (caminho :query/derived table). */
    private static function applyLike($w, string $col, string $term, bool $useILike, bool $useLowerFn, bool $first): void
    {
        $like = "%{$term}%";
        if ($useILike) {
            $first ? $w->where($col, 'ilike', $like) : $w->orWhere($col, 'ilike', $like);
        } elseif ($useLowerFn) {
            $sql = "lower({$col}) like ?";
            $first ? $w->whereRaw($sql, [strtolower($like)]) : $w->orWhereRaw($sql, [strtolower($like)]);
        } else {
            $first ? $w->where($col, 'like', $like) : $w->orWhere($col, 'like', $like);
        }
    }

    /**
     * Sanitiza a lista de colunas de busca vinda do token.
     *
     * Aceita array ou csv. Duas formas SOBREVIVEM, e só elas:
     *   - coluna da própria tabela: [A-Za-z0-9_.] ('nome', 'pessoa.nome');
     *   - chain de relação: 'estado->sigla' ([A-Za-z0-9_] em cada segmento).
     * Qualquer outra coisa é DESCARTADA (antes virava um nome mutilado —
     * 'estado->sigla' saía 'estadosigla', coluna inexistente → SQL inválido →
     * lista vazia em silêncio). A separação plain/chain acontece depois, em
     * {@see plainOnly()}/{@see chainOnly()}: o whereRaw do caminho MySQL
     * interpola o identificador e só pode receber coluna plain.
     *
     * @param  mixed $cols
     * @return array<int,string>
     */
    private static function sanitizeColumns($cols): array
    {
        if (is_string($cols)) {
            $cols = explode(',', $cols);
        }
        if (! is_array($cols)) {
            return [];
        }

        $out = [];
        foreach ($cols as $col) {
            $col = trim((string) $col);
            if ($col === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.]+$/', $col)
                || preg_match('/^[A-Za-z0-9_]+(?:->[A-Za-z0-9_]+)+$/', $col)) {
                $out[] = $col;
            }
        }

        return $out;
    }

    /**
     * Colunas da PRÓPRIA tabela (sem chain) — as únicas que podem ir pro
     * where()/whereRaw do LIKE.
     *
     * @param  array<int,string> $cols
     * @return array<int,string>
     */
    private static function plainOnly(array $cols): array
    {
        return array_values(array_filter($cols, static fn ($c) => strpos($c, '->') === false));
    }

    /**
     * Colunas em forma de chain de relação — viram whereHas, nunca where direto.
     *
     * @param  array<int,string> $cols
     * @return array<int,string>
     */
    private static function chainOnly(array $cols): array
    {
        return array_values(array_filter($cols, static fn ($c) => strpos($c, '->') !== false));
    }

    /**
     * Aplica o LIKE das colunas de chain como whereHas na relação (mesmo
     * desenho do filtro de coluna do grid: `_applyColWhere`).
     *
     * Só 1 nível ('estado->sigla') e só relação DECLARADA no model — chain mais
     * profundo ou relação ausente é fail-closed: não filtra, não quebra a busca
     * (o pior resultado possível seria SQL inválido → lista vazia calada, que é
     * exatamente o bug que isto conserta).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $w
     * @param  array<int,string> $chainColumns
     * @param  array<int,string> $terms
     * @return bool novo valor de $first
     */
    private static function applyChainLike($w, array $chainColumns, array $terms, bool $useILike, bool $useLowerFn, bool $first): bool
    {
        if (! $w instanceof \Illuminate\Database\Eloquent\Builder) {
            return $first;
        }
        $model = $w->getModel();

        foreach ($chainColumns as $chain) {
            $parts = explode('->', $chain);
            if (count($parts) !== 2) {
                continue; // chain profundo: fail-closed
            }
            [$rel, $col] = $parts;

            $method = null;
            foreach (array_unique([$rel, Str::camel($rel)]) as $candidate) {
                if (method_exists($model, $candidate)) {
                    $method = $candidate;
                    break;
                }
            }
            if ($method === null) {
                continue; // sem relação declarada: fail-closed
            }

            $inner = static function ($sub) use ($col, $terms, $useILike, $useLowerFn) {
                $sub->where(static function ($s) use ($col, $terms, $useILike, $useLowerFn) {
                    $innerFirst = true;
                    foreach ($terms as $t) {
                        self::applyLike($s, $col, $t, $useILike, $useLowerFn, $innerFirst);
                        $innerFirst = false;
                    }
                });
            };

            try {
                $first ? $w->whereHas($method, $inner) : $w->orWhereHas($method, $inner);
            } catch (\Throwable $e) {
                continue; // método existe mas não é relação
            }
            $first = false;
        }

        return $first;
    }

    /**
     * Termos do LIKE. Além do termo digitado (espaço → '%'), adiciona a variante
     * só-dígitos quando o usuário digita documento/telefone mascarado
     * ('11.222.333/0001-81' → '11222333000181') e a coluna guarda só dígitos.
     *
     * @return array<int,string>
     */
    private static function searchTerms(string $q): array
    {
        $terms  = [str_replace(' ', '%', $q)];
        $digits = preg_replace('/\D+/', '', $q);
        if ($digits !== '' && strlen($digits) >= 3 && ! in_array($digits, $terms, true)) {
            $terms[] = $digits;
        }

        return $terms;
    }

    /**
     * Extrai as colunas da PRÓPRIA tabela de um template display — as que podem
     * ir pro LIKE.
     *
     * Ex: '{nome} - {documento}'      => ['nome', 'documento']
     * Ex: '{nome} | {estado->sigla}'  => ['nome']          (chain fora)
     * Ex: '{estado->sigla}'           => []                (nada pra buscar aqui)
     * Ex: 'nome'                      => ['nome']
     *
     * O regex antigo ('/\{(\w+)\}/') não casava '{estado->sigla}': num template
     * misto a chain sumia calada, e num template SÓ de chain o fallback devolvia
     * ['{estado->sigla}'] — o literal com chaves ia como coluna pro where, a SQL
     * estourava e o catch devolvia lista VAZIA sem log. Nunca deixe um '{…}'
     * virar coluna.
     *
     * @return array<int,string>
     */
    private static function extractColumns(string $display): array
    {
        if (preg_match_all('/\{([\w>-]+)\}/', $display, $matches)) {
            return self::plainOnly($matches[1]);
        }

        // Display nu: só é coluna se não for chain nem trouxer chave solta.
        $display = trim($display);
        if ($display === '' || strpos($display, '->') !== false || strpos($display, '{') !== false) {
            return [];
        }

        return [$display];
    }

    /** Nunca renderiza — endpoint estatico puro. */
    protected function view(): string|array
    {
        return '';
    }
}
