@php
    // ── Props ────────────────────────────────────────────────────────────────
    $name       = $name       ?? 'detail';
    $mode       = $mode       ?? 'inline';    // inline | modal | drawer
    $formTitle  = $formTitle  ?? '';
    $formCols   = (int)($formCols ?? 2);
    $addLabel   = $addLabel   ?? 'Adicionar';
    $beforeAdd    = $beforeAdd    ?? '';         // método PHP para validação server-side ao adicionar
    $beforeDelete = $beforeDelete ?? '';         // método PHP para validação server-side ao deletar
    $model        = $model        ?? '';         // classe do model Eloquent (ex: 'DocReceitaItem')
    $foreignKey   = $foreignKey   ?? '';         // coluna FK no model filho (habilita auto-save/load)
    $database     = $database     ?? '';         // conexão do banco (default: MAIN_DATABASE)
    $width      = trim((string)($width ?? ''));   // largura do overlay (modal/drawer)
    $perPage    = (int)($perPage ?? 0);
    $sticky     = !empty($sticky);
    $fieldSlot  = $fieldSlot  ?? '';
    $colConfigs = $colConfigs ?? [];
    $actConfigs = $actConfigs ?? [];
    $rows       = $rows       ?? [];

    // Detail é sempre escopado por FK (builder-native); sem filtro extra.
    $_dfCritToken = '';

    // ── Instanciar GridColumn e GridAction a partir dos configs ──────────────
    $columns = [];
    foreach ($colConfigs as $c) {
        $columns[] = \Mad\Grid\MadDataGrid::_colFromConfig($c);
    }
    // A linha do detalhe roda na TELA que contém o <mad-detail-form> — é ela que
    // o perfil marca. Sem informar o dono aqui, as ações da linha (Excluir e
    // companhia) eram as únicas da tela que escapavam da permissão por ação:
    // o servidor recusava o clique, mas o botão continuava lá, prometendo algo
    // que ia falhar. Fora de qualquer tela (preview/render solto) o dono é
    // vazio e nada muda, como no resto do gate.
    $_dfPermOwner = ($_dfPermComp = \Mad\Component\MadRenderContext::getComponent())
        ? get_class($_dfPermComp)
        : '';
    $actions = [];
    foreach ($actConfigs as $a) {
        $act = \Mad\Grid\MadDataGrid::_actFromConfig($a, $_dfPermOwner);
        if ($act) $actions[] = $act;
    }

    // ── Colunas com caminho de relacionamento (`{cidade->estado->pais->nome}`) ──
    // Não existem no toArray() da linha: só resolvem percorrendo as relações do
    // registro filho. Mesmo mecanismo do <mad-grid>/<mad-data-table>.
    $_dfRenderFields = \Mad\Grid\GridRenderHelpers::detectRenderFields($columns);

    // ── Auto-load: se model+fk declarados e rows vazias, carrega do banco ─
    $_dfGivenRows = !empty($rows);
    if ($model && $foreignKey && empty($rows)) {
        $_form = \Mad\Component\MadRenderContext::getForm();
        if ($_form) {
            $rows = $_form->autoLoadDetailRows($name, $model, $foreignKey, $database, $_dfRenderFields);
        }
        unset($_form);
    }

    // ── Normalizar rows (garantir __id) ─────────────────────────────────────
    $normalizedRows = \Mad\Form\FieldListColumn::normalizeRows($rows);

    // Linhas entregues pelo código da tela (não pelo auto-load) num detail que
    // grava sozinho: o formulário anota que as mostrou — o Salvar só apaga a
    // linha que a tela mostrou e o usuário removeu.
    if ($_dfGivenRows && $model && $foreignKey && ($_form = \Mad\Component\MadRenderContext::getForm())) {
        $_form->noteDetailRows($name, $model, $foreignKey, $normalizedRows);
    }
    unset($_form, $_dfGivenRows);

    // ── Colunas calculadas (`evaluate`) nas linhas que já existem (#84) ─────
    // A linha adicionada no navegador é calculada pelo gêmeo JS
    // (`_applyEvaluates`); a que veio do banco era montada com `toArray()` e
    // saía 0 — e o total do rodapé junto. Mesma regra do servidor
    // (MadDetailFormRef::evaluateRow), aplicada antes da formatação abaixo.
    $_dfEvalCols = array_values(array_filter(array_map(
        fn($c) => $c->evaluate ? ['field' => $c->field, 'evaluate' => $c->evaluate] : null,
        $columns
    )));
    if ($_dfEvalCols && $normalizedRows) {
        try {
            $_dfModelCls = $model ? \Mad\Form\ModelOptionsLoader::resolveModelClass($model) : '';
        } catch (\Throwable $e) {
            $_dfModelCls = '';
        }
        $_dfEval = new \Mad\Form\MadDetailFormRef($name, $_dfEvalCols, $_dfModelCls);
        foreach ($normalizedRows as $_dfI => $_dfRow) {
            $_dfEval->evaluateRow($_dfRow);
            $normalizedRows[$_dfI] = $_dfRow;
        }
        unset($_dfEval, $_dfModelCls, $_dfRow, $_dfI);
    }
    unset($_dfEvalCols);

    // ── Transform CALLABLE / token de mídia: pré-computa no servidor ────────
    // O cliente não executa `Classe::metodo` nem monta o HTML de `file-thumb`.
    // Token built-in (money/cpf/date-long/…) NÃO entra aqui: tem gêmeo JS
    // (GridRenderHelpers::builtinFormatJs) e formata também a linha criada no
    // navegador. Aqui só o que depende do PHP.
    //
    // Chave `__fmt`: o prefixo `__` é descartado no save (MadForm::_saveDetailRows
    // e ::_persistDetailInstance pulam `__*`), então não vira coluna nem faz
    // linha vazia parecer preenchida. A linha EDITADA no navegador é remontada
    // pelo `formData` e perde o `__fmt` — cai no valor cru, que é melhor que
    // exibir um formatado obsoleto.
    $_dfFmtCols = array_values(array_filter(
        $columns,
        fn($c) => $c->hasTransform || $c->mediaFormat !== ''
    ));
    if ($_dfFmtCols) {
        foreach ($normalizedRows as $_dfI => $_dfRow) {
            $_dfFmt = [];
            foreach ($_dfFmtCols as $_dfCol) {
                $_dfKey = \Mad\Grid\GridRenderHelpers::rowDataKey($_dfCol->field);
                $_dfRaw = $_dfRow[$_dfKey] ?? ($_dfRow[$_dfCol->field] ?? '');
                try {
                    // renderValue já escapa (htmlspecialchars) o que não é html/mídia
                    // — por isso a célula desse ramo usa x-html.
                    $_dfFmt[$_dfCol->field] = $_dfCol->renderValue($_dfRaw, $_dfRow);
                } catch (\Throwable $e) {
                    // Transformer do dev que lança não pode derrubar o formulário
                    // inteiro: degrada pro valor cru (mesma política do PDV).
                    error_log('[mad-detail-form] transform da coluna "' . $_dfCol->field
                        . '" lançou: ' . $e->getMessage() . ' — exibindo valor cru.');
                    $_dfFmt[$_dfCol->field] = htmlspecialchars(
                        is_scalar($_dfRaw) ? (string) $_dfRaw : '',
                        ENT_QUOTES
                    );
                }
            }
            $normalizedRows[$_dfI]['__fmt'] = $_dfFmt;
        }
    }

    // ── Config JSON para Alpine ─────────────────────────────────────────────
    $alpineCfg = json_encode([
        'name'      => $name,
        'mode'      => $mode,
        'rows'      => $normalizedRows,
        'columns'   => array_map(fn($c) => array_filter([
            'field'    => $c->field,
            'default'  => '',
            'evaluate' => $c->evaluate ?: null,
        ], fn($v) => $v !== null), $columns),
        'perPage'   => $perPage,
        'beforeAdd'    => $beforeAdd ?: null,
        'beforeDelete' => $beforeDelete ?: null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // ── Registra no MadFormRegistry para acesso via $form->getDetailForm() ──
    \Mad\Form\MadFormRegistry::registerDetailForm($name, array_map(fn($c) => [
        'field'    => $c->field,
        'label'    => $c->label,
        'evaluate' => $c->evaluate ?: null,
    ], $columns), $model, $foreignKey, $database);

    // ── Form fields: renderiza os componentes Blade do fieldSlot ────────────
    // O fieldSlot vem como string crua com tags Blade (<mad-input-field>, etc.)
    // Compilamos em runtime via MadBlade::renderString.
    // Snapshot do registry antes/depois: campos file/image que o sub-form
    // registrar são colunas de arquivo deste detail (auto-save por-linha).
    $_dfFieldsBefore = array_keys(\Mad\Form\MadFormRegistry::getFields());
    // Os campos desenhados daqui até o fim do editor são colunas da LINHA: o
    // formulário da tela não os toma por campos do registro principal.
    \Mad\Form\MadFormRegistry::beginDetailEditor($name);
    try {
        $formHtml = \Mad\View\MadBlade::renderString($fieldSlot, get_defined_vars());
    } finally {
        \Mad\Form\MadFormRegistry::endDetailEditor($formHtml ?? '');
    }
    $_dfFileCols = [];
    $_dfColMeta  = [];
    $_dfEditor   = [];   // campos do editor: colunas da LINHA, não do registro principal
    foreach (\Mad\Form\MadFormRegistry::getFields() as $_dfFn => $_dfFp) {
        if (in_array($_dfFn, $_dfFieldsBefore, true)) {
            continue;
        }
        $_dfEditor[] = (string) $_dfFn;
        // strip-mask do campo do editor vale para a coluna da linha: sem isto
        // o auto-save gravava '123.456.789-01' mesmo com strip-mask declarado.
        if (!empty($_dfFp['stripMask']) && !empty($_dfFp['mask'])) {
            $_dfColMeta[$_dfFn] = ['mask' => (string) $_dfFp['mask'], 'stripMask' => true];
        }
        $_dfT = $_dfFp['type'] ?? '';
        // `image` SEM `storage` (<mad-image-field>, <mad-signature-field>)
        // guarda o base64 na própria coluna — o hidden com `name` o leva na
        // linha, como no formulário próprio da tabela. Como coluna de arquivo
        // (com "disk" no lugar do storage ausente) o Salvar pulava o valor e,
        // sem arquivo enviado, a coluna ficava NULL: a assinatura desenhada na
        // cortina sumia sem erro. O `file` sem storage segue indo para o disco,
        // e o `<mad-avatar-field>` também: ele só manda o arquivo (o
        // `<input type="file">` dele tem `name` mesmo sem storage), que é o
        // que o navegador captura como arquivo da linha.
        if ($_dfT === 'image' && empty($_dfFp['storage'])
            && !preg_match(
                '/<input\b(?=(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\stype="file")(?=(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\sname="'
                    . preg_quote(e((string) $_dfFn), '/') . '")/',
                (string) $formHtml
            )) {
            continue;
        }
        if ($_dfT === 'file' || $_dfT === 'image') {
            $_dfFileCols[$_dfFn] = [
                'storage'    => $_dfFp['storage'] ?? 'disk',
                'folder'     => $_dfFp['folder'] ?? 'uploads',
                'fileName'   => $_dfFp['fileName'] ?? 'prefix',
                'nameColumn' => $_dfFp['nameColumn'] ?? '',
                'multi'      => !empty($_dfFp['multiple']),
            ];
        }
    }
    // Sempre, mesmo sem campo de arquivo no editor: o formulário da tela anota
    // como o detalhe trata os arquivos das linhas, e o Salvar só aceita essa
    // descrição.
    \Mad\Form\MadFormRegistry::registerDetailFileColumns($name, $_dfFileCols);
    if ($_dfColMeta) {
        \Mad\Form\MadFormRegistry::registerDetailColMeta($name, $_dfColMeta);
    }
    \Mad\Form\MadFormRegistry::registerDetailEditorFields($name, $_dfEditor);

    // Colunas da linha que este detail não mostra: o formulário anota como
    // estavam no banco — o Salvar não regrava a que ninguém mexeu. No detail
    // gravado à mão (sem model na tag) a base vem do loadDetailRows() da tela.
    if ($_form = \Mad\Component\MadRenderContext::getForm()) {
        $_form->noteDetailColumns($name, (string) $model, (string) $foreignKey, $normalizedRows);
    }
    // As linhas como vão para o navegador: o que voltar diferente nas colunas
    // que o detalhe não tem não é aceito (MadForm::takeRowsFromBrowser).
    \Mad\Component\MadRenderContext::getForm()?->rowsRendered($name, $normalizedRows);
    unset($_form, $_dfEditor);

    $hasTotals = !empty(array_filter($columns, fn($c) => $c->totalFunc));
@endphp

<div class="mad-df" data-mad-df-name="{{ $name }}" x-data="madDetailForm({{ $alpineCfg }})">

    {{-- Token de escopo: sobrevive ao POST para o auto-save aplicar o mesmo escopo --}}
    @if($_dfCritToken)
        <input type="hidden" name="__mad_fl_crit[{{ $name }}]" value="{{ $_dfCritToken }}">
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         FORMULÁRIO — inline, modal ou drawer
         ═══════════════════════════════════════════════════════════════════════ --}}

    @php
        $dfOverlayName = 'df_' . $name;
        $btnLabelJs    = htmlspecialchars($addLabel, ENT_QUOTES);
        // `width` do <mad-detail-form> vira a prop `size` do overlay: token do
        // mapa (sm|md|lg|xl|full) ou medida livre — x-drawer/x-modal já passam
        // por CssUnits::length e caem no default deles se a medida for inválida.
        $dfModalSize   = $width !== '' ? $width : 'lg';
        $dfDrawerSize  = $width !== '' ? $width : 'md';
    @endphp

    @if($mode === 'modal')

    {{-- Botão Adicionar --}}
    <div class="mad-df-toolbar">
        <button type="button" class="mad-btn mad-btn-primary" @click="openNew()">
            <i data-lucide="plus" style="width:14px;height:14px;"></i>
            {!! $addLabel !!}
        </button>
    </div>

    {{-- Formulário do item: clique fora não fecha (perdia o que foi digitado);
         fecha no X, no Esc, em Cancelar e ao adicionar/atualizar. --}}
    <x-modal :name="$dfOverlayName" :title="$formTitle ?: 'Detalhe'" :size="$dfModalSize" :close-on-backdrop="false">
        <div data-df-fields data-df-name="{{ $name }}" @keydown.enter.prevent="addOrUpdate()">
            {!! $formHtml !!}
        </div>
        <div class="mad-df-form-actions">
            <button type="button" class="mad-btn mad-btn-primary" @click="addOrUpdate()">
                <span x-text="(typeof editIndex!=='undefined' && editIndex >= 0) ? 'Atualizar' : '{{ $btnLabelJs }}'"></span>
            </button>
            <button type="button" class="mad-btn mad-btn-ghost" @click="cancelEdit()">{!! __('mad.cancel') !!}</button>
        </div>
    </x-modal>

    @elseif($mode === 'drawer')

    {{-- Botão Adicionar --}}
    <div class="mad-df-toolbar">
        <button type="button" class="mad-btn mad-btn-primary" @click="openNew()">
            <i data-lucide="plus" style="width:14px;height:14px;"></i>
            {!! $addLabel !!}
        </button>
    </div>

    {{-- Idem modal: clique fora não fecha a cortina do item. --}}
    <x-drawer :name="$dfOverlayName" :title="$formTitle ?: 'Detalhe'" :size="$dfDrawerSize" :close-on-backdrop="false">
        <div data-df-fields data-df-name="{{ $name }}" @keydown.enter.prevent="addOrUpdate()">
            {!! $formHtml !!}
        </div>
        <div class="mad-df-form-actions">
            <button type="button" class="mad-btn mad-btn-primary" @click="addOrUpdate()">
                <span x-text="(typeof editIndex!=='undefined' && editIndex >= 0) ? 'Atualizar' : '{{ $btnLabelJs }}'"></span>
            </button>
            <button type="button" class="mad-btn mad-btn-ghost" @click="cancelEdit()">{!! __('mad.cancel') !!}</button>
        </div>
    </x-drawer>

    @else {{-- inline --}}

    <div class="mad-df-form-inline mad-card">
        <div data-df-fields data-df-name="{{ $name }}">
            {!! $formHtml !!}
        </div>
        <div class="mad-df-form-actions">
            <button type="button" class="mad-btn mad-btn-primary" @click="addOrUpdate()"
                    style="display:inline-flex;align-items:center;gap:6px;">
                <i data-lucide="plus" style="width:14px;height:14px;" x-show="(typeof editIndex!=='undefined' && editIndex < 0)"></i>
                <i data-lucide="check" style="width:14px;height:14px;" x-show="(typeof editIndex!=='undefined' && editIndex >= 0)" x-cloak></i>
                <span x-text="(typeof editIndex!=='undefined' && editIndex >= 0) ? 'Atualizar' : '{{ $addLabel }}'"></span>
            </button>
            <button type="button" class="mad-btn mad-btn-ghost" x-show="(typeof editIndex!=='undefined' && editIndex >= 0)" x-cloak
                    @click="cancelEdit()" style="display:inline-flex;align-items:center;gap:6px;">
                <i data-lucide="x" style="width:14px;height:14px;"></i> Cancelar
            </button>
        </div>
    </div>

    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         LISTAGEM (GRID)
         ═══════════════════════════════════════════════════════════════════════ --}}

    <div class="mad-df-list" style="margin-top:12px;">
        <div class="mad-dg-wrap" style="overflow-x:auto;">
            <table class="mad-dg-table">
                <thead class="mad-dg-head{{ $sticky ? ' mad-dg-sticky' : '' }}">
                    <tr>
                        @foreach($columns as $col)
                        @php
                            // Config crua (sem passar pelo setter fluente) trazia '140'
                            // sem unidade e a largura era descartada em silêncio.
                            $_thW = \Mad\Support\CssUnits::length((string) ($col->width ?? ''));
                        @endphp
                        <th class="mad-dg-th" style="text-align:{{ $col->align }};{{ $_thW !== '' ? 'width:'.$_thW.';' : '' }}">
                            {{ $col->label }}
                        </th>
                        @endforeach
                        <th class="mad-dg-th" style="width:80px;text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, idx) in (perPage > 0 ? pagedRows : rows)" :key="row.__id">
                        <tr class="mad-dg-row" :class="{ 'mad-df-row-editing': (typeof editIndex!=='undefined' && editIndex === idx) }"
                            x-init="$nextTick(() => { if (typeof _madLucide === 'function') _madLucide([$el]); })">
                            @foreach($columns as $col)
                            @php
                                // `field` e TEMPLATE DE EXIBICAO, nao chave da linha:
                                // a linha do servidor traz o template resolvido na
                                // propria chave, a criada no navegador so tem as
                                // chaves nuas dos inputs do sub-form. `$_cv` le as
                                // duas; `$_ck` e a chave de ESCRITA (edicao inline).
                                $_cv = \Mad\Grid\GridRenderHelpers::rowValueJs($col->field);
                                $_ck = \Mad\Grid\GridRenderHelpers::rowDataKey($col->field);

                                // Transform da coluna — MESMA precedência do
                                // GridColumn::renderValue (callable/mídia → badge →
                                // money → number → date → built-in → html → cru),
                                // pra célula do detalhe e célula da grid contarem a
                                // mesma história.
                                //  • callable/mídia: só o servidor resolve → lê o
                                //    mapa `__fmt` da linha (x-html: já vem escapado).
                                //  • built-in: gêmeo JS inline → vale também pra
                                //    linha criada/editada no navegador.
                                // O valor da linha NUNCA é tocado: `row[chave]`
                                // segue cru, que é o que vai no POST.
                                $_cFmtKey = \Mad\Grid\GridRenderHelpers::fieldKeyJs($col->field);
                                $_cSrvFmt = $col->hasTransform || $col->mediaFormat !== '';
                                $_cFmtJs  = $col->builtinFormat !== ''
                                    ? \Mad\Grid\GridRenderHelpers::builtinFormatJs($col->builtinFormat, $_cv)
                                    : '';
                                // Tem valor pré-formatado NESTA linha? (a criada/editada
                                // no navegador não tem — cai no ramo cru, escapado
                                // pelo x-text, nunca no x-html).
                                $_cHasFmt = "(row.__fmt && row.__fmt[{$_cFmtKey}] != null)";
                            @endphp
                            <td class="mad-dg-cell" style="text-align:{{ $col->align }};">
                                @if($col->editable && $col->editMode === 'inline')
                                {{-- Inline edit: campo sempre visível --}}
                                @if($col->editType === 'money')
                                <input type="text" inputmode="decimal" class="mad-input mad-dg-inline-input"
                                       :value="madNumFmt({!! $_cv !!} || 0, {{ $col->editDecimals }})"
                                       @focus="madSelectOnFocus($event)"
                                       @blur="let v=madNumParse($event.target.value);$event.target.value=madNumFmt(v,{{ $col->editDecimals }});row['{{ $_ck }}']=v"
                                       style="text-align:{{ $col->align }};">
                                @elseif($col->editType === 'number')
                                <input type="number" class="mad-input mad-dg-inline-input"
                                       x-model="row['{{ $_ck }}']"
                                       step="{{ $col->editDecimals > 0 ? '0.'.str_repeat('0', $col->editDecimals - 1).'1' : '1' }}"
                                       style="text-align:{{ $col->align }};">
                                @else
                                <input type="text" class="mad-input mad-dg-inline-input"
                                       x-model="row['{{ $_ck }}']"
                                       style="text-align:{{ $col->align }};">
                                @endif
                                @elseif($_cSrvFmt)
                                {{-- Transform callable / token de mídia: HTML pronto do
                                     servidor (renderValue já escapou o que não é html).
                                     Linha do navegador não tem `__fmt` → span cru ao lado. --}}
                                <span x-html="{!! $_cHasFmt !!} ? row.__fmt[{!! $_cFmtKey !!}] : ''"
                                      x-show="!!{!! $_cHasFmt !!}"></span>
                                <span x-text="{!! $_cv !!}" x-show="!{!! $_cHasFmt !!}"></span>
                                @elseif($col->isBadge && !empty($col->badgeMap))
                                @php $badgeJson = json_encode($col->badgeMap); @endphp
                                <span x-html="(() => {
                                    let raw = {!! $_cv !!};
                                    let v = raw === true ? '1' : (raw === false ? '0' : (raw == null ? '' : String(raw)));
                                    let map = {{ $badgeJson }};
                                    let entry = map[v] ?? (typeof raw === 'boolean' ? map[String(raw)] : undefined);
                                    if (!entry) return v;
                                    let variant = 'secondary', lbl = v;
                                    if (typeof entry === 'string' && entry.includes(':')) {
                                        let parts = entry.split(':'); variant = parts[0]; lbl = parts[1] || v;
                                    } else if (typeof entry === 'string') { variant = entry; }
                                    return '<span class=&quot;mad-badge mad-badge-' + variant + '&quot;>' + lbl + '</span>';
                                })()"></span>
                                @elseif($col->isMoney)
                                <span x-text="'{{ $col->moneyPrefix }} ' + madNumFmt({!! $_cv !!} || 0, 2)"></span>
                                @elseif($col->isNumber)
                                {{-- attr `num=`/`number=` — faltava aqui e caía no valor cru --}}
                                <span x-text="madNumFmt({!! $_cv !!} || 0, {{ (int) $col->numberDecimals }})"></span>
                                @elseif($col->isDate && $col->dateFormat)
                                <span x-text="(() => { let v = {!! $_cv !!}; if (!v) return ''; try { let d = new Date(v); return d.toLocaleDateString('pt-BR'); } catch(e) { return v; } })()"></span>
                                @elseif($_cFmtJs !== '')
                                {{-- Token built-in do formatter-select (money/cpf/date-long/…):
                                     gêmeo JS do ValueFormatter, emitido inline. FALLBACK de
                                     propósito — os attrs de render-type acima vencem, igual
                                     ao GridColumn::renderValue. --}}
                                <span x-text="{!! $_cFmtJs !!}"></span>
                                @elseif($col->isHtml)
                                <span x-html="{!! $_cv !!}"></span>
                                @else
                                <span x-text="{!! $_cv !!}"></span>
                                @endif
                            </td>
                            @endforeach
                            <td class="mad-dg-cell mad-dg-actions-cell" style="text-align:center;">
                                <div class="mad-dg-actions">
                                    <button type="button" class="mad-dg-action-btn" title="Editar"
                                            @click="editRow(idx)">
                                        <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                                    </button>
                                    <button type="button" class="mad-dg-action-btn mad-dg-action-danger"
                                            title="Remover" @click="madDeletePopover($event, () => deleteRow(idx))">
                                        <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                    </button>
                                    @foreach($actions as $act)
                                    {{-- As linhas do detalhe são montadas no navegador (x-for), então
                                         não há linha em PHP para os callbacks por linha. Permissão não
                                         depende da linha — é da ação — e por isso vem de permMode(). --}}
                                    @if($act->permMode() !== 'hide')
                                    <button type="button"
                                            class="mad-dg-action-btn{{ $act->isPrimary ? ' mad-dg-action-primary' : '' }}{{ $act->isDanger ? ' mad-dg-action-danger' : '' }}"
                                            title="{{ $act->denyTitle() ?: $act->label }}"@if($act->permMode() === 'disable') aria-disabled="true" data-mad-deny @endif
                                            @click="callAction('{{ $act->method }}', row, idx)">
                                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                                    </button>
                                    @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>

                {{-- Empty state --}}
                <tbody x-show="rows.length === 0">
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}" class="mad-dg-cell" style="text-align:center;padding:24px;color:var(--mad-muted-fg);">
                            <i data-lucide="inbox" style="width:24px;height:24px;margin-bottom:8px;opacity:.5;"></i>
                            <div>Nenhum item adicionado</div>
                        </td>
                    </tr>
                </tbody>

                {{-- Totals --}}
                @if($hasTotals)
                <tfoot class="mad-dg-foot" x-show="rows.length > 0">
                    <tr>
                        @foreach($columns as $col)
                        @php
                            // Agregado calculado no cliente por `_agg()` (madDetailForm) —
                            // as mesmas cinco funções da grid (computeTotals). O total é
                            // formatado com a MESMA precedência da célula (renderValue:
                            // money → number → transform built-in → número pt-BR):
                            // `transform="money" total="sum"` mostrava 9.990,00 na linha
                            // e 9.990 no rodapé, porque aqui só `money=` era conhecido e
                            // o resto caía num toLocaleString sem casas decimais.
                            $_cv  = \Mad\Grid\GridRenderHelpers::rowValueJs($col->field, 'r');
                            $_agg = "_agg('{$col->totalFunc}', r => {$_cv})";
                            if ($col->totalFunc === 'count') {
                                $_tot = 'rows.length';               // contagem: sem máscara de dinheiro
                            } elseif (! in_array($col->totalFunc, ['sum', 'avg', 'min', 'max'], true)) {
                                $_tot = '';
                            } elseif ($col->isMoney) {
                                $_tot = "'{$col->moneyPrefix} ' + madNumFmt({$_agg}, 2)";
                            } elseif ($col->isNumber) {
                                $_tot = "madNumFmt({$_agg}, " . (int) $col->numberDecimals . ')';
                            } elseif ($col->builtinFormat !== '') {
                                $_tot = \Mad\Grid\GridRenderHelpers::builtinFormatJs($col->builtinFormat, $_agg);
                            } else {
                                $_tot = "madNumFmt({$_agg}, 2)";
                            }
                        @endphp
                        <td class="mad-dg-cell" style="text-align:{{ $col->align }};font-weight:600;">
                            @if($_tot !== '')
                            <span x-text="{!! $_tot !!}"></span>
                            @endif
                        </td>
                        @endforeach
                        <td></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>

        {{-- Paginação client-side --}}
        <template x-if="perPage > 0 && totalPages > 1">
            <div class="mad-df-pagination">
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" :disabled="page <= 1" @click="goToPage(page - 1)">
                    <i data-lucide="chevron-left" style="width:14px;height:14px;"></i>
                </button>
                <span class="mad-df-page-info" x-text="page + ' / ' + totalPages"></span>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" :disabled="page >= totalPages" @click="goToPage(page + 1)">
                    <i data-lucide="chevron-right" style="width:14px;height:14px;"></i>
                </button>
            </div>
        </template>
    </div>

</div>
