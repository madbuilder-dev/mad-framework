@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'options' => [], 'items' => [], 'value' => [], 'max' => 0, 'placeholder' => 'Digite e pressione Enter...', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => ''])
@php
    // alias: o editor visual grava :items — aceitar os dois nomes
    // (drift editor-runtime, auditoria 14/jul/2026)
    if (empty($options) && !empty($items)) { $options = $items; }
    // Lista posta pelo código (`$this->form->setItems()`) vence a do Blade: é a
    // que o reload_multi_entry mostrou (fw#228).
    $options = \Mad\Support\MadItems::fromForm((string) $name) ?? $options;
    // Lista de objetos [['value' => …, 'label' => …]] vira [valor => rótulo] (\Mad\Support\MadItems).
    if (is_array($options)) { $options = \Mad\Support\MadItems::normalize($options); }
    $required = !empty($required);
    $disabled = !empty($disabled);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $max      = (int)$max;
    // Sem `value` na tag, vale o registro aberto (MadForm fill), como nos
    // outros campos de seleção múltipla — antes a edição abria vazia.
    if (empty($value) && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx)) {
            $value = $_ctx[$name];
        }
    }
    // Lista de strings: a coluna por vírgula, a lista JSON que o navegador
    // devolve ('["pt","es"]') ou um array.
    $value  = \Mad\Form\MadForm::selectionKeys($value, ',');
    // "Valor padrão" (`default`, lista separada por vírgula): só no cadastro
    // novo e sem `value` escrito na tag.
    $value  = \Mad\Support\MadFieldValue::withDefaultSelection((string) $name, $value, $default ?? null);
    $create = empty($options); // criação livre se não houver opções fixas
    \Mad\Form\MadFormRegistry::register($name, 'multi-entry', [
        'label'    => strip_tags($label),
        'required' => $required,
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
        class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
        data-mad-model="{{ $name }}"
        data-mad-multientry
        data-max="{{ $max }}"
        data-create="{{ $create ? '1' : '0' }}"
        data-placeholder="{{ $placeholder }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        {!! $attrs !!}
    >
        @foreach($options as $optKey => $optLabel)
            <option value="{{ $optKey }}"
                @if(in_array((string)$optKey, $value)) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
        @foreach($value as $val)
            @if(!array_key_exists($val, $options))
                <option value="{{ $val }}" selected>{{ $val }}</option>
            @endif
        @endforeach
    </select>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
