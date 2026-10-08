@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'options' => [], 'selected' => [], 'placeholder' => 'Selecione...', 'min-length' => 0, 'max-size' => 0, 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => '', 'noResultsCreateAction' => '', 'noResultsCreateLabel' => 'Cadastrar novo', 'noResultsCreateIcon' => 'plus', 'noResultsCreateClass' => 'mad-btn mad-btn-primary mad-btn-sm', 'noResultsQuickRegisterAction' => '', 'noResultsQuickRegisterLabel' => 'Adicionar', 'noResultsQuickRegisterIcon' => 'check', 'noResultsQuickRegisterClass' => 'mad-btn mad-btn-success mad-btn-sm', 'noResultsQuickFields' => [], 'noResultsMessage' => ''])
@php
    // Lista de objetos [['value' => …, 'label' => …]] vira [valor => rótulo] (\Mad\Support\MadItems).
    if (is_array($options)) { $options = \Mad\Support\MadItems::normalize($options); }
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $maxSize   = (int)($maxSize   ?? 0);
    $minLength = (int)($minLength ?? 0);
    // Auto-resolve selected do MadRenderContext (MadForm fill)
    if (empty($selected) && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx)) {
            $selected = $_ctx[$name];
        }
    }
    // Normaliza para lista de strings. O MadWire devolve a seleção como JSON
    // ('["5","4"]'): com explode() o redesenho da tela perdia todas as marcas.
    $selected = \Mad\Form\MadForm::selectionKeys($selected, ',');
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    // Auto-load selected from pivot table (mode=table). O `name` vai junto: o
    // formulário guarda o que este campo entregou marcado, e o Salvar só
    // desmarca o que consta lá (ver MadForm::pivotLoaded / pivotShown).
    $__pivotNotice = null;
    if ($mode === 'table' && empty($selected) && $pivotModel && $itemKey) {
        $selected = \Mad\Component\MadRenderContext::loadPivotSelected($pivotModel, $foreignKey, $itemKey, $database, $name);
        $__pivotNotice = \Mad\Component\MadRenderContext::pivotLoadNotice($name);
    }
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
        class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
        data-mad-multiselect
        data-max-size="{{ $maxSize }}"
        data-min-length="{{ $minLength }}"
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
