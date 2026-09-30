@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 
    'width' => '',
    'maxWidth' => '',
    'label' => '',
    'name_start' => '',
    'name_end' => '',
    'min' => '',
    'max' => '',
    'max_span' => 0,
    'hint' => '',
    'error' => '',
    'required' => false,
    'disabled' => false,
    'presets' => false,
    'custom_presets' => [],
    'placeholder' => 'Selecione o período',
    'clearable' => true,
    'opens' => 'left',
    'attrs_start' => '',
    'attrs_end' => '',
    'default_start' => '',
    'default_end' => '',
    'display_mask' => 'dd/mm/yyyy',
    'database_mask' => 'yyyy-mm-dd',
])
@php
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    $name_start     = $nameStart     ?? $name_start;
    $name_end       = $nameEnd       ?? $name_end;
    $max_span       = $maxSpan       ?? $max_span;
    $custom_presets = $customPresets ?? $custom_presets;
    $default_start  = $defaultStart  ?? $default_start;
    $default_end    = $defaultEnd    ?? $default_end;
    $display_mask   = $displayMask   ?? $display_mask;
    $database_mask  = $databaseMask  ?? $database_mask;
    $attrs_start    = $attrsStart    ?? $attrs_start;
    $attrs_end      = $attrsEnd      ?? $attrs_end;

    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $presets   = !empty($presets);
    $clearable = !empty($clearable);

    // Detect mad:change / data-mad-change from attrs (set by MadBlade compiler)
    $changeMethod = '';
    // Check attrs_start for data-mad-change (compiled from mad:change on the tag)
    if (preg_match('/data-mad-change="([^"]+)"/', $attrs_start, $cm)) {
        $changeMethod = $cm[1];
        $attrs_start = preg_replace('/\s*data-mad-change="[^"]*"/', '', $attrs_start);
    }
    // Also check component-level attrs (mad:change="method" on the tag itself)
    if (!$changeMethod && isset($attrs) && preg_match('/data-mad-change="([^"]+)"/', $attrs, $cm)) {
        $changeMethod = $cm[1];
    }

    // Auto-bind via contexto do MadComponent
    $_ctx = \Mad\Component\MadRenderContext::current();
    $valueStart = '';
    $valueEnd   = '';
    if ($name_start) {
        if (array_key_exists($name_start, $_ctx)) $valueStart = (string)$_ctx[$name_start];
        if (strpos($attrs_start, 'mad:model') === false && strpos($attrs_start, 'data-mad-model') === false) {
            $attrs_start = 'mad:model="' . $name_start . '" ' . $attrs_start;
        }
    }
    if ($name_end) {
        if (array_key_exists($name_end, $_ctx)) $valueEnd = (string)$_ctx[$name_end];
        if (strpos($attrs_end, 'mad:model') === false && strpos($attrs_end, 'data-mad-model') === false) {
            $attrs_end = 'mad:model="' . $name_end . '" ' . $attrs_end;
        }
    }
    // Fallback pros valores padrão quando o contexto não trouxe nada do model.
    // Só semeia com o PAR completo: um endpoint sozinho vira intervalo meio-aberto
    // ('2025-11-01' + ''), que a consulta por intervalo recusa com exceção no render.
    if ($default_start !== '' && $default_end !== '') {
        if ($valueStart === '') $valueStart = (string)$default_start;
        if ($valueEnd   === '') $valueEnd   = (string)$default_end;
    }

    // Inject data-mad-change into attrs_start for MadWire to detect
    if ($changeMethod) {
        $attrs_start .= ' data-mad-change="' . $changeMethod . '"';
    }

    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    // Build Alpine config
    $alpineCfg = json_encode([
        'startValue'     => $valueStart ?: '',
        'endValue'       => $valueEnd ?: '',
        'min'            => $min ?: '',
        'max'            => $max ?: '',
        'maxSpan'        => (int)$max_span,
        'defaultPresets' => $presets,
        'customPresets'  => !empty($custom_presets) ? $custom_presets : [],
        'placeholder'    => $placeholder,
        'nameStart'      => $name_start,
        'nameEnd'        => $name_end,
        'displayMask'    => $display_mask,
        'databaseMask'   => $database_mask,
        'disabled'       => $disabled,
    ], JSON_UNESCAPED_UNICODE);

    \Mad\Form\MadFormRegistry::register($name_start, 'date', ['label' => strip_tags($label) . ' (início)', 'required' => $required, 'display_mask' => $display_mask, 'database_mask' => $database_mask]);
    \Mad\Form\MadFormRegistry::register($name_end,   'date', ['label' => strip_tags($label) . ' (fim)',   'required' => false,      'display_mask' => $display_mask, 'database_mask' => $database_mask]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" style="position:relative;{{ $_dimStyle }}" x-data="madDateRangePicker({{ $alpineCfg }})">
    @if($label)
        <div class="mad-label">{!! $label !!}{!! $reqStar !!}</div>
    @endif

    {{-- Trigger (visual input) --}}
    <div class="mad-drp-trigger{{ $hasError ? ' mad-input-error' : '' }}"
         :class="{ 'mad-drp-trigger--open': isOpen, 'mad-drp-trigger--empty': !hasValue }"
         @if(!$disabled) @click="open()" @endif
         @if($disabled) aria-disabled="true" style="pointer-events:none;opacity:.5;" @endif
         role="combobox" aria-haspopup="dialog" :aria-expanded="isOpen">
        <i data-lucide="calendar-range" style="width:15px;height:15px;flex-shrink:0;color:var(--mad-text-muted);"></i>
        <span class="mad-drp-display" x-text="displayText"></span>
        @if($clearable)
        <button type="button" class="mad-drp-clear" x-show="hasValue" @click.stop="clear()" tabindex="-1">
            <i data-lucide="x" style="width:14px;height:14px;"></i>
        </button>
        @endif
    </div>

    {{-- Hidden inputs (form data, retrocompatível) --}}
    <input type="hidden" name="{{ $name_start }}" :value="isoStart" {!! $attrs_start !!}>
    <input type="hidden" name="{{ $name_end }}"   :value="isoEnd"   {!! $attrs_end !!}>

    {{-- Popup --}}
    <div class="mad-drp-popup mad-drp-popup--{{ $opens }}"
         x-show="isOpen"
         x-transition:enter="mad-drp-enter"
         x-transition:enter-start="mad-drp-enter-start"
         x-transition:enter-end="mad-drp-enter-end"
         x-transition:leave="mad-drp-leave"
         x-transition:leave-start="mad-drp-leave-start"
         x-transition:leave-end="mad-drp-leave-end"
         @click.outside="close()"
         @keydown.escape.window="close()"
         x-cloak>

        {{-- Presets sidebar --}}
        <template x-if="presets.length > 0">
            <div class="mad-drp-presets">
                <template x-for="(p, i) in presets" :key="i">
                    <button type="button" class="mad-drp-preset"
                            :class="{ 'mad-drp-preset--active': isPresetActive(p) }"
                            @click="applyPreset(p)"
                            x-text="p.label"></button>
                </template>
            </div>
        </template>

        {{-- Calendars --}}
        <div class="mad-drp-calendars">
            {{-- Left calendar --}}
            <div class="mad-drp-cal">
                <div class="mad-drp-nav">
                    <button type="button" class="mad-drp-nav-btn" @click="prevMonth('left')">
                        <i data-lucide="chevron-left" style="width:16px;height:16px;"></i>
                    </button>
                    <span class="mad-drp-month-label" x-text="leftMonthLabel"></span>
                    <button type="button" class="mad-drp-nav-btn" @click="nextMonth('left')">
                        <i data-lucide="chevron-right" style="width:16px;height:16px;"></i>
                    </button>
                </div>
                <div class="mad-drp-weekdays">
                    <span>D</span><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span>
                </div>
                <div class="mad-drp-days">
                    <template x-for="(day, di) in leftDays" :key="'l'+di">
                        <button type="button" class="mad-drp-day"
                                :class="dayClasses(day)"
                                :disabled="day.disabled || !day.currentMonth"
                                @click="day.currentMonth && selectDate(day.date)"
                                @mouseenter="if(day.currentMonth && !day.disabled) hoveredDate = day.date"
                                @mouseleave="hoveredDate = null"
                                x-text="day.day"></button>
                    </template>
                </div>
            </div>

            {{-- Right calendar --}}
            <div class="mad-drp-cal">
                <div class="mad-drp-nav">
                    <button type="button" class="mad-drp-nav-btn" @click="prevMonth('right')">
                        <i data-lucide="chevron-left" style="width:16px;height:16px;"></i>
                    </button>
                    <span class="mad-drp-month-label" x-text="rightMonthLabel"></span>
                    <button type="button" class="mad-drp-nav-btn" @click="nextMonth('right')">
                        <i data-lucide="chevron-right" style="width:16px;height:16px;"></i>
                    </button>
                </div>
                <div class="mad-drp-weekdays">
                    <span>D</span><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span>
                </div>
                <div class="mad-drp-days">
                    <template x-for="(day, di) in rightDays" :key="'r'+di">
                        <button type="button" class="mad-drp-day"
                                :class="dayClasses(day)"
                                :disabled="day.disabled || !day.currentMonth"
                                @click="day.currentMonth && selectDate(day.date)"
                                @mouseenter="if(day.currentMonth && !day.disabled) hoveredDate = day.date"
                                @mouseleave="hoveredDate = null"
                                x-text="day.day"></button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name_start }}">{!! $hasError ? $error : $hint !!}</p>
</div>
