@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'items' => [], 'selected' => [], 'orientation' => 'vertical', 'limit' => -1, 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false])
@php
    // Lista posta pelo código (`$this->form->setItems()`) vence a do Blade: é a
    // que o reload_sort_list mostrou (fw#228).
    $items = \Mad\Support\MadItems::fromForm((string) $name) ?? $items;
    // Lista de objetos [['value' => …, 'label' => …]] vira [valor => rótulo] (\Mad\Support\MadItems).
    if (is_array($items)) { $items = \Mad\Support\MadItems::normalize($items); }
    $required   = !empty($required);
    $disabled   = !empty($disabled);
    $hasError   = !empty($error);
    $reqStar    = $required ? ' <span class="mad-required">*</span>' : '';
    $limit      = (int)$limit;
    $horizontal = ($orientation === 'horizontal');
    // Sem `selected` na tag, vale a ordem do registro aberto (MadForm fill) —
    // antes a edição abria na ordem original das opções.
    if (empty($selected) && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx)) {
            $selected = $_ctx[$name];
        }
    }
    // Lista de strings: por vírgula, JSON ('["c","a"]') ou array.
    $selected = \Mad\Form\MadForm::selectionKeys($selected, ',');
    // "Valor padrão" (`default`, lista separada por vírgula = a ordem inicial):
    // só no cadastro novo e sem `selected` escrito na tag.
    $selected = \Mad\Support\MadFieldValue::withDefaultSelection((string) $name, $selected, $default ?? null);
    // Reorder items: selected first (in order), then remaining
    $orderedItems = [];
    foreach ($selected as $selKey) {
        if (array_key_exists($selKey, $items)) {
            $orderedItems[$selKey] = $items[$selKey];
        }
    }
    foreach ($items as $k => $v) {
        if (!array_key_exists((string)$k, $orderedItems)) {
            $orderedItems[(string)$k] = $v;
        }
    }
    \Mad\Form\MadFormRegistry::register($name, 'sort-list', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <span class="mad-label">{!! $label !!}{!! $reqStar !!}</span>
    @endif
    <div
        class="mad-sort-list{{ $horizontal ? ' mad-sort-list-h' : '' }}"
        x-data="madSortList({ limit: {{ $limit }} })"
        x-ref="list"
        data-mad-sort-list="{{ $name }}"
        data-mad-sort-list-field-name="{{ $name }}"
        @if($disabled) style="pointer-events:none;opacity:.6;" @endif
    >
        @foreach($orderedItems as $key => $itemLabel)
            <div class="mad-sort-item" data-key="{{ $key }}">
                <span class="mad-sort-handle"><i data-lucide="grip-vertical"></i></span>
                <span class="mad-sort-label">{{ $itemLabel }}</span>
                <input type="hidden" name="{{ $name }}[]" value="{{ $key }}">
            </div>
        @endforeach
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
