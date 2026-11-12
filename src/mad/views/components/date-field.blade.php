@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'align' => '', 'width' => '', 'maxWidth' => '',
    'label' => '',
    'name' => '',
    'value' => '',
    'min' => '',
    'max' => '',
    'hint' => '', 'hintIcon' => false,
    'error' => '',
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'attrs' => '',
    'display_mask' => '',
    'database_mask' => 'yyyy-mm-dd',
    'opens' => 'left',
    // Dia da semana junto da data quando o campo não está em edição (fórum
    // #293): `short` → "Ter, 24/03/2026", `long` → "Terça-feira, 24/03/2026".
    // Vazio = como sempre. A digitação e o valor gravado não mudam.
    'weekday' => '',
])
@php
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    // Sem máscara na tag, vale a do idioma do app (inglês = mm/dd/yyyy).
    $display_mask  = ($displayMask ?? $display_mask) ?: \Mad\Support\DateMask::date();
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

    // Converte mascara (display: dd/mm/yyyy hh:ii / db: yyyy-mm-dd HH:MM:SS) para formato PHP date().
    // mm = mes (lowercase), MM = minuto (uppercase, convencao db); ii = minuto (convencao picker).
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

    // Reformata $value de database_mask para display_mask (tolerante: fallback strtotime).
    // Aceita tambem valor ja em display_mask (round-trip via mad:model).
    $rawDbValue = (string) $value;
    if ($value !== '') {
        $dt = \DateTime::createFromFormat($maskToPhp($database_mask), $value);
        if (!$dt) {
            // Tenta display_mask (valor ja convertido pela view ou veio do wire)
            $dt = \DateTime::createFromFormat($maskToPhp($display_mask), $value);
        }
        if (!$dt && str_starts_with($display_mask, 'dd/mm') && \Mad\Form\MadFormRegistry::isDisplayDate($value)) {
            // Texto da tela com hora ou segundos a mais que a máscara: o strtotime()
            // abaixo leria n/n/n como mês/dia/ano e trocaria dia e mês (fw#273).
            $dt = \Mad\Form\MadForm::parseDate($value) ?? false;
        }
        if (!$dt) {
            $ts = strtotime($value);
            if ($ts) { $dt = (new \DateTime())->setTimestamp($ts); }
        }
        if ($dt) { $value = $dt->format($maskToPhp($display_mask)); }
    }

    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);

    $weekdayLabels = \Mad\Support\ValueFormatter::weekdayLabels(match (strtolower(trim((string) $weekday))) {
        'short', 'abbr', 'abreviado' => 'short',
        'long', 'full', 'extenso'    => 'long',
        default                      => '',
    });
    // Texto da leitura (formulário em modo leitura): "Ter, 24/03/2026".
    $displayText = ($weekdayLabels && $value !== '' && !empty($dt))
        ? $weekdayLabels[(int) $dt->format('w')] . ', ' . $value
        : $value;
    $hasError = !empty($error);
    // `hint-icon`: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    // Placeholder amigavel BR: ano = "a", minutos = "mm".
    $placeholder = strtr($display_mask, ['ii' => 'mm', 'y' => mad_t('mad.ui.year_letter')]);

    // Config do Alpine (parser tolerante usa display, database e ISO).
    $alpineCfg = json_encode([
        'displayMask'  => $display_mask,
        'databaseMask' => $database_mask,
        'initialValue' => $rawDbValue,
        'min'          => $min ?: '',
        'max'          => $max ?: '',
    ] + ($weekdayLabels ? ['weekdays' => $weekdayLabels] : []), JSON_UNESCAPED_UNICODE);

    \Mad\Form\MadFormRegistry::register($name, 'date', [
        'label'         => strip_tags($label),
        'required'      => $required,
        'min'           => $min,
        'max'           => $max,
        'display_mask'  => $display_mask,
        'database_mask' => $database_mask,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
{{-- Formulário em leitura (fw#498): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => $value, 'text' => $displayText, 'style' => $_dimStyle]) !!}
@else
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
    @endif
    <div class="mad-input-group" style="position:relative;"
         x-data="madDatePicker({{ $alpineCfg }})"
         @keydown.escape.window="close()"
         @click.outside="close()">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="text"
            class="mad-input{{ \Mad\Support\CssUnits::alignClass($align ?? '') }}{{ $hasError ? ' mad-input-error' : '' }}"
            @if($weekdayLabels) :class="{ 'mad-date-input--weekday': weekdayText }" @endif
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
            <i data-lucide="calendar" style="width:14px;height:14px;"></i>
        </button>

        @if($weekdayLabels)
            {{-- Fora da edição a data aparece com o dia da semana por cima do
                 campo; com o foco volta o texto digitável de sempre. --}}
            <span class="mad-date-weekday" x-ref="weekday" x-show="weekdayText" x-cloak aria-hidden="true"
                  x-effect="weekdayText && $nextTick(() => syncWeekdayBox())"><span x-text="weekdayText"></span></span>
        @endif

        @include('components.partials.date-popup', ['withTime' => false, 'opens' => $opens])
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
@endif
