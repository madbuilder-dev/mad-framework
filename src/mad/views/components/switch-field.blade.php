@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '', 'label' => '', 'description' => '', 'hint' => '', 'error' => '', 'name' => '', 'value' => '', 'checked' => false, 'required' => false,'disabled' => false, 'readonly' => false, 'attrs' => '', 'style' => '', 'valueOn' => '1', 'valueOff' => '0', 'variant' => '', 'size' => '', 'labelPosition' => ''])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $readonly = !empty($readonly);
    $valueOn  = (string) ($valueOn ?? '1');
    $valueOff = (string) ($valueOff ?? '0');
    $variant  = is_string($variant) ? trim($variant) : '';
    $size     = is_string($size)    ? trim($size)    : '';
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name);
    $_isReadonly = $readonly || ($name && \Mad\Component\MadRenderContext::isReadonly($name));
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        // Compara contra valueOn (suporta dual-value: 'A'/'I', etc).
        // Registro > props `checked`/`value` (default do dev) — regra em
        // \Mad\Support\MadFieldValue. Sem coluna no contexto o campo caía
        // sempre em false e não havia como ligar por default pela tag.
        $_default = !empty($checked) ? (string) $valueOn : (isset($value) ? $value : '');
        $isChecked = \Mad\Support\MadFieldValue::resolve($name, $_default) === (string) $valueOn;
        $madModelAttr = 'mad:model="' . $name . '"' . ($isChecked ? ' checked' : '');
        // Prepend mad:model/checked preservando qualquer atributo passado pelo caller
        // (ex: @change, x-on:click) — antes sobrescreviamos $attrs aqui, o que
        // descartava handlers Alpine fornecidos via prop :attrs.
        $attrs = trim($madModelAttr . ' ' . $attrs);
    }
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    $toggleClass = 'mad-toggle';
    if ($variant) { $toggleClass .= ' mad-toggle--' . $variant; }
    if ($size)    { $toggleClass .= ' mad-toggle--' . $size; }
    $rowClass = 'mad-switch-row';
    if ($description) { $rowClass .= ' mad-switch-card'; }
    // label-position="right": chave primeiro, texto colado depois (como no
    // checkbox). Qualquer outro valor mantém o layout publicado: texto à
    // esquerda e chave na ponta direita da coluna.
    if (is_string($labelPosition) && strtolower(trim($labelPosition)) === 'right') {
        $rowClass .= ' mad-switch-row--label-right';
    }
    // readonly via prop ou contexto: pointer-events:none + opacity .75 no field todo
    // (mantem name + value postando; impede toggle pelo usuario)
    \Mad\Form\MadFormRegistry::register($name, 'switch', [
        'label'     => strip_tags($label),
        'required'  => $required,
        'value_on'  => $valueOn,
        'value_off' => $valueOff,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false); @endphp
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }}{{ $_isReadonly ? ' mad-readonly' : '' }}" @if($name) data-mad-field="{{ $name }}" @endif @if($style || $_dimStyle) style="{{ $style }}{{ $_dimStyle }}" @endif>
    <div class="{{ $rowClass }}">
        <div class="mad-switch-text">
            @if($label)
                <div class="mad-label" style="margin-bottom:0;">{!! $label !!}{!! $reqStar !!}</div>
            @endif
            @if($description)
                <div class="mad-switch-desc">{!! $description !!}</div>
            @endif
        </div>
        <div class="mad-toggle-wrap">
            <label class="{{ $toggleClass }}">
                <input type="checkbox" name="{{ $name }}" value="{{ $valueOn }}"
                    data-value-on="{{ $valueOn }}" data-value-off="{{ $valueOff }}"
                    @if($disabled) disabled @endif
                    {!! $attrs !!}>
                <span class="mad-toggle-track"></span>
            </label>
        </div>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
