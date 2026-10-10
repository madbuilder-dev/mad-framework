<?php

namespace Mad\Form;

use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * ModelOptionsLoader — carregador de options (key => label) a partir de um model.
 *
 * Resolve o backlog F2-07: substitui TDBCombo::getItemsFromModel como fonte
 * única dos componentes db-* (dbcombo, dbselect, dbradio, checklists, ...),
 * MadForm::setItems e FieldListColumn.
 *
 * Carrega via Eloquent: query nativa (Query Builder); a connection vem do
 * próprio model.
 *
 * Labels aceitam máscara de render: '{name} ({code})'.
 */
class ModelOptionsLoader
{
    /**
     * @param string         $model       FQCN do model
     * @param string         $key         Coluna da chave
     * @param string         $value       Coluna do label OU máscara '{col} ...'
     * @param string|null    $ordercolumn Coluna de ordenação (default: $key)
     * @param string         $orderDir    Direção ('asc'|'desc'; default asc — retrocompatível)
     * @param array|null     $missing     (saída) colunas do display que a consulta não trouxe —
     *                                    ver {@see itemsFromQuery()}
     * @return array<int|string, string>
     */
    public static function items($model, $key, $value, $ordercolumn = null, string $orderDir = 'asc', ?array &$missing = null): array
    {
        $key   = trim((string) $key);
        $value = trim((string) $value);

        foreach (['model' => $model, 'key' => $key, 'value' => $value] as $param => $val) {
            if (empty($val)) {
                throw new Exception("The parameter ({$param}) of " . __CLASS__ . " is required");
            }
        }

        // Builder-first: monta a query do model e delega ao caminho ÚNICO
        // itemsFromQuery (builder-native, sem caminho legado). Preserva a
        // ordenação default: não-mask ordena por $value; mask sem ordercolumn
        // cai no asort por label dentro do itemsFromQuery.
        $class = self::resolveModelClass($model);
        $query = $class::query();

        $isMask = strpos($value, '{') !== false;
        $order  = $ordercolumn;
        if ($order === null && ! $isMask) {
            $order = $value;
        }

        return self::itemsFromQuery($query, $key, $value, $order, $orderDir, $missing);
    }

    /**
     * Carrega options a partir de um Eloquent/Query Builder JÁ filtrado.
     *
     * O builder fornece o WHERE/JOIN; o componente só lê key/label. Global
     * scopes (soft-delete, tenant) são preservados via
     * {@see \Mad\Database\QuerySource::toBaseQuery()} — que chama toBase()
     * (= applyScopes()) — então registro soft-deletado NÃO vaza. Nunca muta a
     * fonte (trabalha em clone), logo o mesmo builder serve a vários componentes.
     *
     * Máscara com chain ('{nome} | {estado->sigla}') sobre fonte Eloquent NÃO
     * passa por toBase(): itera os models (get() aplica applyScopes() igual) e
     * eager-loada os prefixos do chain. Ver o bloco no corpo do método.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param  string      $key         Coluna da chave
     * @param  string      $value       Coluna do label OU máscara '{col} ...'
     * @param  string|null $ordercolumn Coluna de ordenação (opcional; default: ordem do builder)
     * @param  string      $orderDir    Direção do order ('asc'|'desc'; default asc — retrocompatível)
     * @param  array|null  $missing     (saída) colunas do display que a consulta NÃO trouxe, lidas
     *                                  no 1º registro. Coluna inexistente não lança — o SELECT é
     *                                  `*` e o rótulo só sai em branco —, então quem quer avisar
     *                                  o erro (\Mad\Form\OptionsLoadError) precisa desta lista.
     *                                  Tabela vazia = [] (não há registro para conferir).
     * @return array<int|string, string>
     */
    public static function itemsFromQuery($query, string $key, string $value, ?string $ordercolumn = null, string $orderDir = 'asc', ?array &$missing = null): array
    {
        $missing = [];

        if (! \Mad\Database\QuerySource::isQuery($query)) {
            throw new Exception(
                'ModelOptionsLoader::itemsFromQuery requer um Eloquent/Query Builder.'
            );
        }

        $key   = trim($key);
        $value = trim($value);
        foreach (['key' => $key, 'value' => $value] as $param => $val) {
            if ($val === '') {
                throw new Exception("The parameter ({$param}) of " . __CLASS__ . " is required");
            }
        }

        $isMask = strpos($value, '{') !== false;
        $dir    = strtolower($orderDir) === 'desc' ? 'desc' : 'asc';

        // Token com chain de relação ('{estado->sigla}') só resolve sobre MODEL:
        // toBaseQuery() chama toBase(), que devolve stdClass — linha crua, sem
        // relação nenhuma pra percorrer, e o label sai vazio. Com fonte Eloquent
        // iteramos os models e eager-loadamos os prefixos do chain (sem N+1).
        // Garantia preservada: o get() do Eloquent\Builder também roda
        // applyScopes() — os MESMOS global scopes (soft-delete/tenant) que o
        // toBase() aplicava —, então registro soft-deletado continua sem vazar.
        //
        // Fonte Query\Builder com chain segue pelo caminho stdClass: não existe
        // relação a percorrer ali. O token cai no fallback de chave achatada do
        // renderMask (alias 'estado->sigla' / 'estado_sigla' trazido pelo
        // SELECT) e, na falta dela, em ''. Limitação assumida, não regressão.
        $chains = array_merge(self::chainTokens($value), self::chainTokens($key));

        if ($chains && $query instanceof EloquentBuilder) {
            $q = clone $query; // nunca muta a fonte
            if ($ordercolumn) {
                $q->orderBy($ordercolumn, $dir);
            }
            if ($with = self::eagerPathsFor($query->getModel(), $chains)) {
                $q->with($with);
            }
            $records = $q->get();
        } else {
            // toBaseQuery() aplica global scopes (soft-delete/tenant) e clona a fonte.
            $q = \Mad\Database\QuerySource::toBaseQuery($query);
            if ($ordercolumn) {
                $q->orderBy($ordercolumn, $dir);
            }
            $records = $q->get();
        }

        $items   = [];
        $checked = false;
        foreach ($records as $object) {
            if (! $checked) {
                $missing = self::missingDisplayColumns($object, $value);
                $checked = true;
            }
            if ($isMask) {
                $label = self::renderMask($object, $value);
            } elseif (strpos($value, '->') !== false) {
                // display nu em forma de chain ('estado->sigla', sem chaves)
                $label = self::readToken($object, $value);
            } else {
                $label = (string) ($object->{$value} ?? '');
            }

            $k = strpos($key, '->') !== false
                ? self::readToken($object, $key)
                : $object->{$key};

            $items[$k] = $label;
        }

        if ($isMask && $ordercolumn === null) {
            asort($items);
        }

        return $items;
    }

    /**
     * Rótulos de registros JÁ escolhidos — o texto que o combo do campo mostra
     * para cada chave. Usado por quem só exibe o valor (`<mad-display-field
     * model display>`, a etapa Resumo do passo a passo). Uma consulta para
     * todas as chaves, com os escopos globais do model: chave de registro
     * apagado ou de fora do escopo fica sem rótulo (o caller mostra a chave).
     *
     * @param  string        $model   FQCN, token ou nome curto do model
     * @param  string        $key     Coluna da chave ('' = a chave do model)
     * @param  string        $display Coluna do rótulo OU máscara '{col} ...'
     * @param  list<scalar>  $keys
     * @return array<string, string>  chave => rótulo (só as que existem)
     */
    public static function labelsFor(string $model, string $key, string $display, array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(fn ($k) => trim((string) $k), $keys),
            fn (string $k) => $k !== '',
        )));
        $key = trim($key);
        if ($keys === [] || trim($display) === '' || str_contains($key, '->')) {
            return [];
        }

        $query = self::resolveModelClass($model)::query();
        if ($key === '') {
            $key = $query->getModel()->getKeyName();
        }

        $labels = [];
        foreach (self::itemsFromQuery($query->whereIn($key, $keys), $key, $display) as $k => $label) {
            $labels[(string) $k] = (string) $label;
        }

        return $labels;
    }

    /**
     * Resolve o nome do model para FQCN via {@see \Mad\Database\ModelRegistry}.
     *
     * Aceita FQCN explícito ('App\Models\Iam\User'), token DomainEntity
     * ('IamUser') ou basename de entidade ('User'/'Message'). Fallback legado
     * prefixa 'App\Models\' ao short name se o registry não achar.
     */
    public static function resolveModelClass(string $model): string
    {
        // FQCN explícito que já existe: retorna direto (dispensa registry).
        if (class_exists($model)) {
            if (is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class)) {
                return $model;
            }
            // Classe existe mas NÃO é um Model Eloquent — tipicamente passaram
            // um componente MAD (ex: FQCN de um <mad-kanban>/<mad-dashboard>)
            // onde se espera o model dos dados. Sem este guard o caller faz
            // `$class::query()` e o PHP fatala com o enigmático
            // "Call to protected method ...::query()".
            throw new Exception(
                "ModelOptionsLoader: \"{$model}\" existe mas não é um Model "
                . "Eloquent (esperado App\\Models\\...). Passe o model dos "
                . "dados, não um componente/classe. "
                . "Ex: baseQuery(App\\Models\\Cliente::class)."
            );
        }

        $resolved = \Mad\Database\ModelRegistry::resolve($model);
        if ($resolved !== null) {
            return $resolved;
        }

        // fallback legado: short name -> App\Models\<short> (rede caso o scan falhe)
        $short     = ltrim(strrchr($model, '\\') ?: $model, '\\');
        $candidate = 'App\\Models\\' . $short;
        if (class_exists($candidate)) {
            return $candidate;
        }

        throw new Exception(
            "ModelOptionsLoader: model \"{$model}\" não encontrado "
            . "(tentado \"{$model}\", registry e \"{$candidate}\")."
        );
    }

    /**
     * Colunas SIMPLES do display (nome nu ou tokens `{col}` da máscara) que o
     * registro não tem. Chain (`{estado->sigla}`) fica de fora: ele resolve por
     * relação/convenção de FK, não por coluna do SELECT.
     *
     * Lê o token exatamente como o renderMask (só o `$` da frente sai): um
     * `{ nome }` com espaço ou um id-ref cru `{entity_column_id:12}` também saem
     * em branco no rótulo, e por isso também entram aqui.
     *
     * @return array<int, string>
     */
    public static function missingDisplayColumns(object $object, string $value): array
    {
        $value = trim($value);
        $cols  = [];
        if (strpos($value, '{') !== false) {
            if (preg_match_all('/\{(.*?)\}/', $value, $m)) {
                foreach ($m[1] as $token) {
                    $prop = ltrim($token, '$');
                    if ($prop !== '' && strpos($prop, '->') === false) {
                        $cols[] = $prop;
                    }
                }
            }
        } elseif ($value !== '' && strpos($value, '->') === false) {
            $cols[] = $value;
        }

        $missing = [];
        foreach (array_unique($cols) as $col) {
            try {
                $has = $object instanceof Model
                    ? array_key_exists($col, $object->getAttributes())
                        || $object->hasGetMutator($col)
                        || $object->hasAttributeGetMutator($col)
                        || method_exists($object, $col)
                    : property_exists($object, $col) || isset($object->{$col});
            } catch (\Throwable $e) {
                $has = true; // na dúvida, não acusa
            }
            if (! $has) {
                $missing[] = $col;
            }
        }

        return $missing;
    }

    /** Renderiza máscara '{col} ({outra})' lendo atributos do model. */
    public static function mask(object $object, string $pattern): string
    {
        return self::renderMask($object, $pattern);
    }

    private static function renderMask(object $object, string $pattern): string
    {
        return (string) preg_replace_callback('/\{(.*?)\}/', function ($m) use ($object) {
            $prop = ltrim($m[1], '$');
            if (strpos($prop, '->') === false) {
                return (string) ($object->{$prop} ?? '');
            }

            return self::readToken($object, $prop);
        }, $pattern);
    }

    /**
     * Lê um token de máscara que É um chain de relação ('estado->sigla',
     * 'estado->pais->nome').
     *
     * Model: delega ao resolvedor do grid — percorre as relações com lazy-load
     * e, quando o segmento não bate em relação declarada, cai na convenção de
     * FK (`<segmento>_id` → ModelRegistry → find(), cache por request).
     *
     * stdClass (fonte toBase()/derived table): não há relação pra percorrer.
     * Aceita a chave já ACHATADA pelo SELECT — o alias literal ('estado->sigla')
     * ou a forma com underscore ('estado_sigla') — e, na falta das duas,
     * devolve '' (sem warning de propriedade indefinida).
     */
    private static function readToken(object $object, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($object instanceof Model) {
            return \Mad\Grid\GridRenderHelpers::resolveTemplate('{' . $path . '}', $object);
        }

        foreach ([$path, str_replace('->', '_', $path)] as $flat) {
            if (isset($object->{$flat})) {
                return (string) $object->{$flat};
            }
        }

        return '';
    }

    /**
     * Tokens com chain de relação de uma máscara/display.
     *
     * '{nome} | {estado->sigla}' => ['estado->sigla']
     * 'estado->sigla'            => ['estado->sigla']   (display nu, sem chaves)
     * '{nome}'                   => []
     *
     * @return array<int,string>
     */
    public static function chainTokens(string $pattern): array
    {
        $out = [];

        if (preg_match_all('/\{(.*?)\}/', $pattern, $m)) {
            foreach ($m[1] as $token) {
                $token = trim(ltrim($token, '$'));
                if ($token !== '' && strpos($token, '->') !== false) {
                    $out[] = $token;
                }
            }

            return array_values(array_unique($out));
        }

        // Sem chaves: o display inteiro pode ser o chain.
        $pattern = trim($pattern);
        if ($pattern !== '' && strpos($pattern, '->') !== false) {
            $out[] = $pattern;
        }

        return $out;
    }

    /**
     * Caminhos de eager-load ('estado', 'estado.pais') para os prefixos de
     * relação dos tokens com chain — evita o N+1 do lazy-load linha a linha.
     *
     * Só entra o prefixo que É relação DECLARADA (método existe, com a ponte
     * snake↔camel, e devolve uma Relation). Prefixo sem relação declarada não
     * vira with() — ele é resolvido depois, linha a linha, pelo fallback de FK
     * do GridRenderHelpers (que tem cache por request).
     *
     * @internal Reusado pelo MadDbSearchService (mesmo problema de N+1 no
     *           label do dbunique/dbmulti-search).
     *
     * @param  array<int,string> $chains
     * @return array<int,string>
     */
    public static function eagerPathsFor(Model $model, array $chains): array
    {
        $paths = [];

        foreach ($chains as $chain) {
            $segments = array_map('trim', explode('->', $chain));
            array_pop($segments); // último segmento é o ATRIBUTO, não relação

            $current = $model;
            $parts   = [];

            foreach ($segments as $segment) {
                if ($segment === '') {
                    break;
                }

                $method = null;
                foreach (array_unique([$segment, Str::camel($segment)]) as $candidate) {
                    if (method_exists($current, $candidate)) {
                        $method = $candidate;
                        break;
                    }
                }
                if ($method === null) {
                    break; // sem relação declarada → fallback de FK, sem with()
                }

                try {
                    $relation = $current->{$method}();
                } catch (\Throwable $e) {
                    break;
                }
                if (! $relation instanceof Relation) {
                    break; // método homônimo que não é relação
                }

                $parts[] = $method;
                $current = $relation->getRelated();
            }

            if ($parts) {
                $paths[] = implode('.', $parts);
            }
        }

        return array_values(array_unique($paths));
    }
}
