@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'prefix' => 'R$','suffix' => '', 'placeholder' => '', 'decimals' => 2, 'min' => '', 'max' => '', 'hint' => '', 'hintIcon' => false, 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'decimalSep' => ',', 'thousandSep' => '.', 'fillDirection' => 'left', 'allowNegative' => false, 'emptyAs' => 'null', 'align' => ''])
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $readonly  = !empty($readonly);
    $decimals  = (int)$decimals;
    $allowNegative = !empty($allowNegative);
    // Padrão = digitar como o <mad-numeric-field>: inteiro, vírgula opcional
    // ("10" = 10,00). A calculadora ("10" = 0,10) é `fill-direction="right"`.
    // Antes era o padrão, e no mesmo formulário um campo de moeda e um
    // numérico gravavam valores 100× diferentes para o mesmo "10" (fw#307).
    $fillDirection = strtolower((string)$fillDirection) === 'right' ? 'right' : 'left';
    // Sanitiza separadores — single char (ou string vazia para thousandSep)
    $decimalSep  = $decimalSep !== '' ? mb_substr((string)$decimalSep, 0, 1) : ',';
    $thousandSep = mb_substr((string)$thousandSep, 0, 1);

    // Valor inicial: registro (MadWire) > prop `value` (default do dev).
    // Sem nenhum dos dois o campo abre VAZIO: antes o NULL do registro virava
    // 0.0, o campo abria "0,00" e salvar sem mexer gravava 0 (fw#201).
    $_rawStr  = \Mad\Support\MadFieldValue::resolve((string)$name, $value ?? null);
    $rawValue = is_numeric($_rawStr) ? (float)$_rawStr : null;

    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError  = !empty($error);
    // `hint-icon`: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
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
{{-- Formulário em leitura (fw#498): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => $_rawStr, 'text' => \Mad\Form\ReadMode::money($_rawStr, (string) $prefix, (int) $decimals, (string) $decimalSep, (string) $thousandSep, (string) $suffix), 'style' => $_dimStyle]) !!}
@else
<div class="mad-field" x-data="madMoneyField({{ $alpineCfg }})"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
    @endif
    <div class="mad-input-group">
        @if($hasPrefix)
            <span class="mad-input-addon">{{ $prefix }}</span>
        @endif
        <input
            id="{{ $id }}"
            type="text"
            inputmode="{{ $allowNegative ? 'text' : ($fillDirection === 'left' ? 'decimal' : 'numeric') }}"
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
@endif
