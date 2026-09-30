@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'min' => '', 'max' => '', 'step' => 1, 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'emptyAs' => 'null'])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $readonly = !empty($readonly);
    // Registro > prop `value` (default do dev), preservando attrs do caller.
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value);
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    \Mad\Form\MadFormRegistry::register($name, 'spinner', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs !== 'null' ? $emptyAs : '',
        'min'      => $min,
        'max'      => $max,
        'step'     => $step,
    ]);
    $spinCfg = json_encode([
        'min'  => $min === '' ? null : (float)$min,
        'max'  => $max === '' ? null : (float)$max,
        'step' => (float)$step,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group" x-data="madSpinnerField({{ $spinCfg }})">
        <button type="button" class="mad-spinner-btn" @click="decrement()" :disabled="isAtMin()"
            @if($disabled) disabled @endif>
            <i data-lucide="minus"></i>
        </button>
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="number"
            class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
            x-ref="input"
            @if($min !== '') min="{{ $min }}" @endif
            @if($max !== '') max="{{ $max }}" @endif
            step="{{ $step }}"
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            {!! $attrs !!}
        >
        <button type="button" class="mad-spinner-btn" @click="increment()" :disabled="isAtMax()"
            @if($disabled) disabled @endif>
            <i data-lucide="plus"></i>
        </button>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
