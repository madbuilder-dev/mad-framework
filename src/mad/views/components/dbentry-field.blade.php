@php
    /**
     * mad-dbentry-field — Input text com autocomplete AJAX do banco.
     *
     * Input de banco. Input text livre com dropdown de
     * sugestoes buscadas no banco via MadDbEntryService. O usuario pode digitar
     * livremente (o valor NAO precisa vir da lista — diferente do dbunique-search).
     *
     * Props DB:
     *   model       string   Classe do model Eloquent (obrigatorio)
     *   database    string   Conexao (padrao: main_database)
     *   column      string   Coluna a buscar e exibir nas sugestoes (obrigatorio)
     *   query       Builder|null    Query Builder pronto (filtros aplicados)
     *   filters     array           Atalho: [['field','op','val'], ...]
     *   min-length  int      Chars minimos para disparar busca (padrao: 2)
     *
     * Props input:
     *   name        string   Nome do campo (obrigatorio)
     *   label       string   Label do campo
     *   placeholder string   Placeholder
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro
     *   required    bool
     *   disabled    bool
     *   readonly    bool
     *   maxlength   string   Tamanho maximo
     *   attrs       string   Attrs extras
     *   id          string   ID do elemento
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model       = $model       ?? '';
    $column      = $column      ?? '';
    $filters     = $filters     ?? [];
    $minLength   = (int)($minLength ?? 2);
    $placeholder = $placeholder ?? '';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $readonly    = !empty($readonly);
    $maxlength   = $maxlength   ?? '';
    $attrs       = $attrs       ?? '';
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError    = !empty($error);
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';

    // mad:model + value (registro > prop `value`) — regra em \Mad\Support\MadFieldValue
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value ?? '');

    \Mad\Form\MadFormRegistry::register($name, 'input', [
        'label'     => strip_tags($label),
        'type'      => 'text',
        'required'  => $required,
        'maxlength' => $maxlength,
    ]);

    // Fonte do filtro → SEMPRE compila pra query_sql/query_bindings (parametrizado),
    // SEM serialize de objeto no token. Aceita :query (builder) e :filters (DSL),
    // compilados pra SQL parametrizado no render.
    $query = $query ?? null;
    $querySql = '';
    $queryBindings = [];
    // Falha ao montar a busca (model que não resolve, filtro inválido): o campo
    // segue como antes para o usuário final, o motivo vai para o log e, com
    // APP_DEBUG, aparece no próprio campo (fórum #41) — ver \Mad\Form\OptionsLoadError.
    $__optError = null;
    $__optCtx   = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $column,
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
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbentry-field', $__optCtx);
        }
    }

    // Token AJAX criptografado
    $searchToken = '';
    if (($model || $querySql !== '') && $column) {
        $searchToken = \Mad\Http\MadStateCrypt::encryptFor('db-entry', [
            'database'       => $database,
            'model'          => $model,
            'column'         => $column,
            'query_sql'      => $querySql,
            'query_bindings' => $queryBindings,
            'limit'          => 20,
        ]);
    }
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="text"
        class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        data-mad-dbentry
        data-mad-dbentry-token="{{ $searchToken }}"
        data-min-length="{{ $minLength }}"
        @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($readonly) readonly @endif
        {!! $attrs !!}
    >
    @include('components.partials.options-error', ['optionsError' => $__optError])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
