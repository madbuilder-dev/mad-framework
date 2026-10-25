@php
    /** @var \Mad\Grid\GridColumn[] $columns */
    /** @var array             $rows */
    /** @var int               $total */
    /** @var int               $totalPages */
    /** @var \Mad\Grid\GridAction[] $actions */
    /** @var \Mad\Grid\GridActionGroup[] $actionGroups */
    /** @var array             $totals */
    /** @var array             $groupData */
    /** @var string            $groupBy */
    /** @var bool              $groupTotal */
    /** @var bool              $searchable */
    /** @var string            $actionSide */
    /** @var int               $page */
    /** @var int               $perPage */
    /** @var string            $sortBy */
    /** @var string            $sortDir */
    /** @var array             $filters */
    /** @var array             $filterOps */

    // Defaults defensivos — o template é normalmente alimentado pelo MadGrid
    // (todas as vars presentes), mas precisa não fatalar em render direto/parcial
    // sem props (ex: smoke, preview do builder). array_* exige array, não null.
    $columns      = $columns      ?? [];
    $rows         = $rows         ?? [];
    $actions      = $actions      ?? [];
    $actionGroups = $actionGroups ?? [];
    $rowHighlightRules = $rowHighlightRules ?? [];
    $totals       = $totals       ?? [];
    // Colunas cujo total soma só a página exibida (o banco não calcula o
    // total geral delas) — a célula do rodapé avisa. Ver _footerTotals().
    $totalsPageOnly = is_array($totalsPageOnly ?? null) ? $totalsPageOnly : [];
    $groupData    = $groupData    ?? [];
    $filters      = $filters      ?? [];
    // Coluna que identifica a linha. Vem do MadDataGrid::rowIdField() (chave do
    // model); 'id' cobre grid alimentado por array e render direto sem props.
    $rowIdField   = $rowIdField   ?? 'id';

    // Numéricos vêm COAGIDOS, não só defaultados. MadRenderContext::push() achata
    // as chaves das props públicas array no topo do contexto, e o render() faz
    // array_merge($props, $viewData, $context) — o contexto VENCE o viewData.
    // `filters` é keyed por campo de coluna e `total`/`totalPages` não são props
    // públicas (só viewData), então uma coluna chamada `total` com filtro ativo
    // injeta $context['total'] = <valor do filtro> e sequestra o rodapé. Com
    // valor de range (array) isso vira number_format(array) → 500.
    // `page`/`perPage` são props públicas e o flatten já os protege.
    $total        = is_numeric($total      ?? 0) ? (int) $total      : 0;
    $totalPages   = is_numeric($totalPages ?? 1) ? (int) $totalPages : 1;
    $page         = is_numeric($page       ?? 1) ? (int) $page       : 1;
    $perPage      = is_numeric($perPage    ?? 10) ? (int) $perPage   : 10;
    $groupBy      = $groupBy      ?? '';
    $sortBy       = $sortBy       ?? '';
    $sortDir      = $sortDir      ?? '';

    $filterOps  = $filterOps ?? [];
    // Filtro avançado (<mad-custom-filters>) — [] quando o grid não o declarou.
    $customFilters = is_array($customFilters ?? null) ? $customFilters : [];
    $cfEnabled     = !empty($customFilters['enabled']);
    $colsConfig    = $colsConfig ?? [];
    $columnChooser = $columnChooser ?? true;
    // Botão do seletor só com alguma coluna que ele ofereça (`not-hideable`
    // tira a coluna dele) — senão abriria um painel vazio.
    $hasChoosable  = (bool) array_filter($colsConfig, fn($c) => !empty($c['hideable']));
    // no-auto-load ainda sem ação do usuário: empty-state vira dica + botão.
    $deferred   = $deferred ?? false;
    // row-click: clicar na linha/card executa a 1ª ação (madDataGrid.onRowClick).
    $rowClick   = (bool) ($rowClick ?? false);
    // A ação do clique na linha é a 1ª que não é de exclusão (onRowClick do
    // mad-ui.js). Escondida pelo perfil, o clique pegaria a ação SEGUINTE,
    // que ninguém escolheu (ex.: "Ver" de uma tela sem acesso → aviso de
    // acesso negado). Como a desabilitada, ela deixa a linha sem clique (#469).
    if ($rowClick) {
        foreach ($actions as $_rcAct) {
            if (!$_rcAct instanceof \Mad\Grid\GridAction || $_rcAct->isDanger) {
                continue;
            }
            if ($_rcAct->permMode() === 'hide') {
                $rowClick = false;
            }
            break;
        }
    }
    // require-filter: a dica pede filtro e $filterMissing vira aviso quando o
    // usuário tentou carregar sem preencher. load-hint troca o texto padrão.
    $requireFilter = $requireFilter ?? false;
    $filterMissing = $filterMissing ?? false;
    // Relatório (sem paginação) que não coube na memória: o texto que pede
    // filtro, no lugar de "Nenhum registro". Vazio = carregou normalmente.
    $tooManyRows   = (string) ($tooManyRows ?? '');
    // "Exportar" é uma das caixinhas do perfil. A decisão vem pronta do
    // MadDataGrid: allow (nada muda) | hide (o menu sai) | disable (os formatos
    // ficam cinza, com a dica do porquê). Render solto/preview: allow.
    $permExport    = $permExport ?? 'allow';
    $_exportDeny   = $permExport === 'disable' ? \Mad\Security\ActionVocab::denyTitle('export') : '';
    $loadButton    = $loadButton ?? true;
    $loadHint      = (string) ($loadHint ?? '');
    // `empty-text` (alias `no-results-message`): o que a listagem vazia diz,
    // no lugar de "Nenhum registro encontrado" (fw#518).
    $emptyText     = trim((string) ($emptyText ?? ''));
    $storageKey = $storageKey ?? '';
    $gridClass  = $gridClass  ?? '';
    $_rowPrefix = $gridClass ? $gridClass . '_' : '';
    // <mad-grid auto-refresh="15">: o Alpine relê a página atual a cada N s.
    $_autoRefresh = (int) ($autoRefreshSeconds ?? 0);
    $_autoRefreshAttrs = $_autoRefresh > 0
        ? ' data-mad-dg-refresh="' . $_autoRefresh . '" data-mad-dg-page="' . (int) $page . '" data-mad-dg-row-prefix="' . e($_rowPrefix) . '"'
        : '';
    // Acabamento da linha (fw#494): density/truncate/header-icons/actions-inline
    // viram data-* no .mad-dg-wrap e o CSS decide — o tema (default/ERP) é
    // escolha do navegador, o servidor não sabe qual está ativo. Sem a prop o
    // atributo não sai e cada tema segue o seu padrão. Os quatro vêm do
    // _rowLookViewData() da grade e são lidos direto, sem reatribuir a
    // variável com padrão: o teste de deriva do Studio toma esse molde por
    // prop de tag, e estes são dados internos da grade.
    $_lookAttrs = ((string) ($gridDensity ?? '') !== '' ? ' data-density="' . e($gridDensity) . '"' : '')
        . (isset($gridTruncate) ? ' data-truncate="' . ($gridTruncate ? 'on' : 'off') . '"' : '')
        . ((string) ($gridHeaderIcons ?? '') !== '' ? ' data-header-icons="' . e($gridHeaderIcons) . '"' : '')
        . (!empty($gridActionsInline) ? ' data-actions-inline="' . e((string) $gridActionsInline) . '"' : '')
        // Celular e lista curta (fw#495): mobile="cards|table", card-columns
        // (escolhido à mão: o cartão mostra todas as colunas da lista) e
        // footer="auto|false|true" — o CSS decide com o tema, como acima.
        . ((string) ($gridMobile ?? '') !== '' ? ' data-mobile="' . e($gridMobile) . '"' : '')
        . (!empty($gridCardColumns) ? ' data-card-columns' : '')
        . ((string) ($gridFooter ?? '') !== '' ? ' data-footer="' . e($gridFooter) . '"' : '');
    $hasActions = !empty($actions) || !empty($actionGroups);
    $hasTotals      = !empty($totals);
    $hasGroupBy     = !empty($groupBy);
    $hasGroupFooters = !empty(array_filter($groupData, fn($item) => $item['type'] === 'group-total'));
    // Relatório por janelas (MadGridWindow): ['html', 'more', 'totals', 'sortWithinGroup'] ou null.
    $gridWindow = $gridWindow ?? null;
    // <mad-grid group-band="cells"> — banda de quebra alinhada às colunas.
    $groupBandCells = ($groupBand ?? '') === 'cells';
    $visibleColumns = array_values(array_filter($columns, fn($c) => !$c->hidden && $c->checkDisplay()));
    // <mad-grid selectable>: coluna de checkbox à ESQUERDA de tudo (inclusive
    // das ações "left") + barra de seleção/ações em lote. Estado no Alpine,
    // espelhado no campo oculto que viaja em toda requisição do grid.
    $selectable     = (bool) ($selectable ?? false);
    $selected       = array_values(array_map('strval', (array) ($selected ?? [])));
    $bulkActions    = $selectable ? array_values((array) ($bulkActions ?? [])) : [];
    $selectionField = (string) ($selectionField ?? '');
    $selPageIds     = [];
    if ($selectable) {
        foreach ($rows as $_sIdx => $_sRow) {
            $selPageIds[] = (string) ($_sRow[$rowIdField] ?? $_sRow['id'] ?? $_sIdx);
        }
    }
    // <mad-row-expand> (fórum #127): coluna estreita do chevron, logo depois da
    // seleção. Entra no colCount — o visibleColCount() do Alpine (estado vazio,
    // banda de quebra, row-detail) e a linha anexada contam com ela.
    $rowExpandAction = (($rowExpandAction ?? null) instanceof \Mad\Grid\GridAction) ? $rowExpandAction : null;
    $hasExpand       = $rowExpandAction !== null;
    $colCount       = count($visibleColumns) + ($hasActions ? 1 : 0) + ($selectable ? 1 : 0) + ($hasExpand ? 1 : 0);

    // per-page 0 = sem paginação: todas as linhas na tela. O "até" saía
    // page × 0 = 0 e o rodapé dizia "1–0 de 5" (fw#311).
    $pgStart  = $total > 0 ? ($perPage > 0 ? ($page - 1) * $perPage + 1 : 1) : 0;
    $pgEnd    = $perPage > 0 ? min($page * $perPage, $total) : $total;

    // Card view
    $cardView    = $cardView ?? false;
    $cardDefault = $cardDefault ?? false;
    $cardBelow   = $cardView ? max(0, (int) ($cardBelow ?? 0)) : 0;
    $cardCols    = $cardCols ?? '';

    $cardTitle = $cardSubtitle = $cardBadge = $cardImage = $cardHighlight = null;
    $cardBody  = [];

    if ($cardView) {
        $hasExplicitRole = false;
        foreach ($visibleColumns as $col) {
            if ($col->cardRole !== '') { $hasExplicitRole = true; break; }
        }
        foreach ($visibleColumns as $col) {
            $role = $col->cardRole;
            if (!$hasExplicitRole) {
                if (!$cardTitle && !$col->isBadge && !$col->isMoney && !$col->isDate && $col->field !== 'id') {
                    $role = 'title';
                } elseif ($col->isBadge && !$cardBadge) {
                    $role = 'badge';
                } elseif ($col->isMoney && !$cardHighlight) {
                    $role = 'highlight';
                } elseif ($col->isDate && !$cardSubtitle) {
                    $role = 'subtitle';
                }
            }
            match ($role) {
                'title'     => $cardTitle     = $col,
                'subtitle'  => $cardSubtitle  = $col,
                'badge'     => $cardBadge     = $col,
                'image'     => $cardImage     = $col,
                'highlight' => $cardHighlight = $col,
                default     => $cardBody[]    = $col,
            };
        }
    }

    // Largura com o texto cortado (fw#494b): a coluna do título fica com a
    // sobra e código/tipo saem inteiros (GridColumn::assignShapes; o CSS só
    // lê isso com truncate ligado ou no tema ERP).
    \Mad\Grid\GridColumn::assignShapes($visibleColumns, is_array($rows ?? null) ? $rows : []);
    $_growN = count(array_filter($visibleColumns, fn($c) => $c->shape === 'grow'));
    // Cartão do celular (fw#495): lugar de cada coluna (data-card na <td>).
    \Mad\Grid\GridColumn::assignCardRoles($visibleColumns, (array) ($gridCardColumns ?? []), is_array($rows ?? null) ? $rows : []);

    // Pre-carrega caches usados pelos editores inline (dbcombo options,
    // dbunique-search token e labels). Centralizado em MadDataGrid::_buildEditCaches
    // para garantir paridade entre o render inicial e o manage_row parcial.
    [$editComboOptions, $editSearchToken, $editSearchPreloaded]
        = \Mad\Grid\MadDataGrid::_buildEditCaches($visibleColumns, $rows);

    // Idem para os filtros tipados (filter-type=): options do dbcombo/multi,
    // token AJAX do dbsearch e rótulo do chip ativo. As queries são memoizadas
    // por configuração e o rótulo sai de um whereIn batelado — o caminho legado
    // abaixo ainda faz um find() por chip, e é justamente o que isto evita.
    [$filterComboOptions, $filterSearchToken, $filterActiveLabels]
        = \Mad\Grid\MadDataGrid::_buildFilterCaches($visibleColumns, $colFilters ?? []);

    // Build page list
    $window = 2;
    $ps     = max(1, $page - $window);
    $pe     = min($totalPages, $page + $window);
    $pages  = [];
    if ($ps > 1) {
        $pages[] = ['type' => 'page', 'n' => 1];
        if ($ps > 2) $pages[] = ['type' => 'ellipsis'];
    }
    for ($i = $ps; $i <= $pe; $i++) {
        $pages[] = ['type' => 'page', 'n' => $i];
    }
    if ($pe < $totalPages) {
        if ($pe < $totalPages - 1) $pages[] = ['type' => 'ellipsis'];
        $pages[] = ['type' => 'page', 'n' => $totalPages];
    }
@endphp

@php
    $colsConfigJson = json_encode($colsConfig, JSON_UNESCAPED_UNICODE);
    // Avisos que a própria grade dá na edição na célula (madDataGrid._cellNotice):
    // salvamento sem resposta do servidor e digitação largada por um redesenho.
    $_editTextJson = array_filter($columns ?? [], fn($c) => $c instanceof \Mad\Grid\GridColumn && $c->editable)
        ? json_encode(['lost' => mad_t('mad.error.cell_no_answer'), 'dropped' => mad_t('mad.error.cell_edit_dropped')], JSON_UNESCAPED_UNICODE)
        : '';
@endphp
<div class="mad-dg-wrap" data-grid-key="{{ $storageKey }}"{{ $rowClick ? ' data-row-click' : '' }}{!! $_autoRefreshAttrs !!}{!! $_lookAttrs !!}@if(isset($focusRowId) && $focusRowId !== null && $focusRowId !== '') data-mad-focus-row="{{ $_rowPrefix }}{{ $focusRowId }}"@endif
     x-data="madDataGrid({ sticky: {{ $sticky ?? false ? 'true' : 'false' }}, storageKey: '{{ $storageKey }}', cols: {{ $colsConfigJson }}, colCount: {{ $colCount }}, search: '{{ addslashes($searchValue ?? '') }}'{{ $cardView ? ', cardView: true' : '' }}{{ $cardDefault ? ', cardDefault: true' : '' }}{{ $cardBelow > 0 ? ', cardBelow: ' . $cardBelow : '' }}{{ $rowClick ? ', rowClick: true' : '' }}{{ $_editTextJson !== '' ? ', editText: ' . $_editTextJson : '' }}@if($selectable), selectable: true, selected: {{ json_encode($selected) }}, pageIds: {{ json_encode($selPageIds) }}, bulk: {{ json_encode(array_map(fn($b) => (!empty($b['hidden']) || !empty($b['deny'])) ? null : ['m' => (string) ($b['method'] ?? ''), 'c' => (string) ($b['confirm'] ?? ''), 'min' => (int) ($b['min'] ?? 1)], $bulkActions)) }}, selText: {{ json_encode(['none' => __('grid.selected_none'), 'one' => __('grid.selected_one'), 'many' => __('grid.selected_many')]) }}@endif })"
     x-init="init($el)"
     @mad-dg-page="handlePage($event)"
     @mad-dg-call="handleCall($event)"
     @mad-dg-filter="handleFilter($event)"@if($rowClick)
     @click="onRowClick($event)"@endif>

    {{-- ── Toolbar (busca + exportação + column chooser) ────────────── --}}
    @php
        // Mesmas condições dos botões lá embaixo: perfil sem exportar
        // (`hide`) ou todas as colunas `not-hideable` desenhavam a barra vazia.
        $hasExport  = ($exportable ?? true) && $permExport !== 'hide';
        // `<mad-grid export-label>` (fórum #74): texto ao lado do ícone.
        // Vazio = só o ícone, como sempre foi — o @if do <span> fica na
        // coluna 0 para o botão sem texto sair byte a byte igual ao de antes.
        $_exportLabel = trim((string) ($exportLabel ?? ''));
        $hasRefresh = $refreshable ?? false;
        $hasChooser = $columnChooser && !$hasActions && $hasChoosable;
        $showToolbar = $searchable || $hasExport || $hasChooser || ($cardView && empty($gridCompact)) || $hasRefresh || $cfEnabled;
    @endphp
    @if($showToolbar)
    <div class="mad-dg-toolbar">
        @if($searchable)
        <input type="search" class="mad-input mad-dg-search" placeholder="{{ __('grid.search_placeholder') }}" x-model="search" @input="handleSearch()" @search="handleSearch()">
        @endif
        <div class="mad-dg-toolbar-right">
            {{-- Filtro avançado: botão "Filtros" + popover (teleportado pro body). --}}
            @if($cfEnabled)
            @include('components.data-grid-custom-filters', [
                'customFilters' => $customFilters,
                'storageKey'    => $storageKey,
                'gridClass'     => $gridClass,
                'cfTotal'       => $deferred ? null : $total,
            ])
            @endif
            @if($hasRefresh)
            <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" mad:click="onReload" title="{{ __('grid.refresh') }}">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i>
            </button>
            @endif
            @if($cardView && empty($gridCompact))
            <div class="mad-dg-view-toggle">
                <button type="button" class="mad-dg-view-btn" :class="{ 'active': viewMode === 'table' }"
                        @click="setViewMode('table')" title="{{ __('grid.view_table') }}">
                    <i data-lucide="table-2" style="width:15px;height:15px;"></i>
                </button>
                <button type="button" class="mad-dg-view-btn" :class="{ 'active': viewMode === 'card' }"
                        @click="setViewMode('card')" title="{{ __('grid.view_cards') }}">
                    <i data-lucide="layout-grid" style="width:15px;height:15px;"></i>
                </button>
            </div>
            @endif
            @if($hasExport)
            <div class="mad-dg-export-wrap" x-data="{ exportOpen: false }">
                {{-- Exportar negado: o próprio botão fica apagado, com a dica, igual
                     à lixeira — antes só os formatos dentro do menu ficavam. --}}
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm"
                        @if($_exportDeny !== '') aria-disabled="true" data-mad-deny title="{{ $_exportDeny }}"
                        @else @click.stop="exportOpen=!exportOpen" :disabled="visibleColCount(true) === 0"
                        :title="visibleColCount(true) === 0 ? @js(__('grid.export_no_columns')) : @js(__('grid.export'))"@endif>
                    <i data-lucide="download" style="width:14px;height:14px;"></i>
@if($_exportLabel !== '')
                    <span class="mad-dg-export-label">{{ $_exportLabel }}</span>
@endif
                </button>
                <div class="mad-dg-export-menu" x-show="exportOpen" x-cloak @click.outside="exportOpen=false">
                    <button type="button" class="mad-dg-export-item"@if($_exportDeny !== '') aria-disabled="true" data-mad-deny title="{{ $_exportDeny }}"@else @click="exportOpen=false; handleExport('CSV')"@endif>
                        <i data-lucide="file-text" style="width:14px;height:14px;"></i> {{ __('grid.export_csv') }}
                    </button>
                    <button type="button" class="mad-dg-export-item"@if($_exportDeny !== '') aria-disabled="true" data-mad-deny title="{{ $_exportDeny }}"@else @click="exportOpen=false; handleExport('XLSX')"@endif>
                        <i data-lucide="file-spreadsheet" style="width:14px;height:14px;"></i> {{ __('grid.export_xlsx') }}
                    </button>
                    <button type="button" class="mad-dg-export-item"@if($_exportDeny !== '') aria-disabled="true" data-mad-deny title="{{ $_exportDeny }}"@else @click="exportOpen=false; handleExport('PDF')"@endif>
                        <i data-lucide="file-type" style="width:14px;height:14px;"></i> {{ __('grid.export_pdf') }}
                    </button>
                </div>
            </div>
            @endif
            @if($hasChooser)
            <button class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-col-chooser-btn"
                    @click.stop="colChooserOpen=!colChooserOpen; colChooserOpen && $nextTick(() => { const p = $refs.colChooserPanel; if (p && p.parentElement) p.style.top = Math.max(0, $el.getBoundingClientRect().bottom - p.parentElement.getBoundingClientRect().top + 4) + 'px'; })"
                    title="{{ __('grid.choose_columns') }}">
                <i data-lucide="columns-2" style="width:14px;height:14px;"></i>
            </button>
            @endif
        </div>
    </div>
    @endif

    {{-- ── Seleção de linhas: campo oculto + barra (contador e ações em lote) ──
         O campo oculto viaja em TODA requisição do componente (o MadWire coleta
         os `__mad_*` do wrapper): paginar/buscar/ação de linha levam a seleção,
         e o MadDataGrid a guarda no state e na sessão (MadGridSelection). --}}
    @if($selectable)
    <input type="hidden" name="{{ $selectionField }}" value="{{ json_encode($selected) }}" :value="JSON.stringify(selected)">
    <div class="mad-dg-selbar" :class="{ 'mad-dg-selbar-active': selected.length > 0 }">
        <div class="mad-dg-selbar-info">
            <i data-lucide="list-checks" style="width:14px;height:14px;"></i>
            <span class="mad-dg-selbar-count" x-text="selCountLabel()">{{ count($selected) === 0 ? __('grid.selected_none') : (count($selected) === 1 ? __('grid.selected_one') : str_replace(':count', (string) count($selected), __('grid.selected_many'))) }}</span>
            <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-selbar-clear" x-show="selected.length > 0"@if(empty($selected)) style="display:none;"@endif @click="clearSelection()">
                {{ __('grid.clear_selection') }}
            </button>
        </div>
        {{-- Ação que o perfil não tem, com "Ações sem permissão = Ocultos": não é
             desenhada (`hidden`, ver MadDataGrid::_selectionViewData). O índice das
             outras NÃO muda — é ele que volta ao servidor no clique. --}}
        @if(array_filter($bulkActions, fn($b) => empty($b['hidden'])))
        <div class="mad-dg-selbar-actions">
            @foreach($bulkActions as $_bi => $_ba)
            @continue(!empty($_ba['hidden']))
            @php
                $_bMin     = (int) ($_ba['min'] ?? 1);
                $_bVariant = in_array(($_ba['variant'] ?? 'primary'), ['primary', 'danger', 'secondary', 'ghost', 'success', 'warning'], true) ? $_ba['variant'] : 'primary';
                $_bHint    = $_bMin > 0 ? trans_choice('grid.bulk_min', $_bMin, ['count' => $_bMin]) : '';
            @endphp
            @if(!empty($_ba['deny']))
            {{-- Recusado, modo Desabilitados: aria-disabled + data-mad-deny, nunca
                 `disabled` — o tema tira o mouse de button:disabled e a dica não
                 aparecia (mesma regra do GridAction::stateAttrs). --}}
            <button type="button" class="mad-btn mad-btn-{{ $_bVariant }} mad-btn-sm mad-dg-bulk-btn" data-bulk-index="{{ $_bi }}" aria-disabled="true" data-mad-deny title="{{ $_ba['deny'] }}">
            @else
            <button type="button" class="mad-btn mad-btn-{{ $_bVariant }} mad-btn-sm mad-dg-bulk-btn"
                    data-bulk-index="{{ $_bi }}"
                    @if($_bMin > 0 && count($selected) < $_bMin) disabled @endif
                    :disabled="selected.length < {{ $_bMin }}"
                    @if($_bHint !== '') :title="selected.length < {{ $_bMin }} ? @js($_bHint) : @js((string) ($_ba['label'] ?? ''))"@endif
                    @click="runBulk({{ $_bi }}, $event)">
            @endif
                @if(!empty($_ba['icon']))<i data-lucide="{{ $_ba['icon'] }}" style="width:14px;height:14px;"></i>@endif
                <span>{{ $_ba['label'] ?? '' }}</span>
            </button>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    {{-- ── Barra de filtros ativos ─────────────────────────────────────── --}}
    @php
        $activeFilters = [];
        // Filtros legados (filter-popover antigo)
        foreach ($visibleColumns as $col) {
            $fVal = $filters[$col->field] ?? '';
            if ($fVal === '' || $fVal === null) continue;
            $fDisplay = $fVal;
            if ($col->filterable && $col->filterType === 'select' && isset($col->filterOpts[$fVal])) {
                $fDisplay = $col->filterOpts[$fVal];
            } elseif ($col->filterable && $col->filterType === 'date' && is_scalar($fVal)) {
                // O <input type=date> manda `Y-m-d`: no chip, como a coluna mostra.
                $fDisplay = \Mad\Grid\MadDataGrid::filterValueLabel($col, (string) $fVal, 'date');
            }
            $activeFilters[] = ['label' => $col->label, 'display' => $fDisplay, 'field' => $col->field, 'type' => 'legacy'];
        }
        // Filtros de coluna seguros (col-filter e filter-type)
        foreach ($visibleColumns as $col) {
            if (!$col->colFilterToken) continue;
            $cfField = $col->colFilterField ?: $col->field;
            $cfVal   = $colFilters[$cfField]['value'] ?? '';
            if ($cfVal === '' || $cfVal === [] || $cfVal === null) continue;

            // Filtro tipado: rótulo vem do cache (zero query por chip) e é
            // formatado pelo tipo — range vira "de X até Y", multi lista com
            // "+N", bool vira Sim/Não.
            if ($col->filterKind !== '') {
                $activeFilters[] = [
                    'label'   => $col->label,
                    'display' => \Mad\Grid\MadDataGrid::filterChipLabel(
                        $col, $cfVal, $filterActiveLabels[$col->field] ?? []
                    ),
                    'token'   => $col->colFilterToken,
                    'type'    => 'col',
                ];
                continue;
            }

            $cfDisplay = $colFilters[$cfField]['display'] ?? $cfVal;
            // Resolve display via model quando o display é o próprio ID (dbcombo)
            if (($cfDisplay === $cfVal || !$cfDisplay) && $col->filterModel && $cfVal !== '') {
                try {
                    $modelClass = $col->filterModel;
                    // Eloquent: load por id via find().
                    $record = $modelClass::find($cfVal);
                    if ($record === null) { throw new \RuntimeException('record not found'); }
                    $displayField = $col->filterDisplay ?: 'nome';
                    $cfDisplay = str_contains($displayField, '{')
                        ? $record->render($displayField)
                        : ($record->$displayField ?? $cfVal);
                } catch (\Throwable $e) {
                }
            }
            $activeFilters[] = ['label' => $col->label, 'display' => $cfDisplay, 'token' => $col->colFilterToken, 'type' => 'col'];
        }

        // Condições do filtro avançado: rótulos já resolvidos no servidor
        // (dbcombo/dbsearch → nome, data no locale, período nomeado). O
        // `index` é o da lista em vigor — o mesmo que onCustomFilterRemove usa.
        $cfChips   = $cfEnabled ? array_values((array) ($customFilters['rules'] ?? [])) : [];
        $cfAny     = $cfEnabled && ($customFilters['match'] ?? 'all') === 'any' && count($cfChips) > 1;
        $cfDropped = $cfEnabled ? (int) ($customFilters['dropped'] ?? 0) : 0;
    @endphp
    @if(!empty($activeFilters) || !empty($cfChips) || $cfDropped > 0)
    <div class="mad-dg-active-filters">
        @if(!empty($activeFilters) || (!empty($cfChips) && !$cfAny))
        <span class="mad-dg-active-filters-label">{{ __('grid.active_filters') }}</span>
        @endif
        @foreach($activeFilters as $af)
        <span class="mad-dg-filter-pill">
            {{ $af['label'] }}: <strong>{{ $af['display'] }}</strong>
            @if($af['type'] === 'col')
            <button class="mad-dg-filter-pill-x" mad:click="onClearColFilter('{{ $af['token'] }}')" title="{{ __('grid.remove') }}">
                <i data-lucide="x" style="width:10px;height:10px;"></i>
            </button>
            @else
            <button class="mad-dg-filter-pill-x" mad:click="onClearFilter('{{ $af['field'] }}')" title="{{ __('grid.remove') }}">
                <i data-lucide="x" style="width:10px;height:10px;"></i>
            </button>
            @endif
        </span>
        @endforeach
        {{-- Filtro avançado: clique no chip reabre o popover com a condição em
             destaque; o × remove só ela. "Qualquer uma:" quando é OU. --}}
        @if(!empty($cfChips))
        @if($cfAny)
        <span class="mad-dg-active-filters-label mad-dg-cf-chips-any">{{ \Mad\I18n\MadLang::t('mad.gridcf.chip_any_prefix') }}</span>
        @endif
        @foreach($cfChips as $cfChip)
        @php $cfIdx = (int) ($cfChip['index'] ?? 0); @endphp
        <span class="mad-dg-cf-chip" role="button" tabindex="0" data-cf-chip="{{ $cfIdx }}"
              title="{{ \Mad\I18n\MadLang::t('mad.gridcf.edit_condition') }}"
              @click="if (!$event.target.closest('.mad-dg-cf-chip-x')) $dispatch('mad-dg-cf-open', { index: {{ $cfIdx }} })"
              @keydown.enter="if ($event.target === $el) { $event.preventDefault(); $dispatch('mad-dg-cf-open', { index: {{ $cfIdx }} }) }"
              @keydown.space="if ($event.target === $el) { $event.preventDefault(); $dispatch('mad-dg-cf-open', { index: {{ $cfIdx }} }) }">
            <span class="mad-dg-cf-chip-txt"><b>{{ $cfChip['label'] ?? '' }}</b> <span class="mad-dg-cf-chip-op">{{ $cfChip['opLabel'] ?? '' }}</span>@if(($cfChip['valueLabel'] ?? '') !== '') {{ $cfChip['valueLabel'] }}@endif</span>
            <button type="button" class="mad-dg-cf-chip-x" mad:click="onCustomFilterRemove({{ $cfIdx }})"
                    aria-label="{{ \Mad\I18n\MadLang::t('mad.gridcf.remove') }}: {{ $cfChip['label'] ?? '' }}">
                <i data-lucide="x" style="width:12px;height:12px;"></i>
            </button>
        </span>
        @endforeach
        @endif
        @if($cfDropped > 0)
        <span class="mad-dg-cf-dropped" role="status">
            <i data-lucide="circle-alert" style="width:13px;height:13px;"></i>
            {{ \Mad\I18n\MadLang::t('mad.gridcf.dropped_warning', ['count' => $cfDropped]) }}
        </span>
        @endif
        @if(!empty($activeFilters) || !empty($cfChips))
        <button class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-clear-all" mad:click="onClearAllFilters">
            {{ __('grid.clear_filters') }}
        </button>
        @endif
    </div>
    @endif

    {{-- ── Sticky ghost (thead + quebras) — só renderizado se sticky=true ── --}}
    @if(!empty($sticky))
    <div class="mad-dg-sticky-ghost" aria-hidden="true"></div>
    @endif

    {{-- ── Column chooser (fora do table-wrap para não ser clipado) ── --}}
    @if(!empty($colsConfig))
    {{-- Painel abre do lado do botão: à esquerda quando ele está no <th> das ações à esquerda. --}}
    <div class="mad-dg-col-chooser-wrap{{ $hasActions && $actionSide === 'left' ? ' mad-dg-col-chooser-wrap-start' : '' }}">
        {{-- O botão posiciona o painel logo abaixo dele (top dinâmico): com top:0
             o painel cobria o botão no <th> das ações e não fechava pelo clique.
             O × do título fecha também. --}}
        <div class="mad-dg-col-chooser-panel"
             x-ref="colChooserPanel"
             x-show="colChooserOpen"
             x-cloak
             @click.outside="colChooserOpen=false"
             @keydown.escape.window="colChooserOpen=false">
            <div class="mad-dg-col-chooser-title">
                <i data-lucide="columns-2" style="width:13px;height:13px;"></i>
                <span class="mad-dg-col-chooser-title-text">{{ __('grid.visible_columns') }}</span>
                <button type="button" class="mad-dg-col-chooser-close" @click.stop="colChooserOpen=false"
                        aria-label="{{ mad_t('mad.btn.close') }}">&times;</button>
            </div>
            @foreach($colsConfig as $colCfg)
            @if(!empty($colCfg['hideable']))
            {{-- hide-below: na tela estreita a coluna fica fora de qualquer jeito —
                 a caixa trava (marcar não a traria) e diz por quê. --}}
            @php $_hbCol = !empty($colCfg['hideBelow']); @endphp
            <label class="mad-dg-col-chooser-item"@if($_hbCol) :class="{ 'mad-dg-col-chooser-narrow': isColNarrow('{{ $colCfg['field'] }}') }"@endif>
                <input type="checkbox"
                       name="check-{{ $colCfg['field'] }}"
                       class="mad-dg-col-chooser-check"
                       x-init="$el.checked = (colVisibility['{{ $colCfg['field'] }}'] !== false)"
                       @if($_hbCol) :disabled="isColNarrow('{{ $colCfg['field'] }}')" @endif
                       @change="toggleCol('{{ $colCfg['field'] }}')"/>
                <span>{{ $colCfg['label'] }}</span>
                @if($_hbCol)
                <small class="mad-dg-col-chooser-hint" x-show="isColNarrow('{{ $colCfg['field'] }}')" x-cloak>{{ __('grid.hidden_narrow') }}</small>
                @endif
            </label>
            @endif
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Tabela ──────────────────────────────────────────────────────── --}}
    <div class="mad-dg-table-wrap"@if($cardView) x-show="viewMode === 'table'"@endif>
        <table class="mad-table mad-dg-table">

            <thead class="mad-dg-head">
                <tr>
                    @if($selectable)
                    <th class="mad-dg-th mad-dg-th-select">
                        <input type="checkbox" class="mad-dg-select mad-dg-select-page"
                               aria-label="{{ __('grid.select_page') }}" title="{{ __('grid.select_page') }}"
                               @if(empty($selPageIds)) disabled @endif
                               :checked="pageAllSelected()" x-effect="$el.indeterminate = pageSomeSelected()"
                               @change="togglePage($event.target.checked)">
                    </th>
                    @endif
                    @if($hasExpand)<th class="mad-dg-th mad-dg-th-expand"></th>@endif
                    @if($hasActions && $actionSide === 'left')
                    {{-- O botão do seletor mora no <th> das ações nos DOIS lados: com
                         ações à esquerda (padrão das listagens geradas) ele não
                         aparecia em lugar nenhum — a barra só o mostra sem ações. --}}
                    <th class="mad-dg-th mad-dg-th-actions" style="width:90px;">
                        @if($columnChooser && $hasChoosable)
                        <button class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-col-chooser-btn"
                                @click.stop="colChooserOpen=!colChooserOpen; colChooserOpen && $nextTick(() => { const p = $refs.colChooserPanel; if (p && p.parentElement) p.style.top = Math.max(0, $el.getBoundingClientRect().bottom - p.parentElement.getBoundingClientRect().top + 4) + 'px'; })"
                                title="{{ __('grid.choose_columns') }}">
                            <i data-lucide="columns-2" style="width:14px;height:14px;"></i>
                        </button>
                        @endif
                    </th>
                    @endif

                    @foreach($visibleColumns as $col)
                    @php
                        // Coluna vinda de config crua (não do setter fluente, que já
                        // normaliza) podia trazer '140' sem unidade — declaração
                        // inválida, descartada, coluna na largura automática.
                        $_thW     = \Mad\Support\CssUnits::length((string) ($col->width ?? ''));
                        $thStyle  = $_thW !== '' ? "width:{$_thW};" : '';
                        $cfKey    = $col->colFilterField ?: $col->field;
                        $isActive = (($col->filterable || $col->hasFilterPopover) && isset($filters[$col->field]) && $filters[$col->field] !== '')
                                 || ($col->colFilterToken && isset($colFilters[$cfKey]) && ($colFilters[$cfKey]['value'] ?? '') !== '');
                        $isSorted = $sortBy === $col->field;
                        $fvInit   = addslashes($filters[$col->field] ?? '');
                        // Ícones (ordenar/filtrar) no lado INTERNO do título: depois
                        // dele à esquerda/centro, antes dele à direita — o título fica
                        // alinhado com os valores da coluna e os ícones grudados nele.
                        // `align` pode vir cru do compiler (`align="end"`), daí o match.
                        $thAlign  = match (strtolower(trim((string) $col->align))) {
                            'right', 'end' => 'right',
                            'center'       => 'center',
                            default        => 'left',
                        };
                    @endphp
                    <th class="mad-dg-th mad-dg-th-align-{{ $thAlign }}{{ $col->isNumeric() && !$col->alignSet ? ' mad-dg-th-num' : '' }}{{ $col->shape === 'grow' ? ' mad-dg-col-grow' : '' }}{{ $isActive ? ' mad-dg-th-filtered' : '' }}{{ $isSorted ? ' mad-dg-th-sorted' : '' }}"
                        :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }"
                        style="{{ $thStyle }}text-align:{{ $col->alignCss() }};{{ $_growN > 1 && $col->shape === 'grow' ? '--mad-dg-grow-w:' . round(40 / $_growN, 2) . '%;' : '' }}">
                        <div class="mad-dg-th-content">
                            @if($thAlign !== 'right')
                            <span class="mad-dg-th-label">{{ $col->label }}</span>
                            @endif

                            {{-- Ordenar + filtrar num grupo (fw#494): inerte no default
                                 (display:contents, o layout de sempre); no ERP ou com
                                 header-icons="hover" ele só aparece no hover, por cima
                                 da borda do título, sem ocupar largura da coluna. --}}
                            <div class="mad-dg-th-icons">
                            @if($col->sortable)
                            <button class="mad-dg-sort-btn{{ $isSorted ? ' mad-dg-sort-'.$sortDir : '' }}"
                                    mad:click="onSort('{{ $col->field }}')"
                                    data-sort-field="{{ $col->field }}"
                                    title="{{ $isSorted && (!empty($gridWindow['sortWithinGroup']) || !empty($sortWithinGroup)) ? __('grid.sort_within_group', ['name' => $col->label]) : __('grid.sort_by', ['name' => $col->label]) }}">
                                <i data-lucide="{{ $isSorted ? ($sortDir==='asc' ? 'arrow-up' : 'arrow-down') : 'arrow-up-down' }}"
                                   style="width:12px;height:12px;"></i>
                            </button>
                            @endif

                            {{-- ── Filtro TIPADO (filter-type=) ────────────────────────── --}}
                            {{-- Campo e operador vêm do token assinado; o cliente manda só
                                 o valor. Widgets NATIVOS de propósito: o popover é
                                 teleportado pro <body> e só a lista fixa de _madInit* do
                                 mad-ui.js é reinicializada lá — um date-picker rico não
                                 sobreviveria ao teleporte. --}}
                            @if($col->filterKind !== '')
                            @php
                                $tfField  = $col->colFilterField ?: $col->field;
                                $tfKind   = $col->filterKind;
                                $tfIsRange = str_ends_with($tfKind, '-range');
                                $tfCur    = $colFilters[$tfField]['value'] ?? ($tfIsRange ? ['', ''] : ($tfKind === 'multi' ? [] : ''));
                                $tfA      = $tfIsRange ? (string)($tfCur[0] ?? '') : '';
                                $tfB      = $tfIsRange ? (string)($tfCur[1] ?? '') : '';
                                $tfScalar = (!$tfIsRange && $tfKind !== 'multi') ? (string)(is_array($tfCur) ? '' : $tfCur) : '';
                                $tfMulti  = $tfKind === 'multi' ? (array)$tfCur : [];
                                $tfOpts   = in_array($tfKind, ['dbcombo', 'multi'], true) && $col->filterModel
                                            ? ($filterComboOptions[$col->field] ?? [])
                                            : $col->filterOpts;
                                $tfDateIn = $tfKind === 'date' || $tfIsRange && $tfKind === 'date-range';
                                $tfNumIn  = $tfKind === 'number' || $tfKind === 'number-range';
                                $tfPh     = $col->filterPlaceholder ?: __('grid.filter_placeholder');
                            @endphp
                            <div class="mad-dg-filter-wrap" data-filter-field="{{ $tfField }}"
                                 x-data="{
                                     open: false,
                                     fv: {{ json_encode($tfScalar) }},
                                     fa: {{ json_encode($tfA) }},
                                     fb: {{ json_encode($tfB) }},
                                     fm: {{ json_encode(array_values(array_map('strval', $tfMulti))) }},
                                     _grid: null,
                                     _token: '{{ $col->colFilterToken }}',
                                     _kind: '{{ $tfKind }}',
                                     openPopover() {
                                         this._grid = this.$el.closest('.mad-dg-wrap');
                                         if (typeof window._madPositionFilterPopover === 'function')
                                             window._madPositionFilterPopover(this.$el);
                                     },
                                     value() {
                                         if (this._kind.endsWith('-range')) return [this.fa, this.fb];
                                         if (this._kind === 'multi') return this.fm;
                                         return this.fv;
                                     },
                                     apply() {
                                         const w = this._grid || this.$el.closest('[mad-component]');
                                         // display vazio: o rótulo do chip é resolvido no
                                         // servidor (filterChipLabel + cache de labels).
                                         if (w) MadWire.call(w, 'onColFilter', [this._token, this.value(), '']);
                                         this.open = false;
                                     },
                                     clear() {
                                         this.fv = ''; this.fa = ''; this.fb = ''; this.fm = [];
                                         const w = this._grid || this.$el.closest('[mad-component]');
                                         if (w) MadWire.call(w, 'onClearColFilter', [this._token]);
                                         this.open = false;
                                     }
                                 }">
                                <button class="mad-dg-filter-btn{{ $isActive ? ' mad-dg-filter-active' : '' }}"
                                        @click.stop="open ? open=false : openPopover()"
                                        title="{{ __('grid.filter_by', ['name' => $col->label]) }}">
                                    <i data-lucide="{{ $isActive ? 'filter-x' : 'filter' }}"
                                       style="width:12px;height:12px;"></i>
                                </button>
                                <div class="mad-dg-filter-popover{{ $tfIsRange ? ' mad-dg-filter-popover-range' : '' }}"
                                     x-show="open" x-cloak
                                     @click.outside="open=false"
                                     @keydown.escape.window="open=false">

                                    {{-- filter-placeholder (fw ≥ 5.18): nos selects vira o
                                         data-placeholder do MAD Select (estado vazio) + o
                                         label da option "" (fallback nativo pré-init); no
                                         multi o placeholder do widget; no dbsearch o hint.
                                         date/date-range ficam fora (input nativo de data
                                         ignora placeholder) e number-range tem Mín/Máx
                                         próprios. --}}
                                    @if($tfKind === 'select' || $tfKind === 'dbcombo')
                                    <select class="mad-select mad-dg-filter-input" x-model="fv" data-mad-select
                                            data-placeholder="{{ $col->filterPlaceholder }}">
                                        <option value="">{{ $col->filterPlaceholder ?: __('grid.all') }}</option>
                                        @foreach($tfOpts as $optVal => $optLabel)
                                        <option value="{{ $optVal }}">{{ $optLabel }}</option>
                                        @endforeach
                                    </select>

                                    @elseif($tfKind === 'bool')
                                    <select class="mad-select mad-dg-filter-input" x-model="fv" data-mad-select
                                            data-placeholder="{{ $col->filterPlaceholder }}">
                                        <option value="">{{ $col->filterPlaceholder ?: __('grid.all') }}</option>
                                        <option value="{{ $col->filterTrue }}">{{ __('grid.yes') }}</option>
                                        <option value="{{ $col->filterFalse }}">{{ __('grid.no') }}</option>
                                    </select>

                                    @elseif($tfKind === 'multi')
                                    <select class="mad-select mad-dg-filter-input mad-dg-filter-multi"
                                            multiple data-mad-multiselect x-model="fm"
                                            data-placeholder="{{ $col->filterPlaceholder }}">
                                        @foreach($tfOpts as $optVal => $optLabel)
                                        <option value="{{ $optVal }}">{{ $optLabel }}</option>
                                        @endforeach
                                    </select>

                                    @elseif($tfKind === 'dbsearch')
                                    <select class="mad-select mad-dg-filter-input" x-model="fv"
                                            data-mad-dbsearch
                                            data-mad-search-token="{{ $filterSearchToken[$col->field] ?? '' }}"
                                            data-min-length="{{ $col->filterMinLength }}"
                                            data-placeholder="{{ $col->filterPlaceholder ?: __('grid.filter_search_hint') }}">
                                        <option value="">{{ __('grid.all') }}</option>
                                    </select>

                                    @elseif($tfKind === 'date')
                                    <input type="date" class="mad-input mad-dg-filter-input" x-model="fv"
                                           @keydown.enter="apply()">

                                    @elseif($tfKind === 'date-range')
                                    <div class="mad-dg-filter-range">
                                        <input type="date" class="mad-input" x-model="fa"
                                               aria-label="{{ __('grid.filter_from') }}" @keydown.enter="apply()">
                                        <span class="mad-dg-filter-range-sep">–</span>
                                        <input type="date" class="mad-input" x-model="fb"
                                               aria-label="{{ __('grid.filter_to') }}" @keydown.enter="apply()">
                                    </div>

                                    @elseif($tfKind === 'number')
                                    <input type="number" class="mad-input mad-dg-filter-input" x-model="fv"
                                           placeholder="{{ $tfPh }}" @keydown.enter="apply()">

                                    @elseif($tfKind === 'number-range')
                                    <div class="mad-dg-filter-range">
                                        <input type="number" class="mad-input" x-model="fa"
                                               placeholder="{{ __('grid.filter_min') }}" @keydown.enter="apply()">
                                        <span class="mad-dg-filter-range-sep">–</span>
                                        <input type="number" class="mad-input" x-model="fb"
                                               placeholder="{{ __('grid.filter_max') }}" @keydown.enter="apply()">
                                    </div>

                                    @else
                                    <input type="text" class="mad-input mad-dg-filter-input" x-model="fv"
                                           placeholder="{{ $tfPh }}" @keydown.enter="apply()">
                                    @endif

                                    <div class="mad-dg-filter-btns">
                                        <button type="button" class="mad-btn mad-btn-primary mad-btn-sm" @click="apply()">
                                            <i data-lucide="search" style="width:12px;height:12px;"></i>
                                            {{ __('grid.filter') }}
                                        </button>
                                        <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-filter-clear"
                                                @click="clear()">
                                            <i data-lucide="x" style="width:12px;height:12px;"></i>
                                            {{ __('grid.clear') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- ── Filtro simples (aplica no Enter ou no botão Filtrar) ── --}}
                            @if($col->filterable)
                            @php $fvJs = json_encode($filters[$col->field] ?? ''); @endphp
                            <div class="mad-dg-filter-wrap" data-filter-field="{{ $col->field }}" x-data="{
                                     open: false, fv: {{ $fvJs }}, _grid: null,
                                     openPopover() {
                                         this._grid = this.$el.closest('.mad-dg-wrap');
                                         if (typeof window._madPositionFilterPopover === 'function')
                                             window._madPositionFilterPopover(this.$el);
                                     },
                                     applyFilter(detail) {
                                         const target = this._grid || this.$el.closest('.mad-dg-wrap');
                                         if (target) target.dispatchEvent(new CustomEvent('mad-dg-filter', { bubbles: true, detail }));
                                     }
                                 }">
                                <button class="mad-dg-filter-btn{{ $isActive ? ' mad-dg-filter-active' : '' }}"
                                        @click.stop="open ? open=false : openPopover()"
                                        title="{{ __('grid.filter_by', ['name' => $col->label]) }}">
                                    <i data-lucide="{{ $isActive ? 'filter-x' : 'filter' }}"
                                       style="width:12px;height:12px;"></i>
                                </button>
                                <div class="mad-dg-filter-popover"
                                     x-show="open"
                                     x-cloak
                                     @click.outside="open=false"
                                     @keydown.escape.window="open=false">

                                    @if($col->filterType === 'select')
                                    <select class="mad-select mad-dg-filter-input"
                                            x-model="fv"
                                            @change="applyFilter({field:'{{ $col->field }}',value:fv})">
                                        <option value="">{{ __('grid.all') }}</option>
                                        @foreach($col->filterOpts as $optVal => $optLabel)
                                        @php $selAttr = (isset($filters[$col->field]) && $filters[$col->field] == $optVal) ? 'selected' : ''; @endphp
                                        <option value="{{ $optVal }}" {{ $selAttr }}>{{ $optLabel }}</option>
                                        @endforeach
                                    </select>

                                    @elseif($col->filterType === 'date')
                                    <input type="date" class="mad-input mad-dg-filter-input"
                                           x-model="fv"
                                           @keydown.enter="open=false; applyFilter({field:'{{ $col->field }}',value:fv})">

                                    @else
                                    <input type="text"
                                           class="mad-input mad-dg-filter-input"
                                           placeholder="{{ __('grid.filter_placeholder') }}"
                                           x-model="fv"
                                           @keydown.enter="open=false; applyFilter({field:'{{ $col->field }}',value:fv})">
                                    @endif

                                    <div class="mad-dg-filter-btns">
                                        <button type="button" class="mad-btn mad-btn-primary mad-btn-sm"
                                                @click="open=false; applyFilter({field:'{{ $col->field }}',value:fv})">
                                            <i data-lucide="search" style="width:12px;height:12px;"></i>
                                            {{ __('grid.filter') }}
                                        </button>
                                        <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-filter-clear"
                                                @click="fv=''; open=false; applyFilter({field:'{{ $col->field }}',value:''})">
                                            <i data-lucide="x" style="width:12px;height:12px;"></i>
                                            {{ __('grid.clear') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- ── Col-filter seguro (token criptografado) ── --}}
                            {{-- filterKind === '' é obrigatório: o filtro tipado TAMBÉM
                                 recebe colFilterToken (cunhado no render), e sem o guard
                                 os dois popovers apareceriam na mesma coluna. --}}
                            @if($col->colFilterToken && $col->filterKind === '')
                            @php
                                $cfField  = $col->colFilterField ?: $col->field;
                                $cfLabel  = $col->label;
                                $cfActive = isset($colFilters[$cfField]) && $colFilters[$cfField]['value'] !== '';
                                $cfValue  = $colFilters[$cfField]['value'] ?? '';
                                $cfHtml   = $col->filterPopoverHtml ?? '';
                            @endphp
                            <div class="mad-dg-filter-wrap" data-filter-field="{{ $cfField }}"
                                 x-data="{
                                     open: false,
                                     filter_value: '{{ addslashes($cfValue) }}',
                                     _grid: null,
                                     _pop: null,
                                     _display: '',
                                     _token: '{{ $col->colFilterToken }}',
                                     openPopover() {
                                         this._grid = this.$el.closest('.mad-dg-wrap');
                                         this._pop  = this.$el.querySelector('.mad-dg-filter-popover');
                                         if (typeof window._madPositionFilterPopover === 'function')
                                             window._madPositionFilterPopover(this.$el);
                                         // Captura display ao mudar o select/input no popover
                                         this.$nextTick(() => {
                                             const pop = this._pop;
                                             if (!pop) return;
                                             const sel = pop.querySelector('select');
                                             if (sel) {
                                                 const capture = () => {
                                                     if (sel._madSelect) {
                                                         const v = sel._madSelect.getValue();
                                                         const itemEl = sel._madSelect.getItem(v);
                                                         this._display = itemEl ? itemEl.textContent.trim() : v;
                                                         this.filter_value = v;
                                                     } else {
                                                         this.filter_value = sel.value;
                                                         this._display = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : sel.value;
                                                     }
                                                 };
                                                 sel.addEventListener('change', capture);
                                             }
                                             const inp = pop.querySelector('input:not(.mad-sel-input):not([type=hidden])');
                                             if (inp && !sel) {
                                                 inp.addEventListener('input', () => { this.filter_value = inp.value; this._display = inp.value; });
                                             }
                                         });
                                     },
                                     apply() {
                                         const w = this._grid || this.$el.closest('[mad-component]');
                                         const val = this.filter_value;
                                         const display = this._display || val;
                                         if (w) MadWire.call(w, 'onColFilter', [this._token, val, display]);
                                         this.open = false;
                                     },
                                     clear() {
                                         this.filter_value = '';
                                         const pop = this._pop || this.$el.querySelector('.mad-dg-filter-popover');
                                         if (pop) {
                                             const sel = pop.querySelector('select');
                                             if (sel) { if (sel._madSelect) sel._madSelect.clear(true); else sel.value = ''; }
                                             const inp = pop.querySelector('input');
                                             if (inp) inp.value = '';
                                         }
                                         const w = this._grid || this.$el.closest('[mad-component]');
                                         if (w) MadWire.call(w, 'onClearColFilter', [this._token]);
                                         this.open = false;
                                     }
                                 }">
                                <button class="mad-dg-filter-btn{{ $cfActive ? ' mad-dg-filter-active' : '' }}"
                                        @click.stop="open ? open=false : openPopover()"
                                        title="{{ __('grid.filter_by', ['name' => $cfLabel]) }}">
                                    <i data-lucide="{{ $cfActive ? 'filter-x' : 'filter' }}"
                                       style="width:12px;height:12px;"></i>
                                </button>
                                <div class="mad-dg-filter-popover mad-dg-filter-popover-advanced"
                                     x-show="open"
                                     x-cloak
                                     @click.outside="open=false"
                                     @keydown.escape.window="open=false">

                                    @if(!empty($cfHtml))
                                        {!! $cfHtml !!}
                                    @else
                                        <input type="text"
                                               class="mad-input mad-dg-filter-input"
                                               placeholder="{{ __('grid.filter_placeholder_named', ['name' => $cfLabel]) }}"
                                               x-model="filter_value"
                                               @keydown.enter="apply()">
                                    @endif

                                    <div class="mad-dg-filter-btns">
                                        <button type="button" class="mad-btn mad-btn-primary mad-btn-sm"
                                                @click="apply()">
                                            <i data-lucide="search" style="width:12px;height:12px;"></i>
                                            {{ __('grid.filter') }}
                                        </button>
                                        <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm"
                                                @click="clear()">
                                            <i data-lucide="x" style="width:12px;height:12px;"></i>
                                            {{ __('grid.clear') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- ── Filtro Popover legado (com botões Filtrar/Limpar + operador) ── --}}
                            @if($col->hasFilterPopover)
                            @php
                                $fpField  = $col->field;
                                $fpLabel  = $col->label;
                                $fpFvJs   = json_encode($filters[$fpField] ?? '');
                                $fpOpJs   = json_encode($filterOps[$fpField] ?? 'like');
                            @endphp
                            <div class="mad-dg-filter-wrap" data-filter-field="{{ $fpField }}"
                                 x-data="{
                                     open: false,
                                     filter_value:    {{ $fpFvJs }},
                                     filter_operator: {{ $fpOpJs }},
                                     _grid: null,
                                     openPopover() {
                                         this._grid = this.$el.closest('.mad-dg-wrap');
                                         if (typeof window._madPositionFilterPopover === 'function')
                                             window._madPositionFilterPopover(this.$el);
                                     },
                                     _dispatchFilter(detail) {
                                         const target = this._grid || this.$el.closest('.mad-dg-wrap');
                                         if (target) target.dispatchEvent(new CustomEvent('mad-dg-filter', { bubbles: true, detail }));
                                     },
                                     apply() {
                                         this._dispatchFilter({ field: '{{ $fpField }}', value: this.filter_value, op: this.filter_operator });
                                         this.open = false;
                                     },
                                     clear() {
                                         this.filter_value    = '';
                                         this.filter_operator = 'like';
                                         this._dispatchFilter({ field: '{{ $fpField }}', value: '', op: 'like' });
                                         this.open = false;
                                     }
                                 }">
                                <button class="mad-dg-filter-btn{{ $isActive ? ' mad-dg-filter-active' : '' }}"
                                        @click.stop="open ? open=false : openPopover()"
                                        title="{{ __('grid.filter_by', ['name' => $fpLabel]) }}">
                                    <i data-lucide="{{ $isActive ? 'filter-x' : 'filter' }}"
                                       style="width:12px;height:12px;"></i>
                                </button>
                                <div class="mad-dg-filter-popover mad-dg-filter-popover-advanced"
                                     x-show="open"
                                     x-cloak
                                     @click.outside="open=false"
                                     @keydown.escape.window="open=false">

                                    {{-- Conteúdo customizado ou input padrão --}}
                                    @if(!empty($col->filterPopoverHtml))
                                    {!! $col->filterPopoverHtml !!}
                                    @else
                                    <input type="text"
                                           class="mad-input mad-dg-filter-input"
                                           placeholder="{{ __('grid.filter_placeholder_named', ['name' => $fpLabel]) }}"
                                           x-model="filter_value"
                                           @keydown.enter="apply()">
                                    @endif

                                    {{-- Seletor de operador (opcional) --}}
                                    @if($col->filterOpSelect)
                                    <select class="mad-select mad-dg-filter-op" x-model="filter_operator">
                                        <option value="like">{{ __('grid.op_contains') }}</option>
                                        <option value="=">{{ __('grid.op_equal') }}</option>
                                        <option value="!=">{{ __('grid.op_not_equal') }}</option>
                                        <option value=">=">≥ {{ __('grid.op_gte') }}</option>
                                        <option value="<=">≤ {{ __('grid.op_lte') }}</option>
                                        <option value=">">› {{ __('grid.op_gt') }}</option>
                                        <option value="<">‹ {{ __('grid.op_lt') }}</option>
                                    </select>
                                    @endif

                                    {{-- Botões Filtrar / Limpar --}}
                                    <div class="mad-dg-filter-btns">
                                        <button type="button" class="mad-btn mad-btn-primary mad-btn-sm"
                                                @click="apply()">
                                            <i data-lucide="search" style="width:12px;height:12px;"></i>
                                            {{ __('grid.filter') }}
                                        </button>
                                        <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm"
                                                @click="clear()">
                                            <i data-lucide="x" style="width:12px;height:12px;"></i>
                                            {{ __('grid.clear') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @endif
                            </div>

                            @if($thAlign === 'right')
                            <span class="mad-dg-th-label">{{ $col->label }}</span>
                            @endif
                        </div>
                    </th>
                    @endforeach

                    @if($hasActions && $actionSide === 'right')
                    <th class="mad-dg-th mad-dg-th-actions" style="width:90px;">
                        @if($columnChooser && $hasChoosable)
                        <button class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-col-chooser-btn"
                                @click.stop="colChooserOpen=!colChooserOpen; colChooserOpen && $nextTick(() => { const p = $refs.colChooserPanel; if (p && p.parentElement) p.style.top = Math.max(0, $el.getBoundingClientRect().bottom - p.parentElement.getBoundingClientRect().top + 4) + 'px'; })"
                                title="{{ __('grid.choose_columns') }}">
                            <i data-lucide="columns-2" style="width:14px;height:14px;"></i>
                        </button>
                        @endif
                    </th>
                    @endif
                </tr>
            </thead>

            <tbody class="mad-dg-body">
                {{-- Empty-state SEMPRE no DOM (oculto quando ha linhas) p/ o
                     remove_row restaurar "Nenhum registro" sem reload. --}}
                <tr class="mad-dg-empty-row"@if(!empty($rows)) style="display:none;"@endif>
                    <td :colspan="visibleColCount()" class="mad-dg-empty">
                        <div class="mad-dg-empty-inner">
                            @if($deferred)
                            {{-- Tentativa sem filtro: mesmo ícone e mesma cor do tema da dica
                                 inicial (antes era funil laranja fixo, destoava do tema). O que
                                 muda é o texto e o role=alert para leitor de tela. --}}
                            <i data-lucide="search" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"></i>
                            @if($filterMissing)
                            <span class="mad-dg-filter-required" role="alert">{{ $loadHint !== '' ? $loadHint : __('grid.filter_required') }}</span>
                            @else
                            <span>{{ $loadHint !== '' ? $loadHint : ($requireFilter ? __('grid.filter_required_hint') : __('grid.load_hint')) }}</span>
                            @endif
                            @if($loadButton)
                            <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" mad:click="onReload">
                                <i data-lucide="download" style="width:14px;height:14px;"></i>
                                {{ __('grid.load_records') }}
                            </button>
                            @endif
                            @elseif($tooManyRows !== '')
                            <i data-lucide="filter" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"></i>
                            <span class="mad-dg-filter-required" role="alert">{{ $tooManyRows }}</span>
                            @else
                            <i data-lucide="inbox" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"></i>
                            <span>{{ $emptyText !== '' ? $emptyText : __('grid.no_records') }}</span>
                            @endif
                        </div>
                    </td>
                </tr>
                @if($gridWindow !== null)
                    {{-- Relatório por janelas: a 1ª janela já desenhada (linhas
                         enxutas + quebra pelos partials) e a sentinela que pede a
                         próxima ao chegar perto da tela (mad-ui.js). --}}
                    {!! $gridWindow['html'] !!}
                    @if(!empty($gridWindow['more']))
                    <tr class="mad-dg-window-sentinel" data-mad-window-more="{{ $gridWindow['more'] }}">
                        <td colspan="{{ max(1, count($visibleColumns)) }}"><span class="mad-spinner mad-spinner-sm"></span> {{ __('grid.window_loading') }}</td>
                    </tr>
                    @endif
                @elseif($hasGroupBy && !empty($groupData))
                    @php $rowCounter = 0; $_lastRow = null; @endphp
                    @foreach($groupData as $item)
                    @if($item['type'] === 'group')
                    {{-- ── Cabeçalho de grupo (o mesmo partial da janela do relatório) ── --}}
                    @include('components.partials.data-grid-group-row')
                    @elseif($item['type'] === 'group-total')
                    {{-- ── Total por grupo ── --}}
                    @include('components.partials.data-grid-group-total-row')
                    @else
                    {{-- ── Linha de dado ── --}}
                    @php $row=$item['data']; $rowId=$row[$rowIdField]??$row['id']??$rowCounter; $rowIdJs=\Mad\Grid\MadDataGrid::rowIdJs($rowId); $isEven=$rowCounter%2===0; $rowCounter++; $rowDepth=$item['level']??0; $_hl=\Mad\Grid\MadDataGrid::rowHighlightFor($rowHighlightRules, $row); $_hlStyle=\Mad\Grid\MadDataGrid::rowHighlightStyle($_hl); @endphp
                    <tr class="mad-dg-row {{ $isEven?'mad-dg-row-even':'mad-dg-row-odd' }}{{ $rowDepth>0?' mad-dg-row-depth-'.$rowDepth:'' }}{{ \Mad\Grid\MadDataGrid::rowHighlightClass($_hl) }}" data-row-id="{{ $_rowPrefix }}{{ $rowId }}"@if($_hlStyle !== '') style="{{ $_hlStyle }}"@endif @if($selectable) :class="{ 'mad-dg-row-selected': isSelected({{ json_encode((string) $rowId) }}) }"@endif>
                        @if($selectable)@include('components.data-grid-select-cell', ['rowId' => $rowId])@endif
                        @if($hasExpand)@include('components.data-grid-expand-cell', ['rxAction' => $rowExpandAction, 'row' => $row, 'rowId' => $rowId])@endif
                        @if($hasActions && $actionSide === 'left')
                        @include('components.data-grid-actions-cell')
                        @endif
                        @foreach($visibleColumns as $col)
                        @php
                            $cellVal  = $row[$col->field] ?? '';
                            $rendered = $col->renderValue($cellVal, $row, $_lastRow ?? null);
                            // Para colunas dbcombo / dbunique-search em modo nao-inline, exibir
                            // o LABEL do registro relacionado em vez do ID cru.
                            if ($col->editable && (string)$cellVal !== '' && in_array($col->editType, ['dbcombo','dbunique-search'], true)) {
                                $lbl = '';
                                if ($col->editType === 'dbcombo' && isset($editComboOptions[$col->field][(string)$cellVal])) {
                                    $lbl = $editComboOptions[$col->field][(string)$cellVal];
                                } elseif ($col->editType === 'dbunique-search' && isset($editSearchPreloaded[$col->field][(string)$cellVal])) {
                                    $lbl = $editSearchPreloaded[$col->field][(string)$cellVal];
                                }
                                if ($lbl !== '') $rendered = htmlspecialchars((string)$lbl, ENT_QUOTES);
                            }
                        @endphp
                        <td class="mad-dg-cell{{ $col->nowrapClass() }}{{ $col->cellClass($cellVal) }}{{ \Mad\Grid\MadDataGrid::cellHighlightClass($_hl, $col) }}"{!! $col->cardAttrs($rendered) !!}@if($col->editable) data-col="{{ $col->fieldKey }}"@endif :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }" style="text-align:{{ $col->alignCss() }}{{ \Mad\Grid\MadDataGrid::cellHighlightStyle($_hl, $col) }};">
                            @if($col->editable)
                            @php
                                $editInitVal = $cellVal;
                                if ($col->editType === 'date' && !empty($cellVal)) {
                                    try { $editInitVal = (new \DateTime((string)$cellVal))->format('Y-m-d'); } catch (\Throwable $_) {}
                                }
                                $eMode = $col->editMode;
                            @endphp
                            @if($eMode === 'inline')
                            {{-- Modo inline: campo sempre visível --}}
                            <div class="mad-dg-edit-inline">
                                @include('components.data-grid-edit-inline', [
                                    'col'                 => $col,
                                    'rowId'               => $rowId,
                                    'cellVal'             => $cellVal,
                                    'editInitVal'         => $editInitVal,
                                    'editComboOptions'    => $editComboOptions,
                                    'editSearchToken'     => $editSearchToken,
                                    'editSearchPreloaded' => $editSearchPreloaded,
                                ])
                            </div>
                            @elseif($eMode === 'click')
                            {{-- Modo click: ícone lápis para ativar edição --}}
                            <div class="mad-dg-edit-click" style="display:flex;align-items:center;gap:6px;">
                                <span x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')" style="flex:1">{!! $rendered !!}</span>
                                <button type="button" class="mad-dg-edit-btn"
                                        x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')"
                                        @click="startEdit({{ $rowIdJs }},'{{ $col->field }}',{{ json_encode($editInitVal) }})"
                                        title="{{ __('grid.edit') }}">
                                    <i data-lucide="pencil" style="width:13px;height:13px;"></i>
                                </button>
                                <div x-show="isEditing({{ $rowIdJs }},'{{ $col->field }}')" x-cloak style="flex:1">
                                    @include('components.data-grid-edit-cell', [
                                        'col'                 => $col,
                                        'cellVal'             => $cellVal,
                                        'rowId'               => $rowId,
                                        'editComboOptions'    => $editComboOptions,
                                        'editSearchToken'     => $editSearchToken,
                                        'editSearchPreloaded' => $editSearchPreloaded,
                                    ])
                                </div>
                            </div>
                            @else
                            {{-- Modo dblclick (padrão) — div block com min-height pra dar
                                 area clicavel mesmo quando o conteudo for vazio. --}}
                            <div class="mad-dg-edit-dblclick"
                                 x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')"
                                 @dblclick="startEdit({{ $rowIdJs }},'{{ $col->field }}',{{ json_encode($editInitVal) }})"
                                 title="{{ __('grid.edit') }}"
                                 style="min-height:18px;cursor:text;">{!! $rendered !== '' ? $rendered : '<span style="color:var(--mad-muted-fg);opacity:.5;font-size:11px;">duplo-clique p/ editar</span>' !!}</div>
                            <div x-show="isEditing({{ $rowIdJs }},'{{ $col->field }}')" x-cloak>
                                @include('components.data-grid-edit-cell', [
                                    'col'                 => $col,
                                    'cellVal'             => $cellVal,
                                    'rowId'               => $rowId,
                                    'editComboOptions'    => $editComboOptions,
                                    'editSearchToken'     => $editSearchToken,
                                    'editSearchPreloaded' => $editSearchPreloaded,
                                ])
                            </div>
                            @endif
                            @else
                            {!! $col->cellHtml($rendered, $row) !!}
                            @endif
                        </td>
                        @endforeach
                        @if($hasActions && $actionSide === 'right')
                        @include('components.data-grid-actions-cell')
                        @endif
                    @php $_lastRow = $row ?? null; @endphp
                    </tr>
                    @if(!empty($row['__detail']))
                    {{-- row-detail: 2ª linha descritiva do registro. Marcada com
                         data-detail-for pro manage_row/remove_row do mad.js
                         removerem as duas juntas. --}}
                    <tr class="mad-dg-row-detail {{ $isEven?'mad-dg-row-even':'mad-dg-row-odd' }}" data-detail-for="{{ $_rowPrefix }}{{ $rowId }}">
                        <td :colspan="visibleColCount()">{{ $row['__detail'] }}</td>
                    </tr>
                    @endif
                    @endif
                    @endforeach
                @else
                    @php $_lastRow = null; @endphp
                    @foreach($rows as $rowIdx => $row)
                    @php $rowId=$row[$rowIdField]??$row['id']??$rowIdx; $rowIdJs=\Mad\Grid\MadDataGrid::rowIdJs($rowId); $isEven=$rowIdx%2===0; $_hl=\Mad\Grid\MadDataGrid::rowHighlightFor($rowHighlightRules, $row); $_hlStyle=\Mad\Grid\MadDataGrid::rowHighlightStyle($_hl); @endphp
                    <tr class="mad-dg-row {{ $isEven?'mad-dg-row-even':'mad-dg-row-odd' }}{{ \Mad\Grid\MadDataGrid::rowHighlightClass($_hl) }}" data-row-id="{{ $_rowPrefix }}{{ $rowId }}"@if($_hlStyle !== '') style="{{ $_hlStyle }}"@endif @if($selectable) :class="{ 'mad-dg-row-selected': isSelected({{ json_encode((string) $rowId) }}) }"@endif>
                        @if($selectable)@include('components.data-grid-select-cell', ['rowId' => $rowId])@endif
                        @if($hasExpand)@include('components.data-grid-expand-cell', ['rxAction' => $rowExpandAction, 'row' => $row, 'rowId' => $rowId])@endif
                        @if($hasActions && $actionSide === 'left')
                        @include('components.data-grid-actions-cell')
                        @endif

                        @foreach($visibleColumns as $col)
                        @php
                            $cellVal  = $row[$col->field] ?? '';
                            $rendered = $col->renderValue($cellVal, $row, $_lastRow ?? null);
                            // Para colunas dbcombo / dbunique-search em modo nao-inline, exibir
                            // o LABEL do registro relacionado em vez do ID cru.
                            if ($col->editable && (string)$cellVal !== '' && in_array($col->editType, ['dbcombo','dbunique-search'], true)) {
                                $lbl = '';
                                if ($col->editType === 'dbcombo' && isset($editComboOptions[$col->field][(string)$cellVal])) {
                                    $lbl = $editComboOptions[$col->field][(string)$cellVal];
                                } elseif ($col->editType === 'dbunique-search' && isset($editSearchPreloaded[$col->field][(string)$cellVal])) {
                                    $lbl = $editSearchPreloaded[$col->field][(string)$cellVal];
                                }
                                if ($lbl !== '') $rendered = htmlspecialchars((string)$lbl, ENT_QUOTES);
                            }
                        @endphp
                        <td class="mad-dg-cell{{ $col->nowrapClass() }}{{ $col->cellClass($cellVal) }}{{ \Mad\Grid\MadDataGrid::cellHighlightClass($_hl, $col) }}"{!! $col->cardAttrs($rendered) !!}@if($col->editable) data-col="{{ $col->fieldKey }}"@endif :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }" style="text-align:{{ $col->alignCss() }}{{ \Mad\Grid\MadDataGrid::cellHighlightStyle($_hl, $col) }};">
                            @if($col->editable)
                            @php
                                $editInitVal = $cellVal;
                                if ($col->editType === 'date' && !empty($cellVal)) {
                                    try { $editInitVal = (new \DateTime((string)$cellVal))->format('Y-m-d'); } catch (\Throwable $_) {}
                                }
                                $eMode = $col->editMode;
                            @endphp
                            @if($eMode === 'inline')
                            {{-- Modo inline: campo sempre visível --}}
                            <div class="mad-dg-edit-inline">
                                @include('components.data-grid-edit-inline', [
                                    'col'                 => $col,
                                    'rowId'               => $rowId,
                                    'cellVal'             => $cellVal,
                                    'editInitVal'         => $editInitVal,
                                    'editComboOptions'    => $editComboOptions,
                                    'editSearchToken'     => $editSearchToken,
                                    'editSearchPreloaded' => $editSearchPreloaded,
                                ])
                            </div>
                            @elseif($eMode === 'click')
                            {{-- Modo click: ícone lápis para ativar edição --}}
                            <div class="mad-dg-edit-click" style="display:flex;align-items:center;gap:6px;">
                                <span x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')" style="flex:1">{!! $rendered !!}</span>
                                <button type="button" class="mad-dg-edit-btn"
                                        x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')"
                                        @click="startEdit({{ $rowIdJs }},'{{ $col->field }}',{{ json_encode($editInitVal) }})"
                                        title="{{ __('grid.edit') }}">
                                    <i data-lucide="pencil" style="width:13px;height:13px;"></i>
                                </button>
                                <div x-show="isEditing({{ $rowIdJs }},'{{ $col->field }}')" x-cloak style="flex:1">
                                    @include('components.data-grid-edit-cell', [
                                        'col'                 => $col,
                                        'cellVal'             => $cellVal,
                                        'rowId'               => $rowId,
                                        'editComboOptions'    => $editComboOptions,
                                        'editSearchToken'     => $editSearchToken,
                                        'editSearchPreloaded' => $editSearchPreloaded,
                                    ])
                                </div>
                            </div>
                            @else
                            {{-- Modo dblclick (padrão) — div block com min-height pra dar
                                 area clicavel mesmo quando o conteudo for vazio. --}}
                            <div class="mad-dg-edit-dblclick"
                                 x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')"
                                 @dblclick="startEdit({{ $rowIdJs }},'{{ $col->field }}',{{ json_encode($editInitVal) }})"
                                 title="{{ __('grid.edit') }}"
                                 style="min-height:18px;cursor:text;">{!! $rendered !== '' ? $rendered : '<span style="color:var(--mad-muted-fg);opacity:.5;font-size:11px;">duplo-clique p/ editar</span>' !!}</div>
                            <div x-show="isEditing({{ $rowIdJs }},'{{ $col->field }}')" x-cloak>
                                @include('components.data-grid-edit-cell', [
                                    'col'                 => $col,
                                    'cellVal'             => $cellVal,
                                    'rowId'               => $rowId,
                                    'editComboOptions'    => $editComboOptions,
                                    'editSearchToken'     => $editSearchToken,
                                    'editSearchPreloaded' => $editSearchPreloaded,
                                ])
                            </div>
                            @endif
                            @else
                            {!! $col->cellHtml($rendered, $row) !!}
                            @endif
                        </td>
                        @endforeach

                        @if($hasActions && $actionSide === 'right')
                        @include('components.data-grid-actions-cell')
                        @endif
                    @php $_lastRow = $row; @endphp
                    </tr>
                    @if(!empty($row['__detail']))
                    <tr class="mad-dg-row-detail {{ $isEven?'mad-dg-row-even':'mad-dg-row-odd' }}" data-detail-for="{{ $_rowPrefix }}{{ $rowId }}">
                        <td :colspan="visibleColCount()">{{ $row['__detail'] }}</td>
                    </tr>
                    @endif
                    @endforeach
                @endif
            </tbody>

            @if($hasTotals)
            <tfoot class="mad-dg-foot{{ $hasGroupBy ? ' mad-dg-grand-total' : '' }}">
                <tr>
                    @if($selectable)<td class="mad-dg-cell mad-dg-select-cell"></td>@endif
                    @if($hasExpand)<td class="mad-dg-cell mad-dg-expand-cell"></td>@endif
                    @if($hasActions && $actionSide === 'left')<td class="mad-dg-cell"></td>@endif
                    @if($hasGroupBy)
                    @php
                        // "TOTAL GERAL" só quando alguma coluna soma tudo: se
                        // todas somam a página, o rótulo diz isso.
                        $_allPageOnly = $totalsPageOnly !== []
                            && count(array_diff_key($totals, $totalsPageOnly)) === 0;
                    @endphp
                    @include('components.data-grid-total-cells', [
                        'summaryColumns' => $visibleColumns,
                        'summaryTotals' => $totals,
                        'summaryCaption' => $_allPageOnly ? __('grid.page_total') : __('grid.grand_total'),
                        'summaryContext' => '',
                        'summaryGrand' => true,
                        'summaryPageOnly' => $totalsPageOnly,
                    ])
                    @else
                    @foreach($visibleColumns as $col)
                    <td class="mad-dg-cell mad-dg-total-cell{{ $col->nowrapClass() }}{{ $col->isNumeric() ? ' mad-dg-cell-num' : '' }}" style="text-align:{{ $col->alignCss() }};"
                        :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }">
                        @if(isset($totals[$col->field]))<span class="mad-dg-total-value" data-mad-total="{{ $col->fieldKey }}">{!! \Mad\Grid\MadDataGrid::totalCellHtml($totals[$col->field], !empty($totalsPageOnly[$col->field])) !!}</span>@endif
                    </td>
                    @endforeach
                    @endif
                    @if($hasActions && $actionSide === 'right')<td class="mad-dg-cell"></td>@endif
                </tr>
            </tfoot>
            @endif
        </table>
    </div>

    {{-- ── Card View ─────────────────────────────────────────────────── --}}
    @if($cardView)
    <div class="mad-dg-cards-wrap" x-show="viewMode === 'card'" x-cloak
         @if($cardCols) style="--mad-dg-card-cols:{{ $cardCols }};" @endif>
        {{-- Empty-state SEMPRE no DOM (oculto quando ha linhas): manage_row
             esconde no insert, remove_row mostra quando a lista esvazia. --}}
        <div class="mad-dg-empty mad-dg-cards-empty" style="padding:48px 16px;@if(!empty($rows))display:none;@endif">
            <div class="mad-dg-empty-inner">
                @if($deferred)
                {{-- Tentativa sem filtro: mesmo ícone e mesma cor do tema da dica
                     inicial (antes era funil laranja fixo, destoava do tema). O que
                     muda é o texto e o role=alert para leitor de tela. --}}
                <i data-lucide="search" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"></i>
                @if($filterMissing)
                <span class="mad-dg-filter-required" role="alert">{{ $loadHint !== '' ? $loadHint : __('grid.filter_required') }}</span>
                @else
                <span>{{ $loadHint !== '' ? $loadHint : ($requireFilter ? __('grid.filter_required_hint') : __('grid.load_hint')) }}</span>
                @endif
                @if($loadButton)
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" mad:click="onReload">
                    <i data-lucide="download" style="width:14px;height:14px;"></i>
                    {{ __('grid.load_records') }}
                </button>
                @endif
                @elseif($tooManyRows !== '')
                <i data-lucide="filter" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"></i>
                <span class="mad-dg-filter-required" role="alert">{{ $tooManyRows }}</span>
                @else
                <i data-lucide="inbox" style="width:32px;height:32px;opacity:.3;display:block;margin:0 auto 8px;"></i>
                <span>{{ $emptyText !== '' ? $emptyText : __('grid.no_records') }}</span>
                @endif
            </div>
        </div>
        {{-- Container SEMPRE presente (mesmo vazio) p/ o manageRow conseguir
             inserir o 1o card numa lista vazia em card-view. --}}
        <div class="mad-dg-cards">
            @foreach($rows as $rowIdx => $row)
            @php $rowId = $row[$rowIdField] ?? $row['id'] ?? $rowIdx; $rowIdJs = \Mad\Grid\MadDataGrid::rowIdJs($rowId); $_hl = \Mad\Grid\MadDataGrid::rowHighlightFor($rowHighlightRules, $row); $_hlStyle = \Mad\Grid\MadDataGrid::rowHighlightStyle($_hl); @endphp
            <div class="mad-dg-card{{ \Mad\Grid\MadDataGrid::rowHighlightClass($_hl) }}" data-card-id="{{ $_rowPrefix }}{{ $rowId }}"@if($_hlStyle !== '') style="{{ $_hlStyle }}"@endif @if($selectable) :class="{ 'mad-dg-card-selected': isSelected({{ json_encode((string) $rowId) }}) }"@endif>
                @if($selectable)
                <label class="mad-dg-card-select" @click.stop>
                    <input type="checkbox" class="mad-dg-select" aria-label="{{ __('grid.select_row') }}"
                           :checked="isSelected({{ json_encode((string) $rowId) }})"
                           @change="toggleRow({{ json_encode((string) $rowId) }}, $event.target.checked)">
                </label>
                @endif
                @if($cardImage)
                @php $imgVal = $row[$cardImage->field] ?? ''; @endphp
                @if($imgVal)
                <div class="mad-dg-card-image">
                    <img src="{{ $imgVal }}" alt="" loading="lazy" />
                </div>
                @endif
                @endif

                <div class="mad-dg-card-body">
                    {{-- Header: title + badge --}}
                    <div class="mad-dg-card-header">
                        <div class="mad-dg-card-titles">
                            @if($cardTitle)
                            <span class="mad-dg-card-title">{!! $cardTitle->renderValue($row[$cardTitle->field] ?? '', $row) !!}</span>
                            @endif
                            @if($cardSubtitle)
                            <span class="mad-dg-card-subtitle">{!! $cardSubtitle->renderValue($row[$cardSubtitle->field] ?? '', $row) !!}</span>
                            @endif
                        </div>
                        @if($cardBadge)
                        {!! $cardBadge->renderValue($row[$cardBadge->field] ?? '', $row) !!}
                        @endif
                    </div>

                    {{-- Highlight (money / primary value) --}}
                    @if($cardHighlight)
                    <div class="mad-dg-card-highlight">{!! $cardHighlight->renderValue($row[$cardHighlight->field] ?? '', $row) !!}</div>
                    @endif

                    {{-- Body fields --}}
                    @if(!empty($cardBody))
                    <div class="mad-dg-card-fields">
                        @foreach($cardBody as $col)
                        @php $cellVal = $row[$col->field] ?? ''; $_hlCellStyle = ltrim(\Mad\Grid\MadDataGrid::cellHighlightStyle($_hl, $col), ';'); @endphp
                        @if($cellVal !== '' && $cellVal !== null)
                        <div class="mad-dg-card-field{{ \Mad\Grid\MadDataGrid::cellHighlightClass($_hl, $col) }}"@if($_hlCellStyle !== '') style="{{ $_hlCellStyle }}"@endif :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }">
                            <span class="mad-dg-card-field-label">{{ $col->label }}</span>
                            <span class="mad-dg-card-field-value">{!! $col->renderValue($cellVal, $row) !!}</span>
                        </div>
                        @endif
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- Footer: actions (+ o botão do <mad-row-expand>: no cartão a
                     tela abre na cortina lateral — não há <tr> onde anexar). --}}
                @if($hasActions || $hasExpand)
                <div class="mad-dg-card-footer">
                    <div class="mad-dg-card-actions">
                        @foreach($actions as $act)
                        @php
                            if (!$act->isVisible($row)) continue;
                            $aId = $row[$act->idField] ?? $rowId;
                            $aIdJs = \Mad\Grid\MadDataGrid::rowIdJs($aId);
                            $ep  = !empty($act->params) ? ', ' . json_encode($act->rowParams($row)) : '';
                            $act = $act->getTransformed($row);
                            // Estado + dica do perfil (ver GridAction::stateAttrs): o card
                            // recusado não levava title nenhum.
                            $dis = $act->stateAttrs($row);
                            $dis = ($dis !== '' ? ' ' . $dis : '')
                                 . ($act->denyTitle() !== '' ? ' title="' . e($act->denyTitle()) . '"' : '');
                            $ico = $act->icon ? '<i data-lucide="' . htmlspecialchars($act->icon, ENT_QUOTES) . '" style="width:14px;height:14px;"></i>' : '';
                            $cls = $act->cardClass();
                        @endphp
                        @if($act->isNav)
                        <button type="button" class="{{ $cls }}"{!! $dis !!} {!! $act->getNavAttr($aId, $row) !!}>
                            {!! $ico !!}<span>{{ $act->label }}</span>
                        </button>
                        @elseif($act->confirm || $act->confirmPopover)
                        @php
                            $_cMsg  = $act->confirmPopover ?: $act->confirm;
                            $_cType = $act->confirmPopover ? "'popover'" : 'null';
                            $_click = 'confirmAction(' . json_encode($_cMsg) . ", () => \$dispatch('mad-dg-call',{method:" . json_encode($act->method) . ',params:[' . $aIdJs . $ep . "]}), \$event, " . $_cType . ')';
                        @endphp
                        <button type="button" class="{{ $cls }}"{!! $dis !!}
                                @click="{{ $_click }}">
                            {!! $ico !!}<span>{{ $act->label }}</span>
                        </button>
                        @else
                        <button type="button" class="{{ $cls }}"{!! $dis !!}
                                @click="$dispatch('mad-dg-call',{method:{{ json_encode($act->method) }},params:[{{ $aIdJs }}{{ $ep }}]})">
                            {!! $ico !!}<span>{{ $act->label }}</span>
                        </button>
                        @endif
                        @endforeach

                        @foreach($actionGroups as $grp)
                        {{-- Mesmo menu da linha da tabela (data-grid-actions-cell): Esc, um aberto por vez, abre para dentro. --}}
                        <div class="mad-dg-dropdown-wrap" x-data="{{ \Mad\Grid\MadDataGrid::ROW_MENU_XDATA }}" x-effect="if(open)place()" @scroll.window="open=false" @resize.window="open=false" @keydown.escape.window="if(open){open=false;$refs.btn.focus()}" @mad-dg-menu-open.window="if($event.detail!==$refs.btn)open=false" style="position:relative;">
                            <button type="button" class="mad-dg-card-action" x-ref="btn" @click.stop="open=!open;if(open)$dispatch('mad-dg-menu-open',$refs.btn)"
                                    title="{{ $grp->label }}">
                                @if($grp->icon)<i data-lucide="{{ $grp->icon }}" style="width:14px;height:14px;"></i>@endif
                                @if($grp->label)<span>{{ $grp->label }}</span>@endif
                            </button>
                            <template x-teleport="body"><div class="mad-dg-dropdown" x-init="menu=$el" x-show="open" x-cloak @click.outside="open=false"
                                 :style="'position:fixed;top:'+pos.top+';'+pos.side+':'+pos.x+';z-index:var(--mad-z-float,9999);'">
                                @foreach($grp->actions as $gAct)
                                @php
                                    if (!$gAct->isVisible($row)) continue;
                                    $gaId = $row[$gAct->idField] ?? $rowId;
                                    $gaIdJs = \Mad\Grid\MadDataGrid::rowIdJs($gaId);
                                    $gep  = !empty($gAct->params) ? ', ' . json_encode($gAct->rowParams($row)) : '';
                                    $gAct = $gAct->getTransformed($row);
                                    $gCls = $gAct->menuClass();
                                    $gDis = $gAct->stateAttrs($row);
                                    $gDis = ($gDis !== '' ? ' ' . $gDis : '')
                                          . ($gAct->denyTitle() !== '' ? ' title="' . e($gAct->denyTitle()) . '"' : '');
                                    $gIco = $gAct->icon ? '<i data-lucide="' . htmlspecialchars($gAct->icon, ENT_QUOTES) . '" style="width:13px;height:13px;"></i>' : '';
                                @endphp
                                @if($gAct->isNav)
                                <button type="button" class="{{ $gCls }}"{!! $gDis !!}
                                        @click="open=false" {!! $gAct->getNavAttr($gaId, $row) !!}>
                                    {!! $gIco !!}{{ $gAct->label }}
                                </button>
                                @elseif($gAct->confirm || $gAct->confirmPopover)
                                @php
                                    $_gcMsg  = $gAct->confirmPopover ?: $gAct->confirm;
                                    $_gcType = $gAct->confirmPopover ? "'popover'" : 'null';
                                    $_gclick = "open=false; confirmAction(" . json_encode($_gcMsg) . ", () => \$root.dispatchEvent(new CustomEvent('mad-dg-call',{detail:{method:" . json_encode($gAct->method) . ',params:[' . $gaIdJs . $gep . "]},bubbles:true})), \$event, " . $_gcType . ')';
                                @endphp
                                <button type="button" class="{{ $gCls }}"{!! $gDis !!}
                                        @click="{{ $_gclick }}">
                                    {!! $gIco !!}{{ $gAct->label }}
                                </button>
                                @else
                                <button type="button" class="{{ $gCls }}"{!! $gDis !!}
                                        @click="open=false; $root.dispatchEvent(new CustomEvent('mad-dg-call',{detail:{method:'{{ $gAct->method }}',params:[{{ $gaIdJs }}{{ $gep }}]},bubbles:true}))">
                                    {!! $gIco !!}{{ $gAct->label }}
                                </button>
                                @endif
                                @endforeach
                            </div>
                            </template>
                        </div>
                        @endforeach
                        @if($hasExpand)@include('components.data-grid-expand-cell', ['rxAction' => $rowExpandAction, 'row' => $row, 'rowId' => $rowId, 'card' => true])@endif
                    </div>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Footer: info + per-page + paginação ───────────────────────── --}}
    {{-- mad-dg-footer-single: tudo cabe numa página — some com footer="auto"
         (o padrão no ERP e no compact) e o "1–1 de 1" deixa de ocupar a lista
         curta (fw#495). --}}
    <div class="mad-dg-footer{{ $totalPages <= 1 && $page <= 1 ? ' mad-dg-footer-single' : '' }}">
        {{-- data-range-tpl: o mad.js monta "1–1 de 1" quando o manage_row põe a
             1ª linha numa lista que estava vazia (#75) — no idioma do app. --}}
        <span class="mad-dg-info" data-range-tpl="{{ __('grid.range', ['from' => '__F__', 'to' => '__T__', 'total' => '__N__']) }}">
            @if($total > 0){{ __('grid.range', ['from' => $pgStart, 'to' => $pgEnd, 'total' => $total]) }}@elseif($deferred || $tooManyRows !== '')@else {{ __('grid.no_records') }} @endif
        </span>
        {{-- Sem paginação (per-page 0) não há "Por página": escolher 10 ali
             paginaria uma grade declarada sem paginação (fw#311). --}}
        @if($perPage > 0)
        <div class="mad-dg-per-page">
            <label class="mad-dg-per-page-label">{{ __('grid.per_page') }}</label>
            <select class="mad-select mad-select-sm"
                    @change="$dispatch('mad-dg-call',{method:'onPerPage',params:[parseInt($event.target.value)||15]})"
                    >
                {{-- O per-page da grade entra na lista: `per-page="30"` trazia 30
                     linhas com o seletor em 10, porque nenhuma opção batia (fw#300). --}}
                @php($__perPageOpts = array_values(array_unique(array_merge([10, 15, 20, 50, 100], $perPage > 0 ? [$perPage] : []))))
                @php(sort($__perPageOpts))
                @foreach($__perPageOpts as $opt)
                <option value="{{ $opt }}" {{ $perPage==$opt?'selected':'' }}>{{ $opt }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if($totalPages > 1)
        <nav class="mad-pagination" aria-label="Paginação">
            <button type="button" class="mad-page-btn" {{ $page<=1?'disabled':'' }}
                    @click="$dispatch('mad-dg-page',{page:{{ $page-1 }}})">
                <i data-lucide="chevron-left" style="width:14px;height:14px;"></i>
            </button>
            @foreach($pages as $p)
            @if($p['type']==='ellipsis')
            <span class="mad-page-ellipsis">…</span>
            @else
            <button type="button" class="mad-page-btn {{ $p['n']===$page?'mad-page-btn-active':'' }}"
                    @click="$dispatch('mad-dg-page',{page:{{ $p['n'] }}})">{{ $p['n'] }}</button>
            @endif
            @endforeach
            <button type="button" class="mad-page-btn" {{ $page>=$totalPages?'disabled':'' }}
                    @click="$dispatch('mad-dg-page',{page:{{ $page+1 }}})">
                <i data-lucide="chevron-right" style="width:14px;height:14px;"></i>
            </button>
        </nav>
        @endif
    </div>

</div>
