@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'prefix' => 'R$','suffix' => '', 'placeholder' => '', 'decimals' => 2, 'min' => '', 'max' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'decimalSep' => ',', 'thousandSep' => '.', 'fillDirection' => 'right', 'allowNegative' => false, 'emptyAs' => 'null', 'align' => ''])
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $readonly  = !empty($readonly);
    $decimals  = (int)$decimals;
    $allowNegative = !empty($allowNegative);
    $fillDirection = strtolower((string)$fillDirection) === 'left' ? 'left' : 'right';
    // Sanitiza separadores — single char (ou string vazia para thousandSep)
    $decimalSep  = $decimalSep !== '' ? mb_substr((string)$decimalSep, 0, 1) : ',';
    $thousandSep = mb_substr((string)$thousandSep, 0, 1);

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
    $ph        = $placeholder ?: '0' . ($decimals > 0 ? $decimalSep . str_repeat('0', $decimals) : '');

    $alpineCfg = json_encode(array_filter([
        'decimals'      => $decimals,
        'value'         => $rawValue,
        'min'           => $min !== '' ? (float)$min : null,
        'max'           => $max !== '' ? (float)$max : null,
        'decimalSep'    => $decimalSep,
        'thousandSep'   => $thousandSep,
        'fillDirection' => $fillDirection,
        'allowNegative' => $allowNegative,
    ], fn($v) => $v !== null));

    \Mad\Form\MadFormRegistry::register($name, 'money', [
        'label'         => strip_tags($label),
        'required'      => $required,
        'emptyAs'       => $emptyAs !== 'null' ? $emptyAs : '',
        'min'           => $min,
        'max'           => $max,
        'decimals'      => $decimals,
        'decimalSep'    => $decimalSep,
        'thousandSep'   => $thousandSep,
        'fillDirection' => $fillDirection,
        'allowNegative' => $allowNegative,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" x-data="madMoneyField({{ $alpineCfg }})"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
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
            inputmode="{{ $allowNegative ? 'text' : 'numeric' }}"
            class="mad-input{{ $alignCls }}{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $ph }}"
            x-ref="input"
            :value="display"
            @input="_onInput($event)"
            @keydown="_onKeydown($event)"
            @blur="_onBlur()"
            @focus="madSelectOnFocus($event)"
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
