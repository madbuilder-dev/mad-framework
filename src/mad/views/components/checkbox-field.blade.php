@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '', 'label' => '', 'name' => '', 'value' => '1', 'valueOff' => '0', 'description' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'checked' => false, 'attrs' => ''])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $readonly = !empty($readonly);
    $checked  = !empty($checked);
    // value = TCheckButton indexValue (ativo); valueOff = inactiveIndexValue
    $value    = (string) ($value ?? '1');
    $valueOff = (string) ($valueOff ?? '0');
    $_isReadonly = $readonly || ($name && \Mad\Component\MadRenderContext::isReadonly($name));
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        // Compara contra value (ativo) — suporta dual-value tipo 'A'/'I'.
        // Coluna presente e VAZIA (''/NULL: o cadastro novo traz a coluna assim)
        // não atropela o `checked` da tag nem o "Valor padrão" — a mesma regra
        // de MadFieldValue::resolve. `false` (coluna booleana com cast) é
        // "desligado", não vazio.
        $_cur = array_key_exists($name, $_ctx) ? $_ctx[$name] : null;
        if ($_cur !== null && $_cur !== '' && !is_array($_cur) && !is_object($_cur)) {
            $checked = ((string) $_cur) === $value;
        } else {
            // "Valor padrão" (`default`): o `value` do checkbox é o valor GRAVADO
            // quando marcado, não o estado inicial — o padrão marca o campo
            // quando é igual a ele.
            $_def = \Mad\Support\MadFieldValue::withDefault($name, '', $default ?? null);
            if ($_def !== '') {
                $checked = $_def === $value;
            }
        }
        // Prepend mad:model/checked preservando atributos do caller (ex: @change,
        // x-on:click via :attrs) — sobrescrever descartava handlers silenciosamente.
        $attrs = trim('mad:model="' . $name . '"' . ($checked ? ' checked' : '') . ' ' . $attrs);
    }
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    \Mad\Form\MadFormRegistry::register($name, 'checkbox', [
        'label'     => strip_tags($label),
        'required'  => $required,
        'value'     => $value,
        'value_off' => $valueOff,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false); @endphp
<div class="mad-field{{ $_isReadonly ? ' mad-readonly' : '' }}" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    <label class="mad-checkbox-wrap" for="{{ $id }}">
        <input
            type="checkbox"
            id="{{ $id }}"
            name="{{ $name }}"
            value="{{ $value }}"
            data-value-on="{{ $value }}"
            data-value-off="{{ $valueOff }}"
            class="mad-checkbox"
            @if($checked)  checked  @endif
            @if($required) required @endif
            @if($disabled) disabled @endif
            {!! $attrs !!}
        >
        <span class="mad-checkbox-box"></span>
        <span class="mad-checkbox-label mad-checkbox-field-label">
            {!! $label !!}
            @if($required)<span class="mad-required">*</span>@endif
            @if($description)
                <span class="mad-field-hint" style="display:block;margin-top:1px;">{!! $description !!}</span>
            @endif
        </span>
    </label>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}" style="margin-left:24px;">{!! $hasError ? $error : $hint !!}</p>
</div>
