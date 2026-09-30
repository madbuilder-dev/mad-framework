@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'value' => '', 'placeholder' => 'Pesquisar...', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => ''])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    $alpineId = 'sq_' . preg_replace('/[^a-zA-Z0-9]/', '_', $id);
    \Mad\Form\MadFormRegistry::register($name, 'search', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
    // Valor inicial (registro > prop `value`) + mad:model — o `value=` sai no
    // input e no x-data logo abaixo, então aqui só resolvemos o texto; o
    // mergeAttrs cuidaria de duplicar. Regra em \Mad\Support\MadFieldValue.
    $_searchValue = \Mad\Support\MadFieldValue::resolve((string)$name, $value);
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-search-wrap" x-data="{ {{ $alpineId }}: {{ json_encode((string)$_searchValue) }} }">
        <i data-lucide="search" class="mad-search-icon"></i>
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="search"
            class="mad-input mad-search-input{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            value="{{ htmlspecialchars($_searchValue, ENT_QUOTES) }}"
            x-model="{{ $alpineId }}"
            autocomplete="off"
            @if($required) required @endif
            @if($disabled) disabled @endif
            {!! $attrs !!}
        >
        <button type="button"
                class="mad-search-clear"
                x-show="{{ $alpineId }}"
                x-cloak
                @click="{{ $alpineId }} = ''; document.getElementById('{{ $id }}').dispatchEvent(new Event('input', {bubbles:true}))">
            <i data-lucide="x" style="width:13px;height:13px;"></i>
        </button>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
