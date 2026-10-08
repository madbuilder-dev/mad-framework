@php
    $label     = $label     ?? '';
    $name      = $name      ?? '';
    // alias: o editor visual grava :items — aceitar os dois nomes
    // (drift editor-runtime, auditoria 14/jul/2026)
    $options   = $options   ?? ($items ?? []);
    // Lista de objetos [['value' => …, 'label' => …]] vira [valor => rótulo] (\Mad\Support\MadItems).
    if (is_array($options)) { $options = \Mad\Support\MadItems::normalize($options); }
    $selected  = $selected  ?? [];
    $layout    = $layout    ?? 'vertical';
    $as        = $as        ?? '';
    $size      = $size      ?? '';
    $breakItems = (int) ($breakItems ?? 0);
    $hint      = $hint      ?? '';
    $error     = $error     ?? '';
    $required  = $required  ?? false;
    $disabled  = $disabled  ?? false;
    $separator = $separator ?? ',';
    $required   = !empty($required);
    $disabled   = !empty($disabled);
    $hasError   = !empty($error);
    $reqStar    = $required ? ' <span class="mad-required">*</span>' : '';
    $horizontal = ($layout === 'horizontal');

    // Variantes visuais. A classe base `mad-checkbox-group` fica SEMPRE no
    // container: o op `reload_checkbox_group` (mad.js) e a coleta de valores do
    // mad-livewire.js keiam nela. Diferente do radio, que TROCA a base por
    // `mad-radio-btn-group`, aqui as variantes são apenas modificadores.
    $isButton  = ($as === 'button');
    $sizeCls   = $size === 'sm' ? ' mad-checkbox-group-sm'
               : ($size === 'lg' ? ' mad-checkbox-group-lg' : '');
    $wrapClass = 'mad-checkbox-group'
               . ($horizontal ? ' mad-checkbox-group-h' : '')
               . ($isButton   ? ' mad-checkbox-group-btn' : '')
               . $sizeCls;
    // Quebra a cada N: só faz sentido em linha e fora do segmented (que já é
    // uma peça única). Uma variável só, para o atributo e o loop não divergirem.
    $breakOn   = ($breakItems > 0 && $horizontal && !$isButton) ? $breakItems : 0;
    $count     = 0;

    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');

    // Auto-resolve selected do MadRenderContext (MadForm fill)
    if (empty($selected) && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx)) {
            $selected = $_ctx[$name];
        }
    }
    // Auto-load selected from pivot table (mode=table). O `name` vai junto: o
    // formulário guarda o que este campo entregou marcado, e o Salvar só
    // desmarca o que consta lá (ver MadForm::pivotLoaded / pivotShown).
    $__pivotNotice = null;
    if ($mode === 'table' && empty($selected) && $pivotModel && $itemKey) {
        $selected = \Mad\Component\MadRenderContext::loadPivotSelected($pivotModel, $foreignKey, $itemKey, $database, $name);
        $__pivotNotice = \Mad\Component\MadRenderContext::pivotLoadNotice($name);
    }
    // Normaliza para lista de strings. O MadWire devolve a seleção como JSON
    // ('["5","4"]'): com explode() o redesenho da tela perdia todas as marcas.
    $selected = \Mad\Form\MadForm::selectionKeys($selected, $separator);
    // mode=table: o que vai MARCADO para o navegador é a base do Salvar.
    if ($mode === 'table' && $pivotModel && $itemKey) {
        \Mad\Component\MadRenderContext::pivotRendered($name, $selected, array_keys((array) $options), $pivotModel, $foreignKey, $itemKey);
    }
    // Gravação na própria coluna (por vírgula): o que vai MARCADO para o
    // navegador é a base do Salvar — o item da coluna que a lista não mostra
    // não sai dela (ver MadForm::selectionShown).
    if ($mode !== 'table' && $mode !== 'manual') {
        \Mad\Component\MadRenderContext::selectionRendered($name, $selected, array_keys((array) $options), $separator);
    }

    \Mad\Form\MadFormRegistry::register($name, 'checkbox-group', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'mode'       => $mode,
        'pivotModel' => $pivotModel,
        'foreignKey' => $foreignKey,
        'itemKey'    => $itemKey,
        'database'   => $database,
        // Só quando não é a vírgula: o Salvar lê a coluna com o mesmo separador.
        'separator'  => $separator === ',' ? '' : $separator,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <span class="mad-label">{!! $label !!}{!! $reqStar !!}</span>
    @endif
    <div class="{{ $wrapClass }}" data-mad-model="{{ $name }}"@if($breakOn) data-mad-break="{{ $breakOn }}"@endif>
        @foreach($options as $optKey => $optLabel)
            @php
                $chkId     = $name . '_' . $optKey;
                $isChecked = in_array((string)$optKey, $selected);
                $count++;
            @endphp
            <label class="mad-checkbox-wrap" for="{{ $chkId }}">
                <input
                    type="checkbox"
                    id="{{ $chkId }}"
                    name="{{ $name }}[]"
                    value="{{ $optKey }}"
                    class="mad-checkbox"
                    @if($isChecked) checked @endif
                    @if($disabled) disabled @endif
                >
                <span class="mad-checkbox-box"></span>
                <span class="mad-checkbox-label">{{ $optLabel }}</span>
            </label>
            @if($breakOn && $count % $breakOn === 0 && !$loop->last)
                <div class="mad-checkbox-break"></div>
            @endif
        @endforeach
    </div>
    @include('components.partials.options-error', ['optionsError' => $__pivotNotice])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
