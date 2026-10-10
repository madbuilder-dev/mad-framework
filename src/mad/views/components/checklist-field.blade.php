@php
    /**
     * mad-checklist-field — Lista de seleção múltipla com busca, counter e select-all.
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $items       = $items       ?? [];
    // Lista posta pelo código (`$this->form->setItems()`) vence a do Blade: é a
    // que o reload_checklist mostrou (fw#228). O <mad-dbchecklist-field> já
    // chega aqui com ela (e sem consultar o Model).
    $items       = \Mad\Support\MadItems::fromForm((string) $name) ?? $items;
    $columns     = $columns     ?? [];
    $idCol       = $idColumn    ?? 'id';
    $selected    = $selected    ?? [];
    $searchable  = !isset($searchable) || !empty($searchable);
    $height      = \Mad\Support\CssUnits::length((string) ($height ?? ''), '320px');
    $placeholder = $placeholder ?? 'Buscar...';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $rawObjects  = $rawObjects  ?? [];  // objetos originais (do dbchecklist)
    $attrs = $attrs ?? '';
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

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
    $selected = \Mad\Form\MadForm::selectionKeys($selected, ',');

    $normalizedItems = [];
    foreach ($items as $item) {
        if (is_object($item) && method_exists($item, 'toArray')) {
            $normalizedItems[] = $item->toArray();
        } elseif (is_object($item)) {
            $normalizedItems[] = (array)$item;
        } else {
            $normalizedItems[] = (array)$item;
        }
    }

    // Pre-render server-side por coluna. Existem dois fluxos que escapam do
    // x-text simples do Alpine:
    //
    //   • transform callable — assinatura ($value, $object, $row, $column,
    //     $lastRow), igual ao TDataGrid. Quem registra escolhe HTML/texto.
    //   • mask template — `key` com `{campo}`/`{fk->campo}`. Resolve via
    //     $obj->render($mask) (objeto com render()) ou substituição token a token
    //     contra a row, com `{fk->campo}` percorrendo o objeto original da linha
    //     (model Eloquent do dbchecklist; items manuais só têm o 1º nível).
    //
    // Ambos gravam o resultado em `__html_col_<idx>` da row e marcam
    // `_renderedKey` na coluna. O foreach abaixo lê esse campo via x-html.
    // O índice no nome da chave evita conflito quando a `key` contém
    // caracteres como `{`, `}` ou espaços que quebrariam a expressão Alpine.
    foreach ($columns as $_idx => &$_col) {
        $_colKey = $_col['key'] ?? '';
        $_tf     = $_col['transform'] ?? null;
        $_isMask = is_string($_colKey) && strpos($_colKey, '{') !== false && strpos($_colKey, '}') !== false;
        $_storeKey = '__html_col_' . $_idx;

        if ($_tf && is_callable($_tf)) {
            $_col['_renderedKey'] = $_storeKey;
            $_lastObj = null;
            foreach ($normalizedItems as $_iidx => &$_item) {
                $_val = $_item[$_colKey] ?? '';
                $_obj = $rawObjects[$_iidx] ?? (object) $_item;
                $_item[$_storeKey] = call_user_func(
                    $_tf,
                    $_val,
                    $_obj,
                    $_item,
                    $_col,
                    $_lastObj
                );
                $_lastObj = $_obj;
            }
            unset($_item);
        } elseif ($_isMask) {
            $_col['_renderedKey'] = $_storeKey;
            foreach ($normalizedItems as $_iidx => &$_item) {
                $_obj = $rawObjects[$_iidx] ?? null;
                if ($_obj && method_exists($_obj, 'render')) {
                    // Model com método render() resolve `{a}` / `{fk->b}` contra o
                    // próprio modelo (com JOINs lazy). É o caminho usado pelo
                    // dbchecklist quando o picker grava um mask em `key`.
                    $_item[$_storeKey] = $_obj->render($_colKey);
                } else {
                    // Sem método render() — o caso do model Eloquent e dos items
                    // manuais via prop $items: substituição segura, token a token.
                    // `{campo}` lê a row; `{fk->campo}` percorre as relações do
                    // objeto original da linha (lazy-load e FK por convenção,
                    // o mesmo resolvedor do <mad-grid>). Antes a cadeia lia a row
                    // achatada, onde `fk->campo` não existe: a coluna de outra
                    // tabela (ex.: "Fornecedor") saía vazia no dbchecklist.
                    $_item[$_storeKey] = preg_replace_callback(
                        '/\{([^{}]+)\}/',
                        function ($m) use ($_item, $_obj) {
                            $path = trim($m[1]);
                            if (array_key_exists($path, $_item)) {
                                $val = $_item[$path];
                            } elseif (is_object($_obj) && str_contains($path, '->')) {
                                $val = \Mad\Grid\GridRenderHelpers::resolveObjectPath($_obj, $path);
                            } else {
                                $val = '';
                            }
                            return htmlspecialchars(is_scalar($val) ? (string) $val : '', ENT_QUOTES, 'UTF-8');
                        },
                        $_colKey
                    );
                }
            }
            unset($_item);
        }
    }
    unset($_col);

    // mode=table: o que vai MARCADO para o navegador é a base do Salvar.
    if ($mode === 'table' && $pivotModel && $itemKey) {
        \Mad\Component\MadRenderContext::pivotRendered($name, $selected, array_column($normalizedItems, $idCol), $pivotModel, $foreignKey, $itemKey);
    }
    // Gravação na própria coluna (por vírgula): o que vai MARCADO para o
    // navegador é a base do Salvar — o item da coluna que a lista não mostra
    // não sai dela (ver MadForm::selectionShown).
    if ($mode !== 'table' && $mode !== 'manual') {
        \Mad\Component\MadRenderContext::selectionRendered($name, $selected, array_column($normalizedItems, $idCol));
    }
    // Checklist que o código da tela grava (`saveChecklist()`): de que Model
    // saem as opções, o rótulo e quais das marcas lidas ele desenhou — a
    // ligação que a lista não oferece não volta marcada, e ninguém a desmarcou
    // (ver MadForm::checklistDrawn).
    if ($mode !== 'table') {
        \Mad\Component\MadRenderContext::checklistRendered($name, $selected, array_column($normalizedItems, $idCol), (string) $label, $optionsSource ?? null);
    }

    $itemsJson    = json_encode($normalizedItems, JSON_UNESCAPED_UNICODE);
    $selectedJson = json_encode($selected);
    $colsJson     = json_encode($columns, JSON_UNESCAPED_UNICODE);

    \Mad\Form\MadFormRegistry::register($name, 'checklist', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'mode'       => $mode,
        'pivotModel' => $pivotModel,
        'foreignKey' => $foreignKey,
        'itemKey'    => $itemKey,
        'database'   => $database,
        // Checklist sobre tabela (`<mad-dbchecklist-field>`): o Model e a chave das
        // opções, para o Salvar conferir a marca nova. Com itens fixos ou consulta
        // própria, as chaves dos itens que a tela oferece. Só vai para o estado da
        // tela; `records` diz em que coluna o setItems() traz a chave.
        'optionsSource' => $mode === 'manual' ? '' : (is_array($optionsSource ?? null)
            ? $optionsSource
            : ['offered' => array_column($normalizedItems, $idCol), 'records' => $idCol]),
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <span class="mad-label">{!! $label !!}{!! $reqStar !!}</span>
    @endif
    <div class="mad-checklist"
         x-data="madChecklist({ items: {{ $itemsJson }}, ids: {{ $selectedJson }}, idCol: '{{ $idCol }}', cols: {{ $colsJson }} })"
         data-mad-checklist="{{ $name }}"
         {!! $attrs !!}>

        {{-- Toolbar --}}
        <div class="mad-checklist-toolbar">
            @if($searchable)
            <div class="mad-checklist-search-wrap">
                <i data-lucide="search" style="width:13px;height:13px;" class="mad-checklist-search-icon"></i>
                <input type="search" class="mad-checklist-search" placeholder="{{ $placeholder }}" x-model="query"
                    @if($disabled) disabled @endif>
            </div>
            @endif
            <span class="mad-checklist-counter" x-text="@js(mad_t('mad.checklist.counter')).replace(':n', checkedCount).replace(':total', totalCount) + (outsideCount ? ' · ' + @js(mad_t('mad.checklist.counter_outside')).replace(':n', outsideCount) : '')"></span>
            <button type="button" class="mad-checklist-filter-btn"
                :class="showCheckedOnly && 'mad-checklist-filter-active'"
                @click="showCheckedOnly = !showCheckedOnly"
                title="{{ mad_t('mad.checklist.only_checked') }}">
                <i data-lucide="list-filter" style="width:14px;height:14px;"></i>
            </button>
            <label class="mad-checklist-select-all">
                <input type="checkbox" class="mad-checkbox" @change="toggleAll($event)" :checked="allChecked"
                    :indeterminate="checkedCount > 0 && !allChecked"
                    @if($disabled) disabled @endif>
                <span class="mad-checkbox-box"></span>
            </label>
        </div>

        {{-- Tabela --}}
        <div class="mad-checklist-body" style="max-height:{{ $height }};">
            <table class="mad-checklist-table">
                <thead>
                    <tr>
                        <th style="width:36px;"></th>
                        @foreach($columns as $col)
                        @php
                            $thStyle = !empty($col['width']) ? 'width:' . $col['width'] . ';' : '';
                            $thClass = ($col['align'] ?? '') === 'center' ? 'center' : (($col['align'] ?? '') === 'right' ? 'right' : '');
                        @endphp
                        <th style="{{ $thStyle }}" class="{{ $thClass }}">{{ $col['label'] ?? '' }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    {{-- A lista INTEIRA fica no DOM: a busca e o filtro "somente
                         selecionados" ESCONDEM a linha (x-show), não a tiram da
                         tela. Com `x-for` sobre o resultado da busca, a linha
                         escondida deixava de existir — e com ela a marca e os
                         campos das colunas (transform / slot) que só vivem no
                         DOM: o Salvar gravava só o que a busca mostrava. --}}
                    <template x-for="item in items" :key="item[idCol]">
                        <tr x-show="isShown(item)"
                            :class="{ 'mad-checklist-checked': isChecked(item[idCol]), 'mad-checklist-out': !isShown(item) }">
                            {{-- Checkbox do grupo --}}
                            <td style="cursor:pointer" @click.stop="if (!{{ $disabled ? 'true' : 'false' }}) toggle(item[idCol])">
                                <label class="mad-checkbox-wrap" style="margin:0;" @click.prevent>
                                    <input type="checkbox"
                                           :name="'{{ $name }}[]'"
                                           :value="item[idCol]"
                                           class="mad-checkbox"
                                           :checked="isChecked(item[idCol])"
                                           @if($disabled) disabled @endif>
                                    <span class="mad-checkbox-box"></span>
                                </label>
                            </td>
                            {{-- Colunas. Três fluxos:
                                • _renderedKey  → célula veio pré-renderizada do PHP
                                                  (transform callable OU mask). x-html exibe.
                                • type=slot     → consumidor injeta HTML via slot mecanismo
                                                  (TD vazio, alguém preenche depois).
                                • caso default  → x-text simples; clicar na célula toggla
                                                  o item, igual ao checkbox do grupo. --}}
                            @foreach($columns as $ci => $col)
                            @php
                                $colKey      = $col['key'] ?? '';
                                $renderedKey = $col['_renderedKey'] ?? '';
                                $colAlign    = ($col['align'] ?? '') === 'center' ? 'center' : (($col['align'] ?? '') === 'right' ? 'right' : '');
                                $isSlot      = ($col['type'] ?? '') === 'slot';
                                // `key` pode conter `{`, `}`, `->`, espaços (mask). Para os
                                // atributos data-* usamos JSON-encode para o valor virar uma
                                // string JS válida quando passado via Alpine.
                                $colKeyAttr  = json_encode($colKey, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                            @endphp
                            @if($renderedKey)
                            <td class="{{ $colAlign }}" :data-mad-cl-col="{{ $colKeyAttr }}" :data-mad-cl-row="item[idCol]"
                                x-html="item['{{ $renderedKey }}'] ?? ''"></td>
                            @elseif($isSlot)
                            <td class="{{ $colAlign }}" :data-mad-cl-col="{{ $colKeyAttr }}" :data-mad-cl-row="item[idCol]"></td>
                            @else
                            <td class="{{ $colAlign }}" style="cursor:pointer"
                                @click.stop="if (!{{ $disabled ? 'true' : 'false' }}) toggle(item[idCol])"
                                x-text="item[{{ $colKeyAttr }}] ?? ''"></td>
                            @endif
                            @endforeach
                        </tr>
                    </template>
                    <template x-if="filteredItems.length === 0">
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" style="text-align:center;padding:20px;color:var(--mad-text-muted);">
                                Nenhum item
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
    {{-- Só o <mad-dbchecklist-field> repassa (falha ao carregar do banco, com APP_DEBUG). --}}
    @include('components.partials.options-error', ['optionsError' => ($optionsError ?? null) ?? $__pivotNotice])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
