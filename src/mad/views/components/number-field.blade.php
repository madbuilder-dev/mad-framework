@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'prefix' => '', 'suffix' => '', 'placeholder' => '', 'min' => '', 'max' => '', 'step' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'emptyAs' => 'null', 'align' => ''])
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $readonly  = !empty($readonly);
    // Registro > prop `value` (default do dev) — regra em \Mad\Support\MadFieldValue.
    // Prepend preservando atributos do caller — sobrescrever descartava handlers.
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value);
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    $hasPrefix = !empty($prefix);
    $hasSuffix = !empty($suffix);
    // `align` alinha o valor digitado (default: esquerda). Valor fora da lista é ignorado.
    $alignCls  = in_array($align, ['center', 'right'], true) ? ' mad-text-' . $align : '';
    \Mad\Form\MadFormRegistry::register($name, 'number', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs !== 'null' ? $emptyAs : '',
        'min'      => $min,
        'max'      => $max,
        'step'     => $step,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group">
        @if($hasPrefix)
            <span class="mad-input-addon">{{ $prefix }}</span>
        @endif
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="number"
            class="mad-input{{ $alignCls }}{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            @if($min !== '') min="{{ $min }}" @endif
            @if($max !== '') max="{{ $max }}" @endif
            @if($step !== '') step="{{ $step }}" @endif
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            {!! $attrs !!}
        >
        @if($hasSuffix)
            <span class="mad-input-addon mad-input-addon-r">{{ $suffix }}</span>
        @endif
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
