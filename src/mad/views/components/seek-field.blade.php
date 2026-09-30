@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'placeholder' => '', 'on-seek' => '', 'auxiliary' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'emptyAs' => 'null'])
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $readonly  = !empty($readonly);
    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $onSeek    = $onSeek    ?? '';
    $auxiliary = $auxiliary ?? '';
    // Registro > prop `value` (default do dev), preservando attrs do caller.
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value);
    // Em branco grava NULL (a chave de outra tabela nunca é ''): coluna
    // inteira recusava o '' e o salvar quebrava. empty-as="empty" = antigo.
    $emptyAs = strtolower(trim((string) ($emptyAs ?? 'null'))) ?: 'null';
    \Mad\Form\MadFormRegistry::register($name, 'seek', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group" x-data>
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="text"
            class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            {!! $attrs !!}
        >
        <button
            type="button"
            class="mad-seek-btn"
            @click="$dispatch('mad:seek', { name: '{{ $name }}', onSeek: '{{ $onSeek }}', el: $el })"
            @if($disabled) disabled @endif
            aria-label="Buscar"
        >
            <i data-lucide="search"></i>
        </button>
        @if($auxiliary)
            <input type="hidden" name="{{ $auxiliary }}" id="{{ $auxiliary }}_aux">
        @endif
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
