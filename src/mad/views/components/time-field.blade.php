@props(['autocomplete' => null,  'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'inputBorderColor' => '', 'inputBorderWidth' => '', 'inputBorderSides' => '', 'align' => '', 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'hint' => '', 'hintIcon' => false, 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => ''])
@php // hint-icon: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
@endphp
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $readonly = !empty($readonly);
    // Registro > prop `value` (default do dev), preservando attrs do caller
    // (min/max/step/handlers) — regra em \Mad\Support\MadFieldValue.
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value);
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    \Mad\Form\MadFormRegistry::register($name, 'time', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false, $inputBorderColor ?? '', $inputBorderWidth ?? '', $inputBorderSides ?? ''); @endphp
{{-- Formulário em leitura (fw#498, fórum #394): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => ($__rv = \Mad\Support\MadFieldValue::resolve((string) $name, $value ?? '')), 'text' => preg_match('/^(\d{1,2}:\d{2})(:\d{2})?$/', trim((string) $__rv), $__hm) ? $__hm[1] : (string) $__rv, 'style' => $_dimStyle]) !!}
@else
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
    @endif
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="time"
        class="mad-input{{ \Mad\Support\CssUnits::alignClass($align ?? '') }}{{ $hasError ? ' mad-input-error' : '' }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($readonly) readonly @endif
        {!! \Mad\Support\FieldAutocomplete::attr($autocomplete ?? null, (string) $attrs) !!}{!! $attrs !!}
    >
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
@endif
