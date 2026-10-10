@php
    /**
     * mad-dbselect-check-field — Multi-select com checkboxes no dropdown e auto-query do banco.
     *
     * Multi-select de banco com checkboxes. Combina:
     *  - Modo check do MAD Select (cada option como checkbox)
     *  - Display condensado "Item1, Item2, Item3 +X itens"
     *  - Auto-query via Eloquent/QuerySource (mesmo padrao do dbcombo-field)
     *
     * Props DB:
     *   model         string    Classe do model Eloquent (obrigatorio)
     *   database      string    Conexao (padrao: main_database)
     *   key           string    Campo PK (padrao: 'id')
     *   display       string    Campo de exibicao. Aceita template {nome} ({sigla})
     *   order-by      string    Campo para ordenar (padrao: igual a display)
     *   query         Builder   Eloquent/Query Builder pronto (caminho :query)
     *   filters       array     Atalho [['campo','op','val'], ...]
     *
     * Props select-check (+ persistencia):
     *   name          string    Nome do campo (obrigatorio, envia como name[])
     *   label         string    Label
     *   selected      array     IDs pre-selecionados
     *   max-display   int       Itens visiveis antes de condensar (padrao: 3)
     *   placeholder   string
     *   max-size      int       Maximo de selecoes (0 = ilimitado)
     *   mode          string    'comma' (padrao) ou 'table'
     *   pivot-model   string    Model Eloquent da pivot (mode=table)
     *   foreign-key   string    FK do pai (mode=table; padrão: singular da tabela do pai + _id)
     *   item-key      string    FK do item (mode=table)
     *   hint          string    Texto de ajuda
     *   error         string    Mensagem de erro
     *   required      bool
     *   disabled      bool
     *   attrs         string    Attrs extras
     *   id            string    ID do elemento
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model       = $model       ?? '';
    $keyField    = $key         ?? 'id';
    $display     = $display     ?? 'nome';
    $orderBy     = $orderBy     ?? '';
    // Direção do `order-by`. O painel do builder já escrevia `order="desc"` e
    // nada acontecia: as options saíam sempre ASC. Valor fora do par cai em
    // asc no `itemsFromQuery` / no serviço de busca, que normalizam.
    $orderDir    = $order       ?? '';
    $filters     = $filters     ?? [];
    $query       = $query       ?? null;   // Eloquent/Query Builder (novo padrão)
    $selected    = $selected    ?? [];
    $maxDisplay  = (int)($maxDisplay ?? 3);
    $placeholder = $placeholder ?? 'Selecione...';
    $maxSize     = (int)($maxSize    ?? 0);
    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $attrs       = $attrs       ?? '';
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
    $hasError    = !empty($error);
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';

    // Auto-resolve selected do MadRenderContext
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

    // Consulta própria (`:query`), vista ANTES de `:filters` virar consulta: as
    // chaves que ela carregar são as opções que a tela oferece (ver abaixo). Com
    // "Cadastrar novo"/"Adicionar" a opção nova nasce fora dela: não há lista a conferir.
    $__ownQuery = \Mad\Database\QuerySource::isQuery($query);
    $__offers   = $__ownQuery && $mode !== 'manual' && empty($noResultsCreateAction) && empty($noResultsQuickRegisterAction);

    \Mad\Form\MadFormRegistry::register($name, 'multi-search', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'mode'       => $mode,
        'pivotModel' => $pivotModel,
        'foreignKey' => $foreignKey,
        'itemKey'    => $itemKey,
        'database'   => $database,
        // De onde saem as opções: a marca nova é conferida, no Salvar, na consulta
        // deste Model — ou, com `:query` própria, entre as opções que ela carregou
        // ao desenhar o campo (anotadas logo abaixo). Só vai para o estado da tela.
        'optionsSource' => $mode === 'manual' ? '' : ($__ownQuery
            ? ($__offers ? ['offered' => null] : '')
            : ($model ? ['model' => $model, 'key' => $keyField] : '')),
    ]);

    // :filters (array DSL) → Query Builder interno → caminho :query.
    if (!\Mad\Database\QuerySource::isQuery($query) && !empty($filters) && $model) {
        $__m   = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $__m::query();
        \Mad\Database\QuerySource::applyArrayFilters($query, $filters);
    }

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

    // Falha ao carregar: o campo continua vazio para o usuário final, o motivo
    // vai para o log e, com APP_DEBUG, aparece no próprio campo (fórum #41).
    $__optError   = null;
    $__optMissing = [];
    $__optCtx     = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $display,
        'order_by' => $orderBy,
    ];
    // Auto-query: carrega options do banco no render
    // :query (Builder) tem prioridade sobre model
    $options = [];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $query, $keyField, $display, $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
            );
        } catch (\Throwable $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbselect-check-field', $__optCtx);
        }
    } elseif ($model) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::items(
                $model, $keyField, $display,
                $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
            );
        } catch (\Exception $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbselect-check-field', $__optCtx);
        }
    }
    if ($__offers) {
        \Mad\Form\MadFormRegistry::offered($name, array_keys($options));
    }
    $__optError ??= \Mad\Form\OptionsLoadError::handleMissing($__optMissing, 'mad-dbselect-check-field', $__optCtx);
    $__optError ??= $__pivotNotice;
    // mode=table: o que vai MARCADO para o navegador é a base do Salvar.
    if ($mode === 'table' && $pivotModel && $itemKey) {
        \Mad\Component\MadRenderContext::pivotRendered($name, $selected, array_keys($options), $pivotModel, $foreignKey, $itemKey);
    }
    // Gravação na própria coluna (por vírgula): o que vai MARCADO para o
    // navegador é a base do Salvar — o item da coluna que a lista não mostra
    // não sai dela (ver MadForm::selectionShown).
    if ($mode !== 'table' && $mode !== 'manual') {
        \Mad\Component\MadRenderContext::selectionRendered($name, $selected, array_keys($options));
    }
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <select
        id="{{ $id }}"
        name="{{ $name }}[]"
        multiple
        class="mad-input mad-bsc{{ $hasError ? ' mad-input-error' : '' }}"
        data-mad-selectcheck
        data-max-display="{{ $maxDisplay }}"
        data-max-size="{{ $maxSize }}"
        data-placeholder="{{ $placeholder }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        {!! $noResultsAttrs !!}
        {!! $attrs !!}
    >
        @if($__optError !== null)
            <option value="" disabled data-mad-options-error>{{ $__optError }}</option>
        @endif
        @foreach($options as $optKey => $optLabel)
            <option value="{{ $optKey }}"
                @if(in_array((string)$optKey, $selected)) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
    </select>
    @include('components.partials.options-error', ['optionsError' => $__optError])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
