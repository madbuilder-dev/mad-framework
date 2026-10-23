@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '', 'label' => '', 'name' => '', 'options' => [], 'selected' => null, 'value' => '','inline' => false, 'breakItems' => 0, 'as' => '', 'boolean' => false, 'size' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => '', 'style' => ''])
@php
    /**
     * mad-radio-field — Grupo de radio buttons.
     *
     * Props:
     *   options     array   ['value' => 'Label', ...] ou [['value','label','disabled','description']]
     *   selected    string  Valor selecionado (auto via MadRenderContext quando omitido)
     *   inline      bool    Layout horizontal (padrao: vertical)
     *   breakItems  int     Quebra linha a cada N items (so vale com inline)
     *   as          string  '' (radio padrao) ou 'button' (visual btn-group segmentado)
     *   boolean     bool    Modo Sim/Nao automatico (gera options 1/2 e usa as=button + inline)
     *   size        string  '' (normal), 'sm', 'lg'
     */
    $required = !empty($required);
    $disabled = !empty($disabled);
    $inline   = !empty($inline);
    $boolean  = !empty($boolean);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    $breakItems = (int) $breakItems;

    // O editor de opções do Studio grava a lista como JSON num atributo sem `:`
    // (`options='[{"value":"A","label":"Opção A"}]'`): chega aqui como texto e o
    // foreach derrubava a tela (fórum #166). Texto que não é lista vira lista vazia.
    if (is_string($options)) {
        $__json = json_decode($options, true);
        $options = is_array($__json) ? $__json : [];
    }

    // Lista posta pelo código (`$this->form->setItems()`) vence a do Blade: é a
    // que o reload_radio mostrou. Sem isto o redesenho da tela voltava a lista
    // do Blade (fw#228). A forma de objeto do próprio radio (value, label,
    // disabled, description) passa inteira; outra lista de objetos vira mapa.
    $__codeItems = \Mad\Support\MadItems::fromForm((string) $name);
    if ($__codeItems !== null) {
        $options = (isset($__codeItems[0]['value'], $__codeItems[0]['label']) && is_array($__codeItems[0]))
            ? $__codeItems
            : \Mad\Support\MadItems::normalize($__codeItems);
    }

    // Boolean mode — atalho para Sim/Não com visual button + inline
    if ($boolean && empty($options)) {
        $options = ['1' => 'Sim', '2' => 'Não'];
        if (!$as)     $as = 'button';
        $inline = true;
    }

    // Variante visual: 'button' = segmentado tipo btn-group
    $isButton = ($as === 'button');

    // MadRenderContext — auto-fill selected/hidden/readonly
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name);
    $_isReadonly = $name && \Mad\Component\MadRenderContext::isReadonly($name);
    // `value` é alias de `selected` (prop antes ignorada em silêncio).
    if (($selected === null || $selected === '') && isset($value) && $value !== '') {
        $selected = $value;
    }
    // Registro > prop `selected`/`value` — regra em \Mad\Support\MadFieldValue.
    if ($name) {
        $_resolved = \Mad\Support\MadFieldValue::resolve($name, $selected);
        if ($_resolved === '' && isset($$name)) {
            $_resolved = (string) $$name;
        }
        $selected = $_resolved;
    }
    $selected = (string) ($selected ?? '');

    // As opções que a tela desenha (menos as desabilitadas): o valor escolhido
    // tem de ser uma delas — o que chega no POST é dado do navegador (fw#531).
    // Só vai para o estado da tela.
    $__offered = [];
    foreach ((array) $options as $__k => $__o) {
        if (!is_array($__o)) {
            $__offered[] = $__k;
        } elseif (array_key_exists('value', $__o) && empty($__o['disabled'])) {
            $__offered[] = $__o['value'];
        }
    }
    \Mad\Form\MadFormRegistry::register($name, 'radio', [
        'label'    => strip_tags($label),
        'required' => $required,
        'optionsSource' => $__offered !== [] ? ['one' => true, 'offered' => $__offered] : '',
    ]);

    $sizeCls = $size === 'sm' ? ' mad-radio-group-sm' : ($size === 'lg' ? ' mad-radio-group-lg' : '');

    if ($isButton) {
        // Variante button: segmented toggle group
        $wrapClass = 'mad-radio-btn-group' . ($inline ? '' : ' mad-radio-btn-stack') . $sizeCls;
        $wrapStyle = '';
    } else {
        // Variante padrao: radios circulares
        $wrapClass = 'mad-radio-group' . $sizeCls;
        $wrapStyle = $inline
            ? 'display:flex;flex-direction:row;flex-wrap:wrap;gap:16px;'
            : 'display:flex;flex-direction:column;gap:8px;';
    }

    $count = 0;
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false); @endphp
{{-- Formulário em leitura (fw#498): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => $selected, 'text' => \Mad\Form\ReadMode::optionText(is_array($options) ? $options : [], $selected), 'hidden' => $_isHidden, 'style' => $_dimStyle]) !!}
@else
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }}{{ $_isReadonly ? ' mad-readonly' : '' }}"
    @if($name) data-mad-field="{{ $name }}" @endif
    @if($style || $_dimStyle) style="{{ $style }}{{ $_dimStyle }}" @endif>
    @if($label)
        <div class="mad-label">{!! $label !!}{!! $reqStar !!}</div>
    @endif
    <div class="{{ $wrapClass }}"
        @if($wrapStyle) style="{{ $wrapStyle }}" @endif
        data-mad-radio-group="{{ $name }}" data-mad-model="{{ $name }}">
        @foreach($options as $optVal => $optLabel)
            @php
                $isArray   = is_array($optLabel);
                $oVal      = $isArray ? $optLabel['value']   : $optVal;
                $oText     = $isArray ? $optLabel['label']   : $optLabel;
                $oDisabled = $isArray && !empty($optLabel['disabled']);
                $oDesc     = $isArray ? ($optLabel['description'] ?? '') : '';
                $oId       = $name . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $oVal);
                $isChecked = ((string) $oVal === $selected);
                $count++;
            @endphp
            @if($isButton)
                <label class="mad-radio-btn{{ $isChecked ? ' is-active' : '' }}{{ ($disabled || $oDisabled) ? ' is-disabled' : '' }}" for="{{ $oId }}">
                    <input
                        type="radio"
                        id="{{ $oId }}"
                        name="{{ $name }}"
                        value="{{ $oVal }}"
                        class="mad-radio-btn-input"
                        @if($isChecked) checked @endif
                        @if($disabled || $oDisabled) disabled @endif
                        {!! $attrs !!}
                    >
                    <span class="mad-radio-btn-label">{{ $oText }}</span>
                </label>
            @else
                <label class="mad-radio-wrap" for="{{ $oId }}">
                    <input
                        type="radio"
                        id="{{ $oId }}"
                        name="{{ $name }}"
                        value="{{ $oVal }}"
                        class="mad-radio"
                        @if($isChecked) checked @endif
                        @if($disabled || $oDisabled) disabled @endif
                        {!! $attrs !!}
                    >
                    <span class="mad-radio-circle"></span>
                    <span class="mad-radio-label">
                        {{ $oText }}
                        @if($oDesc)<span class="mad-field-hint" style="display:block;margin-top:1px;">{{ $oDesc }}</span>@endif
                    </span>
                </label>
            @endif
            @if($breakItems > 0 && $inline && !$isButton && $count % $breakItems === 0 && !$loop->last)
                <div style="flex-basis:100%;height:0;"></div>
            @endif
        @endforeach
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
@endif
