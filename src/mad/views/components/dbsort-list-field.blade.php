@php
    /**
     * mad-dbsort-list-field — Lista ordenavel via drag-and-drop com auto-query do banco.
     *
     * Lista ordenavel de banco. Carrega items do banco via Eloquent/QuerySource
     * no render e renderiza com Sortable.js (mesmo visual do sort-list-field).
     *
     * Props DB:
     *   model       string   Classe do model Eloquent (obrigatorio)
     *   database    string   Conexao (padrao: main_database)
     *   key         string   Campo da PK (padrao: 'id')
     *   display     string   Campo a exibir; suporta template '{nome} ({codigo})'
     *   order-by    string   Campo para ordenar (padrao: igual a display)
     *   query       Builder|null    Eloquent/Query Builder (padrao novo)
     *   filters     array           Atalho: [['field','op','val'], ...]
     *
     * Props sort-list:
     *   name        string   Nome do campo (obrigatorio, envia como name[])
     *   label       string   Label do campo
     *   selected    array/string   Chaves pre-selecionadas/ordenadas
     *   orientation string   vertical | horizontal
     *   limit       int      Maximo de itens (-1 = sem limite)
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro
     *   required    bool
     *   disabled    bool
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model       = $model       ?? '';
    $keyField    = $key         ?? 'id';
    $display     = $display     ?? 'nome';
    $orderBy     = $orderBy     ?? '';
    // Direção do `order-by`. O painel do builder já escrevia `order="desc"` e
    // nada acontecia: as options saíam sempre ASC. Valor fora do par cai em
    // asc no `itemsFromQuery` / no serviço de busca, que normalizam.
    $orderDir    = $order       ?? '';
    $filters     = $filters     ?? [];
    $query       = $query       ?? null;   // Eloquent/Query Builder (novo padrão)
    $selected    = $selected    ?? [];
    $orientation = $orientation ?? 'vertical';
    $limit       = (int)($limit ?? -1);
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $hasError    = !empty($error);
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';
    $horizontal  = ($orientation === 'horizontal');

    // Normalize selected to array of strings
    if (is_string($selected)) {
        $selected = $selected !== '' ? explode(',', $selected) : [];
    }
    $selected = array_map('strval', (array)$selected);

    // :filters (array DSL) → Query Builder interno (100% builder-native) → caminho :query.
    if (!\Mad\Database\QuerySource::isQuery($query) && !empty($filters) && $model) {
        $__m   = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $__m::query();
        \Mad\Database\QuerySource::applyArrayFilters($query, $filters);
    }

    // Carrega items do banco — :query (Builder) tem prioridade sobre model
    $items = [];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            $items = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $query, $keyField, $display, $orderBy ?: null, $orderDir ?: 'asc'
            );
        } catch (\Throwable $e) {
            $items = [];
        }
    } elseif ($model) {
        try {
            $items = \Mad\Form\ModelOptionsLoader::items(
                $model, $keyField, $display,
                $orderBy ?: null, $orderDir ?: 'asc'
            );
        } catch (\Exception $e) {
            $items = [];
        }
    }

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
