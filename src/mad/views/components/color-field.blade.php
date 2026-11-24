@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'inputBorderColor' => '', 'inputBorderWidth' => '', 'inputBorderSides' => '', 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'hint' => '','error' => '', 'required' => false, 'disabled' => false, 'attrs' => ''])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    // Registro > prop `value` (default do dev) — regra em \Mad\Support\MadFieldValue.
    $value = \Mad\Support\MadFieldValue::resolve((string)$name, $value ?? null);
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
    $_rawColor = trim((string) $value);   // leitura: vazio é "—", não preto
    $value    = $value !== '' ? $value : '#000000';
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    \Mad\Form\MadFormRegistry::register($name, 'color', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false, $inputBorderColor ?? '', $inputBorderWidth ?? '', $inputBorderSides ?? ''); @endphp
{{-- Formulário em leitura (fw#498, fórum #394): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => $_rawColor, 'text' => $_rawColor, 'html' => preg_match('/^#[0-9a-fA-F]{3,8}$/', $_rawColor) ? '<span style="display:inline-block;width:14px;height:14px;border-radius:3px;border:1px solid var(--mad-border);vertical-align:-2px;margin-right:6px;background:' . $_rawColor . '"></span>' . e($_rawColor) : null, 'style' => $_dimStyle]) !!}
@else
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-colorfield-wrap"
         x-data="madColorField('{{ $value }}')"
         x-init="init()">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="text"
            class="mad-input mad-colorfield-input{{ $hasError ? ' mad-input-error' : '' }}"
            x-model="color"
            @input="syncFromInput()"
            placeholder="#000000"
            autocomplete="off"
            @if($required) required @endif
            @if($disabled) disabled @endif
            {!! $attrs !!}
        >
        <button type="button"
                class="mad-colorfield-swatch"
                x-ref="swatch"
                :style="'background:' + color"
                @click.prevent="pickr && pickr.show()"
                @if($disabled) disabled @endif
                aria-label="{{ mad_t('mad.ui.open_color_picker') }}"></button>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
@endif
