@php
    /**
     * mad-dbmulti-search-field — Select multiplo com busca AJAX no banco.
     *
     * Busca multipla de banco. MAD Select multi com load() remoto:
     * usuario digita → debounced fetch ao MadDbSearchService → retorna options.
     * Suporta mode comma (CSV) e mode table (pivot 1:N) com auto-save via form->save().
     *
     * Props DB:
     *   model       string   Classe do model Eloquent (obrigatorio)
     *   database    string   Conexao (padrao: main_database)
     *   key         string   Campo da PK (padrao: 'id')
     *   display     string   Campo a exibir; suporta template '{nome} - {documento}'
     *   search-columns string|array  Colunas pesquisadas no LIKE (csv ou array).
     *                                Default: as colunas do display.
     *   order-by    string   Campo para ordenar
     *   filters     array           Atalho: [['field','op','val'], ...]
     *   depends-on  string   Campo pai para cascata automatica
     *   depends-column string  Coluna do model a filtrar pelo valor do pai
     *   min-length  int      Chars minimos para disparar busca (padrao: 3)
     *
     * Props multi-search:
     *   name        string   Nome do campo (obrigatorio, envia como name[])
     *   label       string   Label do campo
     *   selected    array/string   IDs pre-selecionados
     *   placeholder string   Texto do placeholder
     *   max-size    int      Maximo de itens (0 = ilimitado)
     *   mode        string   comma | table
     *   pivot-model string   Model Eloquent da pivot (mode=table)
     *   foreign-key string   Coluna FK do registro pai (mode=table; padrão: singular da tabela do pai + _id)
     *   item-key    string   Coluna FK do item selecionado (mode=table)
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro
     *   required    bool
     *   disabled    bool
     *   attrs       string   Attrs extras
     *   id          string   ID do elemento
     */

    $name          = $name          ?? '';
    $width         = $width         ?? '';
    $maxWidth      = $maxWidth      ?? '';
    $label         = $label         ?? '';
    $database      = $database      ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model         = $model         ?? '';
    $keyField      = $key           ?? 'id';
    $display       = $display       ?? 'nome';
    // Ver dbunique-search-field: colunas do LIKE server-side; vazio = display.
    $searchColumns = $searchColumns ?? '';
    $searchColumns = array_values(array_filter(array_map(
        static fn ($c) => preg_replace('/[^A-Za-z0-9_.]/', '', trim((string) $c)),
        is_array($searchColumns) ? $searchColumns : explode(',', (string) $searchColumns)
    ), static fn ($c) => $c !== ''));
    $orderBy       = $orderBy       ?? '';
    // Direção do `order-by`. O painel do builder já escrevia `order="desc"` e
    // nada acontecia: as options saíam sempre ASC. Valor fora do par cai em
    // asc no `itemsFromQuery` / no serviço de busca, que normalizam.
    $orderDir      = $order         ?? '';
    $filters       = $filters       ?? [];
    $dependsOn     = $dependsOn     ?? '';
    $dependsColumn = $dependsColumn ?? '';
    if ($dependsOn && !$dependsColumn) $dependsColumn = $dependsOn;
    $minLength     = (int)($minLength ?? 3);
    $selected      = $selected      ?? [];
    $placeholder   = $placeholder   ?? 'Digite para buscar...';
    $maxSize       = (int)($maxSize   ?? 0);
    $mode          = $mode          ?? 'comma';
    $pivotModel    = $pivotModel    ?? '';
    $foreignKey    = $foreignKey    ?? '';
    $itemKey       = $itemKey       ?? '';
    $hint          = $hint          ?? '';
    $error         = $error         ?? '';
    $required      = !empty($required);
    $disabled      = !empty($disabled);
    $attrs         = $attrs         ?? '';
    $noResultsCreateAction        = $noResultsCreateAction        ?? '';
    $noResultsCreateLabel         = $noResultsCreateLabel         ?? 'Cadastrar novo';
    $noResultsCreateIcon          = $noResultsCreateIcon          ?? 'plus';
    $noResultsCreateClass         = $noResultsCreateClass         ?? 'mad-btn mad-btn-primary mad-btn-sm';
    $noResultsQuickRegisterAction = $noResultsQuickRegisterAction ?? '';
    $noResultsQuickRegisterLabel  = $noResultsQuickRegisterLabel  ?? 'Adicionar';
    $noResultsQuickRegisterIcon   = $noResultsQuickRegisterIcon   ?? 'check';
    $noResultsQuickRegisterClass  = $noResultsQuickRegisterClass  ?? 'mad-btn mad-btn-success mad-btn-sm';
    $noResultsQuickFields         = $noResultsQuickFields         ?? [];
    $noResultsMessage             = $noResultsMessage             ?? '';
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError      = !empty($error);
    $reqStar       = $required ? ' <span class="mad-required">*</span>' : '';

    // Auto-resolve selected do MadRenderContext (MadForm fill)
    if (empty($selected) && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx)) {
            $selected = $_ctx[$name];
        }
    }

    // Auto-load selected from pivot table (mode=table). O `name` vai junto: o
    // formulário guarda o que este campo entregou marcado, e o Salvar só
    // desmarca o que consta lá (ver MadForm::pivotLoaded / pivotShown).
    $__pivotNotice = null;
    if ($mode === 'table' && empty($selected) && $pivotModel && $itemKey) {
        $selected = \Mad\Component\MadRenderContext::loadPivotSelected($pivotModel, $foreignKey, $itemKey, $database, $name);
        $__pivotNotice = \Mad\Component\MadRenderContext::pivotLoadNotice($name);
    }

    // Normaliza para lista de strings. O MadWire devolve a seleção como JSON
    // ('["5","4"]'): com explode() o redesenho da tela perdia todas as marcas.
    $selected = \Mad\Form\MadForm::selectionKeys($selected, ',');
    // "Valor padrão" (`default`, lista separada por vírgula): só no
    // cadastro novo e com a seleção vazia — ANTES de anotar o que vai marcado
    // para o navegador, que é a base do Salvar.
    $selected = \Mad\Support\MadFieldValue::withDefaultSelection((string) $name, $selected, $default ?? null);

    \Mad\Form\MadFormRegistry::register($name, 'db-multi-search', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'mode'       => $mode,
        'pivotModel' => $pivotModel,
        'foreignKey' => $foreignKey,
        'itemKey'    => $itemKey,
        'database'   => $database,
        // De onde saem as opções: a marca nova é conferida, no Salvar, na consulta
        // deste Model — ou, com `:query` própria, na consulta do código da tela,
        // como ela era ao desenhar o campo (só vai para o estado da tela). Com
        // "Cadastrar novo"/"Adicionar", o item novo pode ficar fora da consulta própria.
        'optionsSource' => $mode === 'manual' ? '' : (\Mad\Database\QuerySource::isQuery($query ?? null)
            ? ((empty($noResultsCreateAction) && empty($noResultsQuickRegisterAction)) ? ['query' => $query, 'key' => $keyField] : '')
            : ($model ? ['model' => $model, 'key' => $keyField] : '')),
    ]);

    // Fonte do filtro → SEMPRE compila pra query_sql/query_bindings (parametrizado).
    // Aceita :query (builder) e :filters (DSL), compilados via QuerySource no render.
    $query = $query ?? null;
    $querySql = '';
    $queryBindings = [];
    // Falha ao montar a busca (model que não resolve, filtro inválido) ou ao
    // pré-carregar o rótulo do valor salvo: o campo segue como antes para o
    // usuário final, o motivo vai para o log e, com APP_DEBUG, aparece no
    // próprio campo (fórum #41) — ver \Mad\Form\OptionsLoadError.
    $__optError   = null;
    $__optMissing = [];
    $__optCtx     = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $display,
        'order_by' => $orderBy ?? '',
    ];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        [$querySql, $queryBindings] = \Mad\Database\QuerySource::compileSql($query);
        $database = \Mad\Database\QuerySource::connectionName($query) ?: $database;
    } elseif ($model) {
        try {
            $__m  = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__qb = $__m::query();
            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($__qb, $filters);
            }
            [$querySql, $queryBindings] = \Mad\Database\QuerySource::compileSql($__qb);
        } catch (\Throwable $e) {
            $querySql = '';
            $queryBindings = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbmulti-search-field', $__optCtx);
        }
    }

    // Token AJAX criptografado
    $searchToken = '';
    if ($model || $querySql !== '') {
        $searchToken = \Mad\Http\MadStateCrypt::encryptFor('db-search', [
            'database'       => $database,
            'model'          => $model,
            'key'            => $keyField,
            'display'        => $display,
            'search_columns' => $searchColumns,
            'order'          => $orderBy,
            'order_dir'      => $orderDir,
            'column'         => $dependsColumn,
            'query_sql'      => $querySql,
            'query_bindings' => $queryBindings,
            'limit'          => 500,
        ]);
    }

    // Token depends-on
    $dependsToken = '';
    if ($dependsOn) {
        // Marcador de cascata (o JS só confere que existe) — nenhum serviço aceita.
        $dependsToken = \Mad\Http\MadStateCrypt::encryptFor('search-depends', [
            'database'       => $database,
            'model'          => $model,
            'key'            => $keyField,
            'display'        => $display,
            'order'          => $orderBy,
            'order_dir'      => $orderDir,
            'column'         => $dependsColumn,
            'query_sql'      => $querySql,
            'query_bindings' => $queryBindings,
        ]);
    }

    // No-results block
    $noResultsAttrs = \Mad\Form\MadNoResultsHelper::buildAttrs([
        'name'         => $name,
        'model'        => $model,
        'database'     => $database,
        'key'          => $keyField,
        'display'      => $display,
        'createAction' => $noResultsCreateAction,
        'createLabel'  => $noResultsCreateLabel,
        'createIcon'   => $noResultsCreateIcon,
        'createClass'  => $noResultsCreateClass,
        'quickAction'  => $noResultsQuickRegisterAction,
        'quickLabel'   => $noResultsQuickRegisterLabel,
        'quickIcon'    => $noResultsQuickRegisterIcon,
        'quickClass'   => $noResultsQuickRegisterClass,
        'quickFields'  => $noResultsQuickFields,
        'message'      => $noResultsMessage,
    ]);

    // Pre-carregar labels dos valores selecionados (para onEdit)
    $preloadOptions = [];
    if (!empty($selected) && $model) {
        try {
            $__pm = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $preloadOptions = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $__pm::query()->whereIn($keyField, (array) $selected), $keyField, $display, null, 'asc', $__optMissing
            );
        } catch (\Exception $e) {
            $preloadOptions = [];
            $__optError ??= \Mad\Form\OptionsLoadError::handle($e, 'mad-dbmulti-search-field', $__optCtx);
        }
    }
    $__optError ??= \Mad\Form\OptionsLoadError::handleMissing($__optMissing, 'mad-dbmulti-search-field', $__optCtx);
    $__optError ??= $__pivotNotice;
    // (as opções desenhadas aqui são as dos itens selecionados cujo rótulo carregou)
    // mode=table: o que vai MARCADO para o navegador é a base do Salvar.
    if ($mode === 'table' && $pivotModel && $itemKey) {
        \Mad\Component\MadRenderContext::pivotRendered($name, $selected, array_keys($preloadOptions), $pivotModel, $foreignKey, $itemKey);
    }
    // Gravação na própria coluna (por vírgula): o que vai MARCADO para o
    // navegador é a base do Salvar — o item da coluna que a lista não mostra
    // não sai dela (ver MadForm::selectionShown).
    if ($mode !== 'table' && $mode !== 'manual') {
        \Mad\Component\MadRenderContext::selectionRendered($name, $selected, array_keys($preloadOptions));
    }
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <select
        id="{{ $id }}"
        name="{{ $name }}[]"
        multiple
        class="{{ $hasError ? 'mad-input-error' : '' }}"
        data-mad-dbmultisearch
        data-mad-search-token="{{ $searchToken }}"
        data-min-length="{{ $minLength }}"
        data-max-size="{{ $maxSize }}"
        data-placeholder="{{ $placeholder }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($dependsOn)
            data-mad-depends="{{ $dependsOn }}"
            data-mad-dep-token="{{ $dependsToken }}"
        @endif
        {!! $noResultsAttrs !!}
        {!! $attrs !!}
    >
        @foreach($preloadOptions as $optKey => $optLabel)
            <option value="{{ $optKey }}"
                @if(in_array((string)$optKey, $selected)) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
    </select>
    @include('components.partials.options-error', ['optionsError' => $__optError])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
