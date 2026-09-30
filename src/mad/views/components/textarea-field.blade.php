@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '', 'height' => '', 'label' => '', 'name' => '', 'value' => '', 'placeholder' => '','hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'rows' => 3, 'style' => '', 'attrs' => ''])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $readonly = !empty($readonly);
    $rows = (int)$rows;
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name);
    $_isReadonly = $name && \Mad\Component\MadRenderContext::isReadonly($name);
    if ($_isReadonly) $readonly = true;
    // Registro > prop `value` (default do dev) — regra em \Mad\Support\MadFieldValue.
    $value = \Mad\Support\MadFieldValue::resolve((string)$name, $value ?? null);
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    \Mad\Form\MadFormRegistry::register($name, 'textarea', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
@endphp
@php
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false);
    // `height` vai no <textarea>, NÃO no .mad-field: o wrapper carrega label e
    // hint, então altura nele espremeria a caixa em vez de crescê-la. Normaliza
    // pelo CssUnits (número puro = px) — valor irreconhecível não emite nada.
    $_h = is_scalar($height ?? null) ? \Mad\Support\CssUnits::length((string) $height) : '';
    $_taStyle = ($_h !== '' ? 'height:' . $_h . ';' : '') . (string) $style;
@endphp
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }}" @if($name) data-mad-field="{{ $name }}" @endif @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        class="mad-textarea{{ $hasError ? ' mad-input-error' : '' }}"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        @if($_taStyle) style="{{ $_taStyle }}" @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($readonly) readonly @endif
        {!! $attrs !!}
    >{!! htmlspecialchars($value, ENT_QUOTES) !!}</textarea>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
