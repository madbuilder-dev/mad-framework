{{-- ====================================================================
    Renderiza UMA <tr> completa do MadDataGrid, com editores inline
    (mad-dg-edit-click / -inline / -dblclick), botoes de acao etc.

    Usado:
      1. Pelo template principal data-grid.blade.php via @include
      2. Pelo MadDataGrid::renderSingleRow() (manage_row partial update)

    Variaveis esperadas:
      $row                  array dos dados da linha
      $rowId                int|string PK
      $_rowPrefix           string prefixo do data-row-id (ex: 'MyGrid_')
      $visibleColumns       GridColumn[] colunas visiveis
      $actions              GridAction[]
      $actionGroups         GridActionGroup[]
      $hasActions           bool
      $actionSide           'left'|'right'
      $editComboOptions     array (cache para dbcombo, indexado por field)
      $editSearchToken      array (token AJAX para dbunique-search)
      $editSearchPreloaded  array ([colField => [id => label]])
      $_lastRow             array|null (linha anterior, pra transforms)
      $isEven               bool (opcional — pra classe row-even/odd)
      $rowDepth             int (opcional)
      $selectable           bool (opcional — <mad-grid selectable>: célula do checkbox)
      $_hl                  array|null (opcional — regra de <mad-row-highlights> que casou,
                            ver MadDataGrid::rowHighlightFor())
      $rowExpandAction      GridAction|null (opcional — chevron do <mad-row-expand>)
==================================================================== --}}
@php
    // Forma das colunas (fw#494b) pela própria linha: código/tipo inteiros.
    \Mad\Grid\GridColumn::assignShapes($visibleColumns ?? [], [$row ?? []]);
    \Mad\Grid\GridColumn::assignCardRoles($visibleColumns ?? [], (array) ($gridCardColumns ?? []), [$row ?? []]);
    $_isEven    = $isEven    ?? false;
    $_rowDepth  = $rowDepth  ?? 0;
    $_lastRow   = $_lastRow  ?? null;
    $_selectable = (bool) ($selectable ?? false);
    // Literal JS do id da linha (inteiro cru, resto como string JSON) — ver
    // MadDataGrid::rowIdJs().
    $rowIdJs = \Mad\Grid\MadDataGrid::rowIdJs($rowId);
    // Destaque por condição (<mad-row-highlights>): a 1ª regra que casa.
    $_hl = $_hl ?? null;
    $_hlStyle = \Mad\Grid\MadDataGrid::rowHighlightStyle($_hl);
    $_rxAction = (($rowExpandAction ?? null) instanceof \Mad\Grid\GridAction) ? $rowExpandAction : null;
@endphp
<tr class="mad-dg-row {{ $_isEven?'mad-dg-row-even':'mad-dg-row-odd' }}{{ $_rowDepth>0?' mad-dg-row-depth-'.$_rowDepth:'' }}{{ \Mad\Grid\MadDataGrid::rowHighlightClass($_hl) }}" data-row-id="{{ $_rowPrefix }}{{ $rowId }}"@if($_hlStyle !== '') style="{{ $_hlStyle }}"@endif @if($_selectable) :class="{ 'mad-dg-row-selected': isSelected({{ json_encode((string) $rowId) }}) }"@endif>
    @if($_selectable)@include('components.data-grid-select-cell', ['rowId' => $rowId])@endif
    @if($_rxAction)@include('components.data-grid-expand-cell', ['rxAction' => $_rxAction, 'row' => $row, 'rowId' => $rowId])@endif
    @if($hasActions && $actionSide === 'left')
    @include('components.data-grid-actions-cell')
    @endif
    @foreach($visibleColumns as $col)
    @php
        $cellVal  = $row[$col->field] ?? '';
        $rendered = $col->renderValue($cellVal, $row, $_lastRow);
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
                    'editComboOptions'    => $editComboOptions,
                    'editSearchToken'     => $editSearchToken,
                    'editSearchPreloaded' => $editSearchPreloaded,
                ])
            </div>
        </div>
        @else
        <div class="mad-dg-edit-dblclick"
             x-show="!isEditing({{ $rowIdJs }},'{{ $col->field }}')"
             @dblclick="startEdit({{ $rowIdJs }},'{{ $col->field }}',{{ json_encode($editInitVal) }})"
             title="{{ __('grid.edit') }}"
             style="min-height:18px;cursor:text;">{!! $rendered !== '' ? $rendered : '<span style="color:var(--mad-muted-fg);opacity:.5;font-size:11px;">duplo-clique p/ editar</span>' !!}</div>
        <div x-show="isEditing({{ $rowIdJs }},'{{ $col->field }}')" x-cloak>
            @include('components.data-grid-edit-cell', [
                'col'                 => $col,
                'cellVal'             => $cellVal,
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
</tr>
@if(!empty($row['__detail']))
{{-- row-detail: a mesma 2ª linha descritiva do render inicial. O manage_row
     troca a <tr> da linha; o mad.js remove a detail antiga antes, senão a
     descrição velha sobreviveria à edição. --}}
<tr class="mad-dg-row-detail {{ $_isEven?'mad-dg-row-even':'mad-dg-row-odd' }}" data-detail-for="{{ $_rowPrefix }}{{ $rowId }}">
    <td :colspan="visibleColCount()">{{ $row['__detail'] }}</td>
</tr>
@endif
