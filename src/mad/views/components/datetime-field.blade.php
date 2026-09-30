@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '',
    'label' => '',
    'name' => '',
    'value' => '',
    'min' => '',
    'max' => '',
    'hint' => '',
    'error' => '',
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'attrs' => '',
    'display_mask' => 'dd/mm/yyyy hh:ii',
    'database_mask' => 'yyyy-mm-dd hh:ii',
    'opens' => 'left',
])
@php
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    $display_mask  = $displayMask  ?? $display_mask;
    $database_mask = $databaseMask ?? $database_mask;

    $required = !empty($required);
    $disabled = !empty($disabled);
    $readonly = !empty($readonly);

    // Registro > prop `value` (default do dev) — regra em \Mad\Support\MadFieldValue.
    // O `!isset($value)` de antes invertia isso: `value=` na tag ganhava do banco.
    $value = \Mad\Support\MadFieldValue::resolve((string)$name, $value ?? null);
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }

    // Converte mascara para formato PHP date(). mm = mes, MM = minuto (db), ii = minuto (picker).
    $maskToPhp = function ($mask) {
        return strtr($mask, [
            'yyyy' => 'Y', 'yy' => 'y',
            'mm' => 'm', 'MM' => 'i',
            'dd' => 'd',
            'hh' => 'H', 'HH' => 'H',
            'ii' => 'i',
            'ss' => 's', 'SS' => 's',
        ]);
    };

    $rawDbValue = (string) $value;
    if ($value !== '') {
        $dt = \DateTime::createFromFormat($maskToPhp($database_mask), $value);
        if (!$dt) {
            $ts = strtotime($value);
            if ($ts) { $dt = (new \DateTime())->setTimestamp($ts); }
        }
        if ($dt) { $value = $dt->format($maskToPhp($display_mask)); }
    }

    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    $placeholder = strtr($display_mask, ['ii' => 'mm', 'y' => 'a']);

    $alpineCfg = json_encode([
        'displayMask'  => $display_mask,
        'databaseMask' => $database_mask,
        'initialValue' => $rawDbValue,
        'min'          => $min ?: '',
        'max'          => $max ?: '',
    ], JSON_UNESCAPED_UNICODE);

    \Mad\Form\MadFormRegistry::register($name, 'datetime', [
        'label'         => strip_tags($label),
        'required'      => $required,
        'min'           => $min,
        'max'           => $max,
        'display_mask'  => $display_mask,
        'database_mask' => $database_mask,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group" style="position:relative;"
         x-data="madDateTimePicker({{ $alpineCfg }})"
         @keydown.escape.window="close()"
         @click.outside="close()">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="text"
            class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
            value="{{ $value }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            inputmode="numeric"
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            {!! $attrs !!}
        >
        <button type="button" class="mad-datepicker-btn" tabindex="-1"
                @if($disabled || $readonly) disabled @endif
                @click="toggle()">
            <i data-lucide="calendar-clock" style="width:14px;height:14px;"></i>
        </button>

        @include('components.partials.date-popup', ['withTime' => true, 'opens' => $opens])
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
