@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'min' => 0,'max' => 100, 'step' => 1, 'suffix' => '', 'hint' => '', 'error' => '', 'disabled' => false, 'attrs' => '', 'emptyAs' => 'null'])
@php
    $disabled = !empty($disabled);
    // Registro > prop `value` (default do dev) — regra em \Mad\Support\MadFieldValue.
    $value = \Mad\Support\MadFieldValue::resolve((string)$name, $value ?? null);
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model.live="' . $name . '" ' . $attrs;
    }
    $value    = $value !== '' ? $value : 50;
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $alpineId = 'rv_' . preg_replace('/[^a-zA-Z0-9]/', '_', $id);
    $sfx      = addslashes($suffix);
    \Mad\Form\MadFormRegistry::register($name, 'range', [
        'label' => strip_tags($label),
        'emptyAs' => $emptyAs !== 'null' ? $emptyAs : '',
        'min'   => $min,
        'max'   => $max,
        'step'  => $step,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false); @endphp
<div class="mad-field" x-data="{ {{ $alpineId }}: {{ $value }} }" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2px;">
            <label class="mad-label" for="{{ $id }}" style="margin:0;">{!! $label !!}</label>
            <span style="font-size:13px;font-weight:700;color:var(--mad-text);"
                  x-text="{{ $alpineId }} + '{{ $suffix }}'"></span>
        </div>
    @endif
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="range"
        class="mad-range"
        min="{{ $min }}"
        max="{{ $max }}"
        step="{{ $step }}"
        x-model="{{ $alpineId }}"
        @if($disabled) disabled @endif
        {!! $attrs !!}
    >
    <div style="display:flex;justify-content:space-between;margin-top:2px;">
        <span style="font-size:10px;color:var(--mad-text-subtle);">{{ $min }}{{ $suffix }}</span>
        <span style="font-size:10px;color:var(--mad-text-subtle);">{{ $max }}{{ $suffix }}</span>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
