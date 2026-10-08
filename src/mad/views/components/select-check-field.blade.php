@php
    /**
     * mad-select-check-field — Multi-select com checkboxes no dropdown e display condensado.
     *
     * Multi-select com checkboxes. UI:
     * - Dropdown: cada option renderizada como checkbox (modo check do MAD Select)
     * - Control: uma linha compacta com os primeiros N itens + contador "+X itens"
     *   Ex: "Bola, Maça, Banana +4 itens"
     *
     * Para versao com auto-query do banco, usar <mad-dbselect-check-field>.
     *
     * Props:
     *   name          string    Nome do campo (obrigatorio, envia como name[])
     *   label         string    Label do campo
     *   options       array     Mapa [valor => label]
     *   selected      array     IDs pre-selecionados (aceita array ou CSV)
     *   max-display   int       Quantos items mostrar no control antes de condensar (padrao: 3)
     *   placeholder   string    Placeholder
     *   max-size      int       Maximo de selecoes (0 = ilimitado)
     *   mode          string    'comma' (padrao) ou 'table'
     *   pivot-model   string    Model Eloquent da pivot (mode=table)
     *   foreign-key   string    FK do pai (mode=table; padrão: singular da tabela do pai + _id)
     *   item-key      string    FK do item (mode=table)
     *   database      string    Conexao (mode=table)
     *   hint          string    Texto de ajuda
     *   error         string    Mensagem de erro
     *   required      bool
     *   disabled      bool
     *   attrs         string    Attrs extras
     *   id            string    ID do elemento
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $options     = $options     ?? [];
    // Lista de objetos [['value' => …, 'label' => …]] vira [valor => rótulo] (\Mad\Support\MadItems).
    if (is_array($options)) { $options = \Mad\Support\MadItems::normalize($options); }
    $selected    = $selected    ?? [];
    $maxDisplay  = (int)($maxDisplay ?? 3);
    $placeholder = $placeholder ?? 'Selecione...';
    $maxSize     = (int)($maxSize    ?? 0);
    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
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
    // mode=table: o que vai MARCADO para o navegador é a base do Salvar.
    if ($mode === 'table' && $pivotModel && $itemKey) {
        \Mad\Component\MadRenderContext::pivotRendered($name, $selected, array_keys((array) $options), $pivotModel, $foreignKey, $itemKey);
    }
    // Gravação na própria coluna (por vírgula): o que vai MARCADO para o
    // navegador é a base do Salvar — o item da coluna que a lista não mostra
    // não sai dela (ver MadForm::selectionShown).
    if ($mode !== 'table' && $mode !== 'manual') {
        \Mad\Component\MadRenderContext::selectionRendered($name, $selected, array_keys((array) $options));
    }

    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }

    \Mad\Form\MadFormRegistry::register($name, 'multi-search', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'mode'       => $mode,
        'pivotModel' => $pivotModel,
        'foreignKey' => $foreignKey,
        'itemKey'    => $itemKey,
        'database'   => $database,
    ]);

    $noResultsAttrs = \Mad\Form\MadNoResultsHelper::buildAttrs([
        'name'         => $name,
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
        @foreach($options as $optKey => $optLabel)
            <option value="{{ $optKey }}"
                @if(in_array((string)$optKey, $selected)) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
    </select>
    @include('components.partials.options-error', ['optionsError' => $__pivotNotice])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
