@php
    // ── Props (BladeOne nao suporta props nativamente) ────────────────────────
    $name      = $name      ?? 'field_list';
    $columns   = $columns   ?? [];          // FieldListColumn[]
    $actions   = $actions   ?? [];          // FieldListAction[]
    $rows      = $rows      ?? [];          // rows existentes (DB / vazio)
    $addable   = !empty($addable);
    $removable = !empty($removable);
    $sortable  = !empty($sortable);
    $maxRows   = (int)($maxRows  ?? 0);
    $label     = $label     ?? '';
    $hint      = $hint      ?? '';
    $error     = $error     ?? '';
    $addLabel   = $addLabel   ?? 'Adicionar linha';
    $class      = $class      ?? '';
    $model      = $model      ?? '';
    $foreignKey = $foreignKey ?? '';
    $database   = $database   ?? '';
    $onAdd      = $onAdd      ?? '';
    $onRemove   = $onRemove   ?? '';
    $onTotalize = $onTotalize ?? '';

    // F5: filtro extra removido (builder-native); detail sempre por FK.
    $_flCritToken = '';

    // ── Colunas com caminho de relacionamento (`{cidade->estado->pais->nome}`) ──
    // Não existem no toArray() da linha: só resolvem percorrendo as relações do
    // registro filho. Mesmo mecanismo do <mad-grid>/<mad-data-table>.
    $_flRenderFields = \Mad\Grid\GridRenderHelpers::detectRenderFields($columns);

    // ── Auto-load: se model+fk declarados e rows vazias, carrega do banco ─
    if ($model && $foreignKey && empty($rows)) {
        $_form = \Mad\Component\MadRenderContext::getForm();
        if ($_form) {
            $rows = $_form->autoLoadDetailRows($name, $model, $foreignKey, $database, $_flRenderFields);
        }
        unset($_form);
    }

    // ── Separa colunas visíveis das hidden ────────────────────────────────────
    $visibleCols = array_values(array_filter($columns, fn($c) => $c->type !== 'hidden'));
    $hiddenCols  = array_values(array_filter($columns, fn($c) => $c->type === 'hidden'));
    $hasSum      = !empty(array_filter($visibleCols, fn($c) => $c->doSum || $c->doCount));
    $computedCols = array_filter($columns, fn($c) => !empty($c->compute));

    // ── CSS Grid template (ex: '30% 30% 1fr 48px') ───────────────────────────
    // Cada coluna visível contribui com sua largura (ou '1fr' se vazia)
    // + coluna de ações customizadas (N*32 + 8px) quando $actions não vazio
    // + coluna de remover fixa (48px) quando removable=true
    //
    // A largura passa por cssTrack() e NÃO vai crua: `width="140"` (px implícito,
    // o contrato documentado) sem unidade é track inválido, e track inválido
    // invalida a declaração INTEIRA — o grid colapsa numa coluna e a lista toda
    // empilha. Normalizar aqui, em render, cobre os três caminhos de autoria de
    // uma vez: tag compilada, chain fluente e `:width="$x"` bind.
    $gridParts = array_map(fn($c) => \Mad\Form\FieldListColumn::cssTrack($c->width), $visibleCols);
    if ($sortable)  array_unshift($gridParts, '24px'); // espaço para o handle
    if (!empty($actions)) {
        $actionsWidth = (count($actions) * 32) + 8;
        $gridParts[]  = $actionsWidth . 'px';
    }
    if ($removable) $gridParts[] = '48px';
    $gridTemplate = implode(' ', $gridParts);

    // ── Bandas de grupo (<mad-field-list-group label="...">) ─────────────────
    // Derivadas de $visibleCols, NÃO de um array paralelo montado no compilador:
    // visibilidade é `type !== 'hidden'` avaliado AQUI, em render (e `type` aceita
    // bind PHP), então contar o span neste laço é a única forma de ele bater com
    // $gridTemplate. Span errado desalinha TODAS as bandas seguintes.
    $groupBands = [];
    $hasGroups  = false;
    foreach ($visibleCols as $_gc) {
        $_k    = $_gc->groupKey !== '' ? $_gc->groupKey : $_gc->group;
        $_last = $groupBands ? array_key_last($groupBands) : null;
        if ($_last !== null && $groupBands[$_last]['key'] === $_k) {
            $groupBands[$_last]['span']++;
        } else {
            $groupBands[] = ['key' => $_k, 'label' => $_gc->group, 'span' => 1];
        }
        if ($_gc->group !== '') $hasGroups = true;
    }
    if (!$hasGroups) $groupBands = [];   // custo zero nas páginas existentes

    // ── Normaliza rows e monta JSON de config para o Alpine ──────────────────
    $initialRows = \Mad\Form\FieldListColumn::normalizeRows($rows);

    // Registra os campos do field-list para getFieldList() no MadForm.
    // colMeta leva o que o SAVE precisa saber da coluna (force-case/strip-mask):
    // no request de save as colunas não são re-renderizadas, então o metadado
    // viaja no token cifrado. Só colunas que declaram algo entram.
    $_flColMeta = [];
    foreach ($columns as $_mc) {
        if (!$_mc->supportsTextPresentation()) {
            continue;
        }
        if ($_mc->forceCase === '' && !($_mc->stripMask && $_mc->mask !== '')) {
            continue;
        }
        $_flColMeta[$_mc->field] = [
            'mask'      => $_mc->mask,
            'stripMask' => $_mc->stripMask,
            'forceCase' => $_mc->forceCase,
        ];
    }
    \Mad\Form\MadFormRegistry::registerFieldList($name, array_map(fn($c) => $c->field, $columns), $model, $foreignKey, $database, $_flColMeta);

    // Colunas de arquivo (file/multifile/files) → auto-save por-linha via $form->save()
    $_flFileCols = [];
    foreach ($columns as $_fc) {
        if (in_array($_fc->type, ['file', 'multifile'], true)) {
            $_flFileCols[$_fc->field] = [
                'storage'    => $_fc->storage ?: 'disk',
                'folder'     => $_fc->folder ?: 'uploads',
                'fileName'   => $_fc->fileName ?: 'prefix',
                'nameColumn' => $_fc->nameColumn ?: '',
                'multi'      => $_fc->type === 'multifile',
            ];
        } elseif ($_fc->type === 'files' && $_fc->model && $_fc->foreignKey && $_fc->pathColumn) {
            // modal multi-arquivo → tabela NETO (1 linha por arquivo, disk ou db)
            $_flFileCols[$_fc->field] = [
                'kind'       => 'grandchild',
                'storage'    => $_fc->storage ?: 'disk',
                'folder'     => $_fc->folder ?: 'uploads',
                'fileName'   => $_fc->fileName ?: 'prefix',
                'model'      => $_fc->model,
                'foreignKey' => $_fc->foreignKey,
                'pathColumn' => $_fc->pathColumn,
                'nameColumn' => $_fc->nameColumn ?: '',
                'database'   => $_fc->database ?: $database,
            ];
        }
    }
    if ($_flFileCols) {
        \Mad\Form\MadFormRegistry::registerDetailFileColumns($name, $_flFileCols);
    }

    // type='files': injeta os netos já gravados em cada linha (edição) p/ o modal exibir.
    // Chave '__flfiles_<campo>' (prefixo __ → ignorada no save do item).
    $_filesCols = array_values(array_filter(
        $columns,
        fn ($c) => $c->type === 'files' && $c->model && $c->foreignKey && $c->pathColumn
    ));
    if ($_filesCols && !empty($initialRows)) {
        foreach ($initialRows as $_ri => $_r) {
            $_rid = $_r['id'] ?? null;
            foreach ($_filesCols as $_fc) {
                $_attr = '__flfiles_' . $_fc->field;
                $list  = [];
                $_isDb = (($_fc->storage ?: 'disk') === 'db');
                if ($_rid) {
                    try {
                        $_q = ($_fc->model)::where($_fc->foreignKey, $_rid);
                        $_q->where('storage', $_fc->storage ?: 'disk');
                        foreach ($_q->get() as $_gf) {
                            $_p  = (string) ($_gf->{$_fc->pathColumn} ?? '');
                            $_nm = $_fc->nameColumn ? (string) ($_gf->{$_fc->nameColumn} ?? '') : '';
                            $_nm = $_nm !== '' ? $_nm : ($_isDb ? ('arquivo_' . $_gf->id) : basename($_p));
                            $list[] = [
                                'id'   => (int) $_gf->id,
                                'name' => $_nm,
                                // chave de "manter" no reconcile do save: db→id, disk→path
                                'key'  => $_isDb ? ('id:' . $_gf->id) : $_p,
                                // download: disk via mad.download (path), db via mad.blob (base64)
                                'url'  => $_isDb
                                    ? mad_blob_url($_fc->model, (int) $_gf->id, $_fc->pathColumn, $_nm)
                                    : mad_download_url($_p, $_nm),
                            ];
                        }
                    } catch (\Throwable $e) {
                        // tabela neto ausente / sem conexão: ignora
                    }
                }
                $initialRows[$_ri][$_attr] = $list;
            }
        }
    }

    // Registra variáveis de autocomplete no VarRegistry para auto-bind
    foreach ($columns as $_col) {
        if ($_col->attrs && preg_match('/data-mad-autocomplete="([^"]+)"/', $_col->attrs, $_m)) {
            \Mad\Registry\MadVarRegistry::register($_m[1], 'completion');
        }
    }

    $cfgJson = json_encode([
        'columns'    => array_map(fn($c) => $c->toArray(), $columns),
        'actions'    => array_map(fn($a) => $a->toArray(), $actions),
        'rows'       => $initialRows,
        'maxRows'    => $maxRows,
        'addable'    => $addable,
        'removable'  => $removable,
        'sortable'   => $sortable,
        'onAdd'      => $onAdd,
        'onRemove'   => $onRemove,
        'onTotalize' => $onTotalize,
        'name'       => $name,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // ── Label obrigatório ─────────────────────────────────────────────────────
    $reqStar  = '';
    $hasReq   = !empty(array_filter($visibleCols, fn($c) => $c->required));
    $hasError = !empty($error);
@endphp

<div class="mad-field {{ $class }}">

    {{-- Label e hint --------------------------------------------------------- --}}
    @if($label)
        <span class="mad-label">{{ $label }}</span>
    @endif

    {{-- Container principal do field-list ------------------------------------ --}}
    <div class="mad-fl {{ $hasError ? 'mad-fl-has-error' : '' }}"
         data-mad-fl-name="{{ $name }}"
         x-data="madFieldList({{ $cfgJson }})"
         @if($sortable) data-sortable="1" @endif>

        {{-- Token de escopo: sobrevive ao POST para o auto-save aplicar o mesmo filtro --}}
        @if($_flCritToken)
            <input type="hidden" name="__mad_fl_crit[{{ $name }}]" value="{{ $_flCritToken }}">
        @endif

        {{-- Header ----------------------------------------------------------- --}}
        @if(count($visibleCols) > 0)
        {{-- Banda de grupos: mesmo grid-template do header, span por run de
             colunas visíveis, e as MESMAS células de filler (sortable/actions/
             removable) — o alinhamento sai por construção. --}}
        @if($hasGroups)
        <div class="mad-fl-group-band" style="grid-template-columns:{{ $gridTemplate }}">
            @if($sortable)
                <div class="mad-fl-group-cell mad-fl-group-spacer"></div>
            @endif
            @foreach($groupBands as $_b)
                <div class="mad-fl-group-cell{{ $_b['label'] === '' ? ' mad-fl-group-spacer' : '' }}"
                     style="grid-column:span {{ $_b['span'] }};"
                     @if($_b['label'] !== '') title="{{ $_b['label'] }}" @endif>{{ $_b['label'] }}</div>
            @endforeach
            @if(!empty($actions))
                <div class="mad-fl-group-cell mad-fl-group-spacer"></div>
            @endif
            @if($removable)
                <div class="mad-fl-group-cell mad-fl-group-spacer"></div>
            @endif
        </div>
        @endif
        <div class="mad-fl-header" style="grid-template-columns:{{ $gridTemplate }}">
            @if($sortable)
                <div class="mad-fl-header-cell"></div>
            @endif
            @foreach($visibleCols as $col)
                <div class="mad-fl-header-cell">
                    {{ $col->label }}
                    @if($col->required)
                        <span style="color:var(--mad-danger);margin-left:2px;">*</span>
                    @endif
                    @if($col->hint !== '')
                        {{-- title no WRAPPER, não no <i>: o lucide troca o <i> por
                             um <svg> e nem sempre copia os atributos. --}}
                        <span class="mad-fl-hint-icon" title="{{ $col->hint }}">
                            <i data-lucide="help-circle" style="width:12px;height:12px;"></i>
                        </span>
                    @endif
                </div>
            @endforeach
            @if(!empty($actions))
                <div class="mad-fl-actions-header"></div>
            @endif
            @if($removable)
                <div class="mad-fl-actions-header"></div>
            @endif
        </div>
        @endif

        {{-- Body: x-for (Alpine) + @foreach de colunas (Blade compile-time) -- --}}
        {{-- O @foreach compila PHP-time; o x-for repete o HTML gerado por linha --}}
        <div class="mad-fl-body" x-ref="body">

            {{-- Empty state -------------------------------------------------- --}}
            <div class="mad-fl-empty" x-show="rows.length === 0" x-cloak>
                <i data-lucide="inbox" style="width:18px;height:18px;flex-shrink:0;"></i>
                <span>Nenhum item adicionado</span>
            </div>

            <template x-for="(row, rowIndex) in rows" :key="row.__id">
                <div class="mad-fl-row" :data-row-id="row.__id"
                     x-init="$nextTick(() => { _madLucide(); if (typeof _madEnergizeFields === 'function') _madEnergizeFields($el); if (typeof _madFlApplyCachedOptions === 'function') _madFlApplyCachedOptions($el); })"
                     style="grid-template-columns:{{ $gridTemplate }}">

                    {{-- Hidden de rastreamento (__id por linha) --------------- --}}
                    <input type="hidden" name="__id[]" :value="row.__id">

                    {{-- Hidden columns --------------------------------------- --}}
                    @foreach($hiddenCols as $col)
                        <input type="hidden"
                               name="{{ $col->field }}[]"
                               x-model="row['{{ $col->field }}']">
                    @endforeach

                    {{-- Computed columns (x-effect auto-calc) --------------- --}}
                    @foreach($computedCols as $cc)
                        @php $_computeJs = \Mad\Form\FieldListColumn::compileFormula($cc->compute); @endphp
                        <span x-effect="row['{{ $cc->field }}'] = {{ $_computeJs }}" style="display:none"></span>
                    @endforeach

                    {{-- Drag handle (somente quando sortable) ---------------- --}}
                    @if($sortable)
                        <div class="mad-fl-handle" title="Arrastar para reordenar">
                            <i data-lucide="grip-vertical" style="width:13px;height:13px;"></i>
                        </div>
                    @endif

                    {{-- Células visíveis (compiladas PHP-time, repetidas pelo x-for) --}}
                    @foreach($visibleCols as $col)
                    @php
                        $fld = $col->field;
                        $ph  = htmlspecialchars($col->placeholder, ENT_QUOTES);

                        // Condições por-row compiladas ({campo} → row['campo']).
                        $dw = $col->disabledWhen !== '' ? \Mad\Form\FieldListColumn::compileCondition($col->disabledWhen) : '';
                        $rw = $col->readonlyWhen !== '' ? \Mad\Form\FieldListColumn::compileCondition($col->readonlyWhen) : '';
                        $qw = $col->requiredWhen !== '' ? \Mad\Form\FieldListColumn::compileCondition($col->requiredWhen) : '';
                        $vw = $col->visibleWhen  !== '' ? \Mad\Form\FieldListColumn::compileCondition($col->visibleWhen)  : '';

                        // on-change: método PHP (data-mad-fl-change) vs expressão Alpine
                        // (@change). Guard único — antes duplicado no select e no fallback,
                        // e AUSENTE em money/numeric/discount (expr Alpine ia crua pro
                        // data-mad-fl-change e virava "nome de método" no servidor).
                        $ocIsMethod = $col->onChange !== ''
                            && preg_match('/^[a-zA-Z_]\w*(\([^)]*\))?$/', trim($col->onChange))
                            && !str_contains($col->onChange, '$')
                            && !str_contains($col->onChange, ' ');
                    @endphp
                    {{-- hint também na célula: no mobile (<=640px) o header e a
                         banda somem, e só o data-label sobrevive via ::before. --}}
                    <div class="mad-fl-cell" data-label="{{ $col->label }}"
                         @if($col->hint !== '') title="{{ $col->hint }}" @endif
                         @if($vw !== '') x-show="{{ $vw }}" @endif>

                        @if($col->type === 'select' || $col->type === 'dbcombo')
                        @php
                            // ── Camada 1: auto-resolve dbcombo com depends-on + where ──
                            $depToken = '';
                            if ($col->type === 'dbcombo' && $col->model) {
                                // F5: option-load 100% Query Builder (where= via applyWhereStringToQuery).
                                $__m  = class_exists($col->model) ? $col->model : \Mad\Form\ModelOptionsLoader::resolveModelClass($col->model);
                                $__cq = $__m::query();
                                \Mad\Form\FieldListColumn::applyWhereStringToQuery($__cq, (string) $col->where);

                                $depValues = [];
                                if ($col->dependsOn && !empty($initialRows)) {
                                    $depValues = array_unique(array_filter(array_column($initialRows, $col->dependsOn)));
                                    if ($depValues) {
                                        $filterCol = $col->dependsColumn ?: $col->dependsOn;
                                        $__cq->whereIn($filterCol, array_values($depValues));
                                    }
                                }

                                // Só carrega se: não tem dependsOn OU tem valores no campo pai
                                if (!$col->dependsOn || !empty($depValues)) {
                                    $col->options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                                        $__cq,
                                        $col->keyField,
                                        $col->display,
                                        $col->orderBy ?: null,
                                        $col->orderDir
                                    );
                                }

                                // ── Camada 3: token criptografado para cascata AJAX ──────────
                                // Mesmo padrao do <mad-dbcombo-field>: o cliente nao manipula
                                // model/database/where — apenas recebe um token opaco. O
                                // servidor decripta em _autoLoadDependentOptions e aplica o
                                // where= do desenvolvedor no builder como base, somando o
                                // filtro da cascata por cima.
                                if ($col->dependsOn) {
                                    $depToken = \Mad\Http\MadStateCrypt::encryptFor('field-list-dep', [
                                        'database' => $col->database ?: (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business'),
                                        'model'    => $col->model,
                                        'key'      => $col->keyField,
                                        'display'  => $col->display,
                                        'order'    => $col->orderBy,
                                        'order_dir' => $col->orderDir,
                                        'column'   => $col->dependsColumn ?: $col->dependsOn,
                                        'where'    => $col->where,
                                    ]);
                                }
                            }
                        @endphp
                            <select class="mad-input-sm"
                                    name="{{ $fld }}[]"
                                    x-model="row['{{ $fld }}']"
                                    data-mad-select
                                    @if($col->required)  required  @endif
                                    @if($col->disabled)  disabled  @endif
                                    @if($dw !== '') :disabled="{{ $dw }}" @endif
                                    @if($qw !== '') :required="{{ $qw }}" @endif
                                    @if($col->onChange)
                                    @if($ocIsMethod)
                                        data-mad-fl-change="{{ $col->onChange }}"
                                    @else
                                        @change="{{ $col->onChange }}"
                                    @endif
                                    @endif
                                    @if($col->dependsOn)
                                        data-mad-fl-depends="{{ $col->dependsOn }}"
                                        data-mad-fl-dep-token="{{ $depToken }}"
                                    @endif
                                    @if($col->attrs) {!! $col->attrs !!} @endif>
                                <option value="">{{ $col->placeholder !== '' ? $col->placeholder : 'Selecione...' }}</option>
                                @foreach($col->options as $optVal => $optLabel)
                                    <option value="{{ $optVal }}"
                                            :selected="row['{{ $fld }}'] == '{{ $optVal }}'">{{ is_scalar($optLabel) ? $optLabel : $optVal }}</option>
                                @endforeach
                            </select>
                            @if($dw !== '')
                                {{-- select disabled NAO submete → este hidden assume o name
                                     exatamente quando o select esta desabilitado (binds
                                     invertidos: sempre UM dos dois entra no POST, arrays
                                     paralelos por row nunca desalinham). --}}
                                <input type="hidden" name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']"
                                       :disabled="!({{ $dw }})">
                            @endif

                        @elseif($col->type === 'money')
                        @php
                            $mDecimals = $col->decimals ?? 2;
                            $mPrefix   = $col->prefix ?? '';
                            $mMin      = $col->min;
                            $mMax      = $col->max;
                        @endphp
                            <div class="mad-fl-money"
                                 x-data="madMoneyCell({ decimals: {{ $mDecimals }}, min: {{ $mMin !== null ? $mMin : 'null' }}, max: {{ $mMax !== null ? $mMax : 'null' }}, field: '{{ $fld }}', row: row })">
                                @if($mPrefix)
                                    <span class="mad-fl-money-prefix">{{ $mPrefix }}</span>
                                @endif
                                <input type="text"
                                       inputmode="numeric"
                                       class="mad-input mad-input-sm"
                                       x-ref="input"
                                       :value="display"
                                       placeholder="{{ $ph ?: '0,' . str_repeat('0', $mDecimals) }}"
                                       @input="_onInput($event)"
                                       @blur="_onBlur()"
                                       @focus="madSelectOnFocus($event)"
                                       @keydown.enter="$event.target.blur()"
                                       @if($col->required) required @endif
                                       @if($col->readonly) readonly @endif
                                       @if($col->disabled) disabled @endif
                                       @if($dw !== '') :disabled="{{ $dw }}" @endif
                                       @if($rw !== '') :readonly="{{ $rw }}" @endif
                                       @if($qw !== '') :required="{{ $qw }}" @endif
                                       @if($col->onChange && !$ocIsMethod) @change="{{ $col->onChange }}" @endif
                                       @if($col->attrs) {!! $col->attrs !!} @endif>
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']"
                                       @if($col->onChange && $ocIsMethod) data-mad-fl-change="{{ $col->onChange }}" @endif>
                            </div>

                        @elseif($col->type === 'numeric')
                        @php
                            $nDecimals = $col->decimals ?? 2;
                            $nMin      = $col->min;
                            $nMax      = $col->max;
                        @endphp
                            <div x-data="madNumericCell({ decimals: {{ $nDecimals }}, min: {{ $nMin !== null ? $nMin : 'null' }}, max: {{ $nMax !== null ? $nMax : 'null' }}, field: '{{ $fld }}', row: row })">
                                <input type="text"
                                       inputmode="decimal"
                                       class="mad-input mad-input-sm"
                                       x-ref="input"
                                       :value="display"
                                       placeholder="{{ $ph ?: '0' }}"
                                       @input="_onInput($event)"
                                       @focus="_onFocus($event)"
                                       @blur="_onBlur($event)"
                                       @keydown.enter="$event.target.blur()"
                                       @if($col->required) required @endif
                                       @if($col->readonly) readonly @endif
                                       @if($col->disabled) disabled @endif
                                       @if($dw !== '') :disabled="{{ $dw }}" @endif
                                       @if($rw !== '') :readonly="{{ $rw }}" @endif
                                       @if($qw !== '') :required="{{ $qw }}" @endif
                                       @if($col->onChange && !$ocIsMethod) @change="{{ $col->onChange }}" @endif
                                       @if($col->attrs) {!! $col->attrs !!} @endif>
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']"
                                       @if($col->onChange && $ocIsMethod) data-mad-fl-change="{{ $col->onChange }}" @endif>
                            </div>

                        @elseif($col->type === 'discount' || $col->type === 'combo_input')
                        @php
                            $dDecimals   = $col->decimals ?? 2;
                            $dMin        = $col->min;
                            $dMax        = $col->max;
                            $dTypeField  = $col->typeField ?: ($fld . '_tipo');
                            $dOptions    = $col->options;
                            if (empty($dOptions) && $col->type === 'discount') {
                                $dOptions = ['%' => '%', 'R$' => 'R$'];
                            }
                            $dIsDiscount = $col->type === 'discount' ? 'true' : 'false';
                            $dDefaultOpt = !empty($dOptions) ? array_key_first($dOptions) : '';
                        @endphp
                            <div class="mad-fl-discount"
                                 x-data="madDiscountCell({ decimals: {{ $dDecimals }}, min: {{ $dMin !== null ? $dMin : 'null' }}, max: {{ $dMax !== null ? $dMax : 'null' }}, field: '{{ $fld }}', typeField: '{{ $dTypeField }}', row: row, isDiscount: {{ $dIsDiscount }}, defaultOption: '{{ $dDefaultOpt }}' })">
                                <input type="text"
                                       inputmode="numeric"
                                       class="mad-input mad-input-sm"
                                       x-ref="input"
                                       :value="display"
                                       placeholder="0,{{ str_repeat('0', $dDecimals) }}"
                                       @input="_onInput($event)"
                                       @blur="_onBlur()"
                                       @focus="madSelectOnFocus($event)"
                                       @keydown.enter="$event.target.blur()"
                                       @if($col->required) required @endif
                                       @if($col->readonly) readonly @endif
                                       @if($col->disabled) disabled @endif
                                       @if($dw !== '') :disabled="{{ $dw }}" @endif
                                       @if($rw !== '') :readonly="{{ $rw }}" @endif
                                       @if($qw !== '') :required="{{ $qw }}" @endif
                                       @if($col->onChange && !$ocIsMethod) @change="{{ $col->onChange }}" @endif
                                       @if($col->attrs) {!! $col->attrs !!} @endif>
                                <select class="mad-fl-discount-type"
                                        x-model="tipo"
                                        @change="_changeTipo($event)"
                                        @if($col->disabled) disabled @endif
                                        @if($dw !== '') :disabled="{{ $dw }}" @endif>
                                    @foreach($dOptions as $optVal => $optLabel)
                                        <option value="{{ $optVal }}">{{ is_scalar($optLabel) ? $optLabel : $optVal }}</option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="{{ $fld }}[]" x-model="row['{{ $fld }}']"
                                       @if($col->onChange && $ocIsMethod) data-mad-fl-change="{{ $col->onChange }}" @endif>
                                <input type="hidden" name="{{ $dTypeField }}[]" x-model="row['{{ $dTypeField }}']">
                            </div>

                        @elseif($col->type === 'file' || $col->type === 'multifile')
                        @php
                            $fAccept  = $col->accept ?: '';
                            $fMaxSize = (int)($col->maxSize ?: 0);
                            $fMulti   = $col->type === 'multifile' ? 'true' : 'false';
                        @endphp
                            <div class="mad-fl-file"
                                 x-data="madFileCell({ field: '{{ $fld }}', row: row, multi: {{ $fMulti }}, accept: '{{ $fAccept }}', maxSize: {{ $fMaxSize }} })"
                                 @if($col->attrs) {!! $col->attrs !!} @endif>

                                {{-- Lista de arquivos selecionados --}}
                                <template x-for="(fname, fi) in allNames()" :key="fi">
                                    <span class="mad-fl-file-tag">
                                        <i data-lucide="file" style="width:11px;height:11px;flex-shrink:0;"></i>
                                        <span x-text="fname" class="mad-fl-file-tag-name"></span>
                                        <button type="button" class="mad-fl-file-tag-x"
                                                @click="removeFile(fi)" title="Remover"
                                                @if($col->disabled) disabled @endif>&times;</button>
                                    </span>
                                </template>

                                {{-- Botão selecionar --}}
                                <button type="button" class="mad-fl-file-pick"
                                        x-show="{{ $fMulti }} || allNames().length === 0"
                                        @click="$refs.fileInput.click()"
                                        @if($col->disabled) disabled @endif
                                        @if($dw !== '') :disabled="{{ $dw }}" @endif>
                                    <i data-lucide="upload" style="width:12px;height:12px;"></i>
                                </button>

                                {{-- Input file real (oculto) --}}
                                <input type="file" x-ref="fileInput"
                                       style="display:none"
                                       @if($fAccept) accept="{{ $fAccept }}" @endif
                                       @if($col->type === 'multifile') multiple @endif
                                       @change="onSelect($event)">

                                {{-- Hidden para sincronizar nome no x-model --}}
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']">
                            </div>

                        @elseif($col->type === 'files')
                        @php
                            $fAccept   = $col->accept ?: '';
                            $fMaxSize  = (int)($col->maxSize ?: 0);
                            $fStorage  = $col->storage ?: 'disk';
                            $fExisting = '__flfiles_' . $col->field;
                        @endphp
                            <div class="mad-fl-files-cell"
                                 x-data="madFlFileModal({ field: '{{ $fld }}', row: row, flName: '{{ $name }}', storage: '{{ $fStorage }}', accept: '{{ $fAccept }}', maxSize: {{ $fMaxSize }}, existingKey: '{{ $fExisting }}' })">

                                {{-- Botão (abre modal) com contagem --}}
                                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm"
                                        @click="openModal()" @if($col->disabled) disabled @endif
                                        @if($dw !== '') :disabled="{{ $dw }}" @endif
                                        @if($col->attrs) {!! $col->attrs !!} @endif
                                        style="display:inline-flex;align-items:center;gap:6px;">
                                    <i data-lucide="paperclip" style="width:13px;height:13px;"></i>
                                    <span x-text="count() ? (count() + ' arquivo(s)') : 'Arquivos'"></span>
                                </button>

                                {{-- Hidden: chaves dos netos MANTIDOS (reconcile no save) --}}
                                <template x-for="(ef, i) in existing" :key="'k'+i">
                                    <input type="hidden" :name="'__mad_existing_files[' + keyFor() + '][]'" :value="ef.key">
                                </template>

                                {{-- Modal de upload --}}
                                <div class="mad-modal-overlay mad-flm-overlay" x-show="open" x-cloak style="z-index:1070;"
                                     @click.self="open=false" @keydown.escape.window="open=false">
                                    <div class="mad-modal mad-flm-modal" @click.stop>
                                        <div class="mad-modal-header">
                                            <div class="mad-modal-title">
                                                Arquivos da linha
                                                <span x-show="storage==='db'" style="font-size:11px;color:var(--mad-text-muted);">(banco / base64)</span>
                                                <span x-show="storage!=='db'" style="font-size:11px;color:var(--mad-text-muted);">(disco)</span>
                                                <span class="mad-badge mad-badge-secondary" x-show="count()>0" x-text="count()" style="margin-left:6px;"></span>
                                            </div>
                                            <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon" @click="open=false">&times;</button>
                                        </div>
                                        <div class="mad-modal-body">
                                            <div class="mad-file-body" @click="$refs.flmInput.click()"
                                                 style="cursor:pointer;border:1px dashed var(--mad-border);border-radius:8px;padding:22px;text-align:center;">
                                                <i data-lucide="upload-cloud" style="width:28px;height:28px;color:var(--mad-text-subtle);"></i>
                                                <div><strong>Clique para enviar</strong> ou arraste vários</div>
                                                @if($fAccept)<small style="color:var(--mad-text-muted);">{{ $fAccept }}</small>@endif
                                            </div>
                                            <input type="file" x-ref="flmInput" style="display:none" multiple
                                                   @if($fAccept) accept="{{ $fAccept }}" @endif @change="onSelect($event)">

                                            {{-- Galeria com miniaturas (imagens) ou ícone por extensão --}}
                                            <div class="mad-flm-gallery" x-show="count() > 0" x-cloak>
                                                <template x-for="(ef, i) in existing" :key="'e'+i">
                                                    <div class="mad-multi-file-card">
                                                        <template x-if="ef.isImage && ef.thumb">
                                                            <img :src="ef.thumb" class="mad-multi-file-card-img" alt="" loading="lazy">
                                                        </template>
                                                        <template x-if="!(ef.isImage && ef.thumb)">
                                                            <div class="mad-multi-file-card-icon" style="background:var(--mad-bg-muted,#f3f4f6);">
                                                                <i :data-lucide="ef.icon"></i>
                                                                <span class="mad-multi-file-card-ext" x-text="ef.ext"></span>
                                                            </div>
                                                        </template>
                                                        <div class="mad-multi-file-card-info">
                                                            <span class="mad-multi-file-card-name" x-text="ef.name" :title="ef.name"></span>
                                                            <span class="mad-multi-file-card-size">
                                                                <a x-show="ef.url" :href="ef.url" target="_blank" style="display:inline-flex;align-items:center;gap:3px;">
                                                                    <i data-lucide="download" style="width:11px;height:11px;"></i> baixar
                                                                </a>
                                                                <span x-show="!ef.url">existente</span>
                                                            </span>
                                                        </div>
                                                        <button type="button" class="mad-multi-file-card-remove" @click="removeExisting(i)" title="Remover">&times;</button>
                                                    </div>
                                                </template>
                                                <template x-for="(f, i) in files" :key="'n'+i">
                                                    <div class="mad-multi-file-card">
                                                        <template x-if="f.isImage && f.thumb">
                                                            <img :src="f.thumb" class="mad-multi-file-card-img" alt="" loading="lazy">
                                                        </template>
                                                        <template x-if="!(f.isImage && f.thumb)">
                                                            <div class="mad-multi-file-card-icon" style="background:var(--mad-bg-muted,#f3f4f6);">
                                                                <i :data-lucide="f.icon"></i>
                                                                <span class="mad-multi-file-card-ext" x-text="f.ext"></span>
                                                            </div>
                                                        </template>
                                                        <div class="mad-multi-file-card-info">
                                                            <span class="mad-multi-file-card-name" x-text="f.name" :title="f.name"></span>
                                                            <span class="mad-multi-file-card-size" style="color:var(--mad-success);"><span x-text="f.sizeText"></span> · novo</span>
                                                        </div>
                                                        <button type="button" class="mad-multi-file-card-remove" @click="removeNew(i)" title="Remover">&times;</button>
                                                    </div>
                                                </template>
                                            </div>
                                            <div x-show="count() === 0" style="color:var(--mad-text-muted);font-size:13px;text-align:center;padding:18px;">Nenhum arquivo nesta linha</div>
                                        </div>
                                        <div class="mad-modal-footer" style="padding:10px 16px;text-align:right;border-top:1px solid var(--mad-border);">
                                            <button type="button" class="mad-btn mad-btn-primary mad-btn-sm" @click="open=false">Concluir</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        @elseif($col->type === 'checkbox')
                            <label class="mad-checkbox-wrap" style="justify-content:center;min-height:28px;">
                                <input type="checkbox"
                                       class="mad-checkbox"
                                       :checked="row['{{ $fld }}'] == '1' || row['{{ $fld }}'] === true"
                                       @change="row['{{ $fld }}'] = $event.target.checked ? '1' : '0'{{ $col->onChange && !$ocIsMethod ? '; ' . $col->onChange : '' }}"
                                       @if($col->onChange && $ocIsMethod) data-mad-fl-change="{{ $col->onChange }}" @endif
                                       @if($col->disabled) disabled @endif
                                       @if($dw !== '') :disabled="{{ $dw }}" @endif
                                       @if($col->attrs) {!! $col->attrs !!} @endif>
                                <span class="mad-checkbox-box"></span>
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       :value="row['{{ $fld }}'] == '1' || row['{{ $fld }}'] === true ? '1' : '0'">
                            </label>

                        @elseif($col->type === 'toggle')
                            <label class="mad-toggle-wrap" style="justify-content:center;min-height:28px;">
                                <span class="mad-toggle">
                                    <input type="checkbox"
                                           :checked="row['{{ $fld }}'] == '1' || row['{{ $fld }}'] === true"
                                           @change="row['{{ $fld }}'] = $event.target.checked ? '1' : '0'{{ $col->onChange && !$ocIsMethod ? '; ' . $col->onChange : '' }}"
                                           @if($col->onChange && $ocIsMethod) data-mad-fl-change="{{ $col->onChange }}" @endif
                                           @if($col->disabled) disabled @endif
                                           @if($dw !== '') :disabled="{{ $dw }}" @endif
                                           @if($col->attrs) {!! $col->attrs !!} @endif>
                                    <span class="mad-toggle-track"></span>
                                </span>
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       :value="row['{{ $fld }}'] == '1' || row['{{ $fld }}'] === true ? '1' : '0'">
                            </label>

                        @elseif($col->type === 'radio')
                            <div class="mad-fl-radio-group">
                                @foreach($col->options as $optVal => $optLabel)
                                <label class="mad-radio-wrap">
                                    <input type="radio"
                                           class="mad-radio"
                                           :name="'{{ $fld }}_' + row.__id"
                                           value="{{ $optVal }}"
                                           :checked="row['{{ $fld }}'] == '{{ $optVal }}'"
                                           @change="row['{{ $fld }}'] = '{{ $optVal }}'{{ $col->onChange && !$ocIsMethod ? '; ' . $col->onChange : '' }}"
                                           @if($col->onChange && $ocIsMethod) data-mad-fl-change="{{ $col->onChange }}" @endif
                                           @if($col->disabled) disabled @endif
                                           @if($dw !== '') :disabled="{{ $dw }}" @endif>
                                    <span class="mad-radio-circle"></span>
                                    <span class="mad-radio-label">{{ is_scalar($optLabel) ? $optLabel : $optVal }}</span>
                                </label>
                                @endforeach
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']">
                            </div>

                        @elseif($col->type === 'textarea')
                            <textarea class="mad-input mad-input-sm"
                                      name="{{ $fld }}[]"
                                      rows="2"
                                      x-model="row['{{ $fld }}']"
                                      placeholder="{{ $ph }}"
                                      @if($col->required)  required  @endif
                                      @if($col->readonly)  readonly  @endif
                                      @if($col->disabled)  disabled  @endif
                                      @if($dw !== '') :disabled="{{ $dw }}" @endif
                                      @if($rw !== '') :readonly="{{ $rw }}" @endif
                                      @if($qw !== '') :required="{{ $qw }}" @endif
                                      @if($col->onChange)
                                      @if($ocIsMethod)
                                          data-mad-fl-change="{{ $col->onChange }}"
                                      @else
                                          @change="{{ $col->onChange }}"
                                      @endif
                                      @endif
                                      @if($col->attrs) {!! $col->attrs !!} @endif></textarea>
                            @if($dw !== '')
                                {{-- textarea disabled NAO submete → hidden com bind invertido
                                     assume o name (mesmo padrao do select acima). --}}
                                <input type="hidden" name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']"
                                       :disabled="!({{ $dw }})">
                            @endif

                        @elseif($col->type === 'date')
                        @php
                            $minIso = '';
                            $maxIso = '';
                            if ($col->min !== null) { $ts = strtotime((string)$col->min); if ($ts) $minIso = date('Y-m-d', $ts); }
                            if ($col->max !== null) { $ts = strtotime((string)$col->max); if ($ts) $maxIso = date('Y-m-d', $ts); }
                            $dpCfg = [
                                'displayMask'  => 'dd/mm/yyyy',
                                'databaseMask' => 'yyyy-mm-dd',
                                'min'          => $minIso,
                                'max'          => $maxIso,
                            ];
                        @endphp
                            <div class="mad-fl-date mad-input-group" style="position:relative;"
                                 x-data="madDatePicker(Object.assign({{ json_encode($dpCfg, JSON_UNESCAPED_UNICODE) }}, {
                                    initialValue: row['{{ $fld }}'] || '',
                                    onCommit: function(iso) { row['{{ $fld }}'] = iso; }
                                 }))"
                                 x-init="$watch(() => row['{{ $fld }}'], v => setIso(v))"
                                 @keydown.escape.window="close()"
                                 @click.outside="close()">
                                <input type="text"
                                       class="mad-input mad-input-sm"
                                       placeholder="{{ $ph ?: 'dd/mm/aaaa' }}"
                                       autocomplete="off"
                                       inputmode="numeric"
                                       @if($col->required) required @endif
                                       @if($col->readonly) readonly @endif
                                       @if($col->disabled) disabled @endif
                                       @if($dw !== '') :disabled="{{ $dw }}" @endif
                                       @if($rw !== '') :readonly="{{ $rw }}" @endif
                                       @if($qw !== '') :required="{{ $qw }}" @endif
                                       {{-- o picker dispara change neste input no commit
                                            (mad-ui.js _writeInput) → dual-mode funciona --}}
                                       @if($col->onChange)
                                       @if($ocIsMethod)
                                           data-mad-fl-change="{{ $col->onChange }}"
                                       @else
                                           @change="{{ $col->onChange }}"
                                       @endif
                                       @endif
                                       @if($col->attrs) {!! $col->attrs !!} @endif>
                                <button type="button" class="mad-fl-date-btn mad-datepicker-btn" tabindex="-1"
                                        @click="toggle()"
                                        @if($col->disabled || $col->readonly) disabled @endif
                                        @if($dw !== '') :disabled="{{ $dw }}" @endif>
                                    <i data-lucide="calendar" style="width:12px;height:12px;"></i>
                                </button>
                                <input type="hidden"
                                       name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']">
                                @include('components.partials.date-popup', ['withTime' => false])
                            </div>

                        @else
                            {{-- text, number, email, tel, etc. ----------------- --}}
                            @php
                                // ── Apresentação da célula (fw 5.40) ─────────────────
                                // Paridade com <mad-input-field>: máscara, force-case,
                                // ícone, largura máxima, toggle de senha. Só nos tipos
                                // texto-ish (supportsTextPresentation): em money/numeric/
                                // date o runtime já formata no próprio @input, e um
                                // data-mad-mask por cima disputaria o mesmo evento.
                                $_pres    = $col->supportsTextPresentation();
                                $_mask    = $_pres ? $col->mask                 : '';
                                $_force   = $_pres ? strtolower($col->forceCase): '';
                                $_maxlen  = $_pres ? $col->effectiveMaxlength() : null;
                                $_icon    = $_pres ? $col->icon                 : '';
                                $_toggle  = $_pres && $col->togglePassword;
                                $_iconSd  = $col->iconSideNormalized();
                                $_useGrp  = $_icon !== '' || $_toggle;
                                $_iconSty = $col->iconColor !== ''
                                    ? ' style="color:' . htmlspecialchars($col->iconColor, ENT_QUOTES) . '"'
                                    : '';
                                // max-width limita o INPUT dentro da célula; `width` é o
                                // track da coluna no grid e continua no $gridTemplate.
                                // A normalização mora no FieldListColumn::cellDimStyle()
                                // — ver a nota lá sobre o FieldDimStyleTest.
                                $_inpDim  = $col->cellDimStyle();
                                $_force   = in_array($_force, ['upper', 'lower', 'title'], true) ? $_force : '';
                            @endphp
                            @if($_useGrp)
                            <div class="mad-input-group"@if($_toggle) x-data="{ pwVisible: false }"@endif>
                            @endif
                            @if($_icon !== '' && $_iconSd === 'left')
                                <span class="mad-input-addon"{!! $_iconSty !!}>
                                    <i data-lucide="{{ $_icon }}" style="width:13px;height:13px;"></i>
                                </span>
                            @endif
                            <input @if($_toggle) :type="pwVisible ? 'text' : 'password'" @else type="{{ $col->type }}" @endif
                                   class="mad-input mad-input-sm"
                                   name="{{ $fld }}[]"
                                   x-model="row['{{ $fld }}']"
                                   placeholder="{{ $ph }}"
                                   @if($col->required)        required                    @endif
                                   @if($col->readonly)        readonly                    @endif
                                   @if($col->disabled)        disabled                    @endif
                                   @if($dw !== '') :disabled="{{ $dw }}" @endif
                                   @if($rw !== '') :readonly="{{ $rw }}" @endif
                                   @if($qw !== '') :required="{{ $qw }}" @endif
                                   @if($col->min !== null)    min="{{ $col->min }}"       @endif
                                   @if($col->max !== null)    max="{{ $col->max }}"       @endif
                                   @if($col->type === 'number') step="{{ $col->step }}"  @endif
                                   @if($_mask !== '')   data-mad-mask="{{ $_mask }}"      @endif
                                   @if($_force !== '')  data-mad-force="{{ $_force }}"    @endif
                                   @if($_maxlen !== null) maxlength="{{ $_maxlen }}"      @endif
                                   @if($_inpDim !== '') style="{{ $_inpDim }}"            @endif
                                   @if($col->onChange)
                                   @if($ocIsMethod)
                                       data-mad-fl-change="{{ $col->onChange }}"
                                   @else
                                       @change="{{ $col->onChange }}"
                                   @endif
                                   @endif
                                   @if($col->attrs) {!! $col->attrs !!}                  @endif>
                            @if($_icon !== '' && $_iconSd === 'right' && !$_toggle)
                                <span class="mad-input-addon mad-input-addon-r"{!! $_iconSty !!}>
                                    <i data-lucide="{{ $_icon }}" style="width:13px;height:13px;"></i>
                                </span>
                            @endif
                            @if($_toggle)
                                <button type="button" class="mad-password-toggle-btn" tabindex="-1"
                                        @if($col->disabled || $col->readonly) disabled @endif
                                        @click="pwVisible = !pwVisible">
                                    <i data-lucide="eye"     x-show="!pwVisible" style="width:13px;height:13px;"></i>
                                    <i data-lucide="eye-off" x-show="pwVisible"  style="width:13px;height:13px;" x-cloak></i>
                                </button>
                            @endif
                            @if($_useGrp)
                            </div>
                            @endif
                            @if($dw !== '')
                                {{-- input disabled NAO submete → hidden com bind invertido
                                     assume o name (mesmo padrao do select/textarea). --}}
                                <input type="hidden" name="{{ $fld }}[]"
                                       x-model="row['{{ $fld }}']"
                                       :disabled="!({{ $dw }})">
                            @endif
                        @endif

                    </div>
                    @endforeach

                    {{-- Acoes customizadas ----------------------------------- --}}
                    @if(!empty($actions))
                        <div class="mad-fl-actions-cell">
                            @foreach($actions as $act)
                                @php
                                    $actJson  = json_encode($act->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    $btnCls   = $act->btnClass();
                                    $btnStyle = $act->btnStyle();
                                    $btnTitle = $act->title !== '' ? $act->title : $act->label;
                                @endphp
                                <button type="button"
                                        class="{{ $btnCls }}"
                                        @if($btnStyle !== '') style="{{ $btnStyle }}" @endif
                                        @if($btnTitle !== '') title="{{ $btnTitle }}" @endif
                                        @click="_runAction({{ $actJson }}, row, rowIndex, $event)">
                                    @if($act->icon)
                                        <i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>
                                    @endif
                                    @if($act->label !== '')
                                        <span class="mad-fl-action-btn-label">{{ $act->label }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Botão remover --------------------------------------- --}}
                    @if($removable)
                        <div class="mad-fl-actions-cell">
                            <button type="button"
                                    class="mad-fl-remove-btn"
                                    @click="removeRow(rowIndex)"
                                    title="Remover linha">
                                <i data-lucide="x" style="width:13px;height:13px;"></i>
                            </button>
                        </div>
                    @endif

                </div>
            </template>
        </div>
        {{-- /mad-fl-body ---------------------------------------------------- --}}

        {{-- Linha de totais (apenas quando há colunas com .sum()) ----------- --}}
        @if($hasSum)
        <div class="mad-fl-totals" style="grid-template-columns:{{ $gridTemplate }}">
            @if($sortable) <div class="mad-fl-cell"></div> @endif
            @foreach($visibleCols as $col)
                <div class="mad-fl-cell mad-fl-total-cell">
                    @if($col->doSum)
                        <span x-text="getSum('{{ $col->field }}')"></span>
                    @elseif($col->doCount)
                        <span x-text="getCount()"></span>
                    @endif
                </div>
            @endforeach
            @if(!empty($actions)) <div class="mad-fl-actions-cell"></div> @endif
            @if($removable) <div class="mad-fl-actions-cell"></div> @endif
        </div>
        @endif

        {{-- Botão adicionar linha ------------------------------------------- --}}
        @if($addable)
        <div class="mad-fl-add-row">
            <button type="button"
                    class="mad-btn mad-btn-ghost mad-btn-sm mad-fl-add-btn"
                    @click="addRow()"
                    :disabled="maxRows > 0 && rows.length >= maxRows">
                <i data-lucide="plus" style="width:13px;height:13px;"></i>
                {{ $addLabel }}
            </button>
            <span x-show="maxRows > 0" class="mad-text-xs mad-text-muted" style="margin-left:var(--mad-s2);">
                (<span x-text="rows.length"></span>/{{ $maxRows }})
            </span>
        </div>
        @endif

    </div>
    {{-- /mad-fl --}}

    {{-- Hint / Error --------------------------------------------------------- --}}
    @if($error)
        <div class="mad-field-hint" style="color:var(--mad-danger);">{{ $error }}</div>
    @elseif($hint)
        <div class="mad-field-hint">{{ $hint }}</div>
    @endif

</div>
{{-- /mad-field --}}
