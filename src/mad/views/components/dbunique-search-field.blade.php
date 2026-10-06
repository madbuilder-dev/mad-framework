@php
    /**
     * mad-dbunique-search-field — Select unico com busca AJAX no banco.
     *
     * Busca unica de banco. MAD Select com load() remoto:
     * usuario digita → debounced fetch ao MadDbSearchService → retorna options.
     * A config da query (model, database, display, query) e criptografada
     * server-side e embutida no DOM como token opaco — o cliente nunca ve.
     *
     * Props:
     *   name        string   Nome do campo (obrigatorio)
     *   label       string   Label do campo
     *   model       string   Classe do model Eloquent (obrigatorio)
     *   database    string   Conexao (padrao: main_database)
     *   key         string   Campo da PK (padrao: 'id')
     *   display     string   Campo a exibir; suporta template '{nome} - {documento}'
     *   search-columns string|array  Colunas pesquisadas no LIKE (csv ou array).
     *                                Default: as colunas do display. Use quando a
     *                                busca precisa cobrir colunas que NAO aparecem
     *                                no label (ex: cnpj, telefone, email).
     *   order-by    string   Campo para ordenar
     *   query       Builder|null    Query builder Eloquent (opcional)
     *   filters     array           Atalho: [['field','op','val'], ...]
     *   depends-on  string   Campo pai para cascata automatica
     *   depends-column string  Coluna do model a filtrar pelo valor do pai
     *   min-length  int      Chars minimos para disparar busca (padrao: 3)
     *   selected    string   ID pre-selecionado
     *   placeholder string   Texto do placeholder
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro
     *   required    bool
     *   empty-as    string   Em branco grava: 'null' (padrão), 'zero' ou 'empty' ('' — comportamento antigo)
     *   disabled    bool
     *   attrs       string   Attrs extras
     *   id          string   ID do elemento
     *   create      string   'Classe::metodo' (ou 'Classe') — botão "+" ao lado da busca que abre
     *                        o cadastro; o returnToCombo() do form alvo devolve a option nova
     *   create-label string  Dica/nome acessível do botão (padrao: 'Cadastrar novo')
     *   create-icon string   Icone Lucide (padrao: 'plus')
     *   create-params array  Parametros fixos da abertura
     */

    $name          = $name          ?? '';
    $width         = $width         ?? '';
    $maxWidth      = $maxWidth      ?? '';
    $label         = $label         ?? '';
    $database      = $database      ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model         = $model         ?? '';
    $keyField      = $key           ?? 'id';
    $display       = $display       ?? 'nome';
    // Colunas do LIKE server-side. Vazio = colunas do display (comportamento
    // historico). Aceita csv ('cnpj,telefone') ou array; sanitizado aqui porque
    // o service interpola o nome da coluna em whereRaw (lower()) no MySQL.
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
    $selected      = $selected      ?? '';
    $placeholder   = $placeholder   ?? 'Digite para buscar...';
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
    $autoFill                     = $autoFill                     ?? [];   // <fill> filhos → auto-fill ao selecionar
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError      = !empty($error);
    $reqStar       = $required ? ' <span class="mad-required">*</span>' : '';

    // Auto-resolve selected do MadRenderContext
    if ($selected === '' && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx)) {
            $selected = (string)$_ctx[$name];
        }
    }
    $selected = (string)$selected;

    // Em branco grava NULL (a chave de outra tabela nunca é ''): coluna
    // inteira recusava o '' e o salvar quebrava. empty-as="empty" = antigo.
    $emptyAs = strtolower(trim((string) ($emptyAs ?? 'null'))) ?: 'null';
    \Mad\Form\MadFormRegistry::register($name, 'select', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs,
    ]);

    // Fonte do filtro → SEMPRE compila pra query_sql/query_bindings (parametrizado),
    // sem serialize de objeto no token. Aceita :query (builder Eloquent) e
    // :filters (DSL), ambos baked no SQL parametrizado no render. O
    // service re-aplica como derived table (fromRaw). toBase() baked o soft-delete.
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
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbunique-search-field', $__optCtx);
        }
    }

    // Token AJAX criptografado — toda a config da query segura
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

    // Token depends-on (para cascata — armazena parentValue no JS)
    $dependsToken = '';
    if ($dependsOn) {
        // Marcador de cascata: o JS só confere que existe (a busca usa o
        // search-token + depValue). Finalidade própria = nenhum serviço aceita.
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

    // Auto-fill (<fill>): reaproveita o query_sql/bindings já compilados acima
    // (mesmo filtro do select). Cliente só manda {token,value}; o
    // MadAutoFillService valida o value no conjunto e resolve os campos.
    $autoFillToken = '';
    if (!empty($autoFill) && ($model || $querySql !== '')) {
        $autoFillToken = \Mad\Http\MadStateCrypt::encryptFor('auto-fill', [
            'database'       => $database,
            'model'          => $model,
            'key'            => $keyField,
            'query_sql'      => $querySql,
            'query_bindings' => $queryBindings,
            'fills'          => $autoFill,
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

    // "+" ao lado da busca (`create`): cadastra e volta selecionado.
    $_createBtn = \Mad\Form\MadNoResultsHelper::createButton([
        'createAction' => is_string($create ?? null) ? $create : '',
        'createLabel'  => is_string($createLabel ?? null) ? $createLabel : '',
        'createIcon'   => is_string($createIcon ?? null) ? $createIcon : '',
        'params'       => is_array($createParams ?? null) ? $createParams : [],
        'name'         => $name,
        'model'        => $model,
        'database'     => $database,
        'key'          => $keyField,
        'display'      => $display,
    ], $disabled);

    // Pre-carregar label do valor selecionado (para onEdit)
    $preloadOptions = [];
    if ($selected !== '' && $model) {
        try {
            $__pm = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $preloadOptions = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $__pm::query()->where($keyField, '=', $selected), $keyField, $display, null, 'asc', $__optMissing
            );
        } catch (\Throwable $e) {
            $preloadOptions = [];
            $__optError ??= \Mad\Form\OptionsLoadError::handle($e, 'mad-dbunique-search-field', $__optCtx);
        }
    }
    $__optError ??= \Mad\Form\OptionsLoadError::handleMissing($__optMissing, 'mad-dbunique-search-field', $__optCtx);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    @if($_createBtn !== '')<div class="mad-combo-create-row" style="display:flex;gap:6px;align-items:flex-start;min-width:0"><div style="flex:1;min-width:0">@endif
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        class="{{ $hasError ? 'mad-input-error' : '' }}"
        data-mad-dbsearch
        data-mad-search-token="{{ $searchToken }}"
        data-min-length="{{ $minLength }}"
        data-placeholder="{{ $placeholder }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($dependsOn)
            data-mad-depends="{{ $dependsOn }}"
            data-mad-dep-token="{{ $dependsToken }}"
        @endif
        @if($autoFillToken)
            data-mad-autofill-token="{{ $autoFillToken }}"
            data-mad-autofill-fields="{{ implode(',', array_column($autoFill, 'field')) }}"
        @endif
        {!! $noResultsAttrs !!}
        {!! $attrs !!}
    >
        <option value="">{{ $placeholder }}</option>
        @foreach($preloadOptions as $optKey => $optLabel)
            <option value="{{ $optKey }}" selected>{{ $optLabel }}</option>
        @endforeach
    </select>
    @if($_createBtn !== '')</div>{!! $_createBtn !!}</div>@endif
    @include('components.partials.options-error', ['optionsError' => $__optError])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
