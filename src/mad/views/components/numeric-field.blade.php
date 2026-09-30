@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'prefix' => '', 'suffix' => '', 'placeholder' => '0,00','decimals' => 2, 'min' => '', 'max' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'emptyAs' => 'null', 'align' => ''])
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $readonly  = !empty($readonly);
    $decimals  = (int)$decimals;

    // Valor inicial: registro (MadWire) > prop `value` (default do dev) > 0.
    // Regra em \Mad\Support\MadFieldValue — antes a prop `value` era ignorada.
    $rawValue = \Mad\Support\MadFieldValue::resolveFloat((string)$name, $value ?? null);

    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    $hasPrefix = !empty($prefix);
    $hasSuffix = !empty($suffix);
    // `align` alinha o valor digitado (default: esquerda). Valor fora da lista é ignorado.
    $alignCls  = in_array($align, ['center', 'right'], true) ? ' mad-text-' . $align : '';

    $alpineCfg = json_encode(array_filter([
        'decimals' => $decimals,
        'value'    => $rawValue,
        'min'      => $min !== '' ? (float)$min : null,
        'max'      => $max !== '' ? (float)$max : null,
    ], fn($v) => $v !== null));

    \Mad\Form\MadFormRegistry::register($name, 'numeric', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs !== 'null' ? $emptyAs : '',
        'min'      => $min,
        'max'      => $max,
        'decimals' => $decimals,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" x-data="madNumericField({{ $alpineCfg }})"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group">
        @if($hasPrefix)
            <span class="mad-input-addon">{{ $prefix }}</span>
        @endif
        <input
            id="{{ $id }}"
            type="text"
            inputmode="decimal"
            class="mad-input{{ $alignCls }}{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            x-ref="input"
            @focus="onFocus($event)"
            @blur="onBlur($event)"
            @keydown.enter="$event.target.blur()"
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            {!! $attrs !!}
        >
        @if($hasSuffix)
            <span class="mad-input-addon mad-input-addon-r">{{ $suffix }}</span>
        @endif
    </div>
    {{-- Hidden input com o valor numérico cru para submit/mad:model --}}
    <input type="hidden" name="{{ $name }}" :value="rawValue" mad:model="{{ $name }}">
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
