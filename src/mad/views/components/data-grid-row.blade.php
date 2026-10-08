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
==================================================================== --}}
@php
    $_isEven    = $isEven    ?? false;
    $_rowDepth  = $rowDepth  ?? 0;
    $_lastRow   = $_lastRow  ?? null;
    $_selectable = (bool) ($selectable ?? false);
    // Literal JS do id da linha (inteiro cru, resto como string JSON) — ver
    // MadDataGrid::rowIdJs().
    $rowIdJs = \Mad\Grid\MadDataGrid::rowIdJs($rowId);
@endphp
<tr class="mad-dg-row {{ $_isEven?'mad-dg-row-even':'mad-dg-row-odd' }}{{ $_rowDepth>0?' mad-dg-row-depth-'.$_rowDepth:'' }}" data-row-id="{{ $_rowPrefix }}{{ $rowId }}"@if($_selectable) :class="{ 'mad-dg-row-selected': isSelected({{ json_encode((string) $rowId) }}) }"@endif>
    @if($_selectable)@include('components.data-grid-select-cell', ['rowId' => $rowId])@endif
    @if($hasActions && $actionSide === 'left')
    <td class="mad-dg-cell mad-dg-actions-cell">
        <div class="mad-dg-actions">
            @foreach(array_filter($actions, fn($a)=>$a->isVisible($row)) as $act)
            @php $aId=$row[$act->idField]??$rowId; $aIdJs=\Mad\Grid\MadDataGrid::rowIdJs($aId); $ep=!empty($act->params)?', '.json_encode($act->rowParams($row)):''; $act=$act->getTransformed($row); @endphp
            @if($act->isNav)
            <button type="button" class="{{ $act->btnClass() }}" {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                    {!! $act->getNavAttr($aId, $row) !!}>
                @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                @if($act->label&&!$act->icon){{ $act->label }}@endif
            </button>
            @elseif($act->confirm || $act->confirmPopover)
            @php $_cMsg = $act->confirmPopover ?: $act->confirm; $_cType = $act->confirmPopover ? "'popover'" : "null"; @endphp
            <button type="button" class="{{ $act->btnClass() }}" {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                    @click="confirmAction({{ json_encode($_cMsg) }}, () => $dispatch('mad-dg-call',{method:{{ json_encode($act->method) }},params:[{{ $aIdJs }}{{ $ep }}]}), $event, {{ $_cType }})">
                @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                @if($act->label&&!$act->icon){{ $act->label }}@endif
            </button>
            @else
            <button type="button" class="{{ $act->btnClass() }}" {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                    mad:click="{{ $act->method }}({{ $aIdJs }}{{ $ep }})">
                @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                @if($act->label&&!$act->icon){{ $act->label }}@endif
            </button>
            @endif
            @endforeach
            @foreach($actionGroups as $grpAct)
            {{-- Grupo cujas acoes sumiram todas (perfil sem permissao) nao deixa o "..." orfao na linha: botao que abre um menu vazio e botao morto. --}}
            @php $_grpActs = array_filter($grpAct->actions, fn($a)=>$a->isVisible($row)); @endphp
            @if($_grpActs)
            <div class="mad-dg-dropdown-wrap" x-data="{open:false,pos:{top:'0px',right:'0px'},place(){let r=this.$refs.btn.getBoundingClientRect();this.pos={top:(r.bottom+4)+'px',right:(window.innerWidth-r.right)+'px'}}}" x-effect="if(open)place()" @scroll.window="open=false" @resize.window="open=false" style="position:relative;">
                <button type="button" class="mad-dg-action-btn{{ $grpAct->label ? ' mad-dg-action-btn-group' : '' }}" x-ref="btn" @click.stop="open=!open" title="{{ $grpAct->label }}">
                    @if($grpAct->icon)<i data-lucide="{{ $grpAct->icon }}" style="width:14px;height:14px;"></i>@endif
                    @if($grpAct->label)<span class="mad-dg-action-btn-label">{{ $grpAct->label }}</span>@endif
                </button>
                <template x-teleport="body"><div class="mad-dg-dropdown" x-show="open" x-cloak @click.outside="open=false" :style="'position:fixed;top:'+pos.top+';right:'+pos.right+';z-index:var(--mad-z-float,9999);'">
                    @foreach($_grpActs as $act)
                    @php $aId=$row[$act->idField]??$rowId; $aIdJs=\Mad\Grid\MadDataGrid::rowIdJs($aId); $ep=!empty($act->params)?', '.json_encode($act->rowParams($row)):''; $act=$act->getTransformed($row); $diCls='mad-dg-dropdown-item'.($act->isDanger?' mad-dg-dropdown-danger':''); @endphp
                    @if($act->isNav)
                    <button type="button" class="{{ $diCls }}" {!! $act->stateAttrs($row) !!}@if($act->denyTitle()) title="{{ $act->denyTitle() }}"@endif
                            @click="open=false" {!! $act->getNavAttr($aId, $row) !!}>
                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>@endif
                        {{ $act->label }}
                    </button>
                    @elseif($act->confirm || $act->confirmPopover)
                    @php $_cMsg = $act->confirmPopover ?: $act->confirm; $_cType = $act->confirmPopover ? "'popover'" : "null"; @endphp
                    <button type="button" class="{{ $diCls }}" {!! $act->stateAttrs($row) !!}@if($act->denyTitle()) title="{{ $act->denyTitle() }}"@endif
                            @click="open=false; confirmAction({{ json_encode($_cMsg) }}, () => $root.dispatchEvent(new CustomEvent('mad-dg-call',{detail:{method:{{ json_encode($act->method) }},params:[{{ $aIdJs }}{{ $ep }}]},bubbles:true})), $event, {{ $_cType }})">
                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>@endif
                        {{ $act->label }}
                    </button>
                    @else
                    <button type="button" class="{{ $diCls }}" {!! $act->stateAttrs($row) !!}@if($act->denyTitle()) title="{{ $act->denyTitle() }}"@endif
                            @click="open=false; $root.dispatchEvent(new CustomEvent('mad-dg-call',{detail:{method:'{{ $act->method }}',params:[{{ $aIdJs }}{{ $ep }}]},bubbles:true}))">
                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>@endif
                        {{ $act->label }}
                    </button>
                    @endif
                    @endforeach
                </div>
                </template>
            </div>
            @endif
            @endforeach
        </div>
    </td>
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
    <td class="mad-dg-cell"@if($col->editable) data-col="{{ $col->fieldKey }}"@endif :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }" style="text-align:{{ $col->align }};">
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
        {!! $rendered !!}
        @endif
    </td>
    @endforeach
    @if($hasActions && $actionSide === 'right')
    <td class="mad-dg-cell mad-dg-actions-cell">
        <div class="mad-dg-actions">
            @foreach(array_filter($actions, fn($a)=>$a->isVisible($row)) as $act)
            @php $aId=$row[$act->idField]??$rowId; $aIdJs=\Mad\Grid\MadDataGrid::rowIdJs($aId); $ep=!empty($act->params)?', '.json_encode($act->rowParams($row)):''; $act=$act->getTransformed($row); @endphp
            @if($act->isNav)
            <button type="button" class="{{ $act->btnClass() }}" {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                    {!! $act->getNavAttr($aId, $row) !!}>
                @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                @if($act->label&&!$act->icon){{ $act->label }}@endif
            </button>
            @elseif($act->confirm || $act->confirmPopover)
            @php $_cMsg = $act->confirmPopover ?: $act->confirm; $_cType = $act->confirmPopover ? "'popover'" : "null"; @endphp
            <button type="button" class="{{ $act->btnClass() }}" {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                    @click="confirmAction({{ json_encode($_cMsg) }}, () => $dispatch('mad-dg-call',{method:{{ json_encode($act->method) }},params:[{{ $aIdJs }}{{ $ep }}]}), $event, {{ $_cType }})">
                @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                @if($act->label&&!$act->icon){{ $act->label }}@endif
            </button>
            @else
            <button type="button" class="{{ $act->btnClass() }}" {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                    mad:click="{{ $act->method }}({{ $aIdJs }}{{ $ep }})">
                @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                @if($act->label&&!$act->icon){{ $act->label }}@endif
            </button>
            @endif
            @endforeach
            @foreach($actionGroups as $grpAct)
            {{-- Grupo cujas acoes sumiram todas (perfil sem permissao) nao deixa o "..." orfao na linha: botao que abre um menu vazio e botao morto. --}}
            @php $_grpActs = array_filter($grpAct->actions, fn($a)=>$a->isVisible($row)); @endphp
            @if($_grpActs)
            <div class="mad-dg-dropdown-wrap" x-data="{open:false,pos:{top:'0px',right:'0px'},place(){let r=this.$refs.btn.getBoundingClientRect();this.pos={top:(r.bottom+4)+'px',right:(window.innerWidth-r.right)+'px'}}}" x-effect="if(open)place()" @scroll.window="open=false" @resize.window="open=false" style="position:relative;">
                <button type="button" class="mad-dg-action-btn{{ $grpAct->label ? ' mad-dg-action-btn-group' : '' }}" x-ref="btn" @click.stop="open=!open" title="{{ $grpAct->label }}">
                    @if($grpAct->icon)<i data-lucide="{{ $grpAct->icon }}" style="width:14px;height:14px;"></i>@endif
                    @if($grpAct->label)<span class="mad-dg-action-btn-label">{{ $grpAct->label }}</span>@endif
                </button>
                <template x-teleport="body"><div class="mad-dg-dropdown" x-show="open" x-cloak @click.outside="open=false" :style="'position:fixed;top:'+pos.top+';right:'+pos.right+';z-index:var(--mad-z-float,9999);'">
                    @foreach($_grpActs as $act)
                    @php $aId=$row[$act->idField]??$rowId; $aIdJs=\Mad\Grid\MadDataGrid::rowIdJs($aId); $ep=!empty($act->params)?', '.json_encode($act->rowParams($row)):''; $act=$act->getTransformed($row); $diCls='mad-dg-dropdown-item'.($act->isDanger?' mad-dg-dropdown-danger':''); @endphp
                    @if($act->isNav)
                    <button type="button" class="{{ $diCls }}" {!! $act->stateAttrs($row) !!}@if($act->denyTitle()) title="{{ $act->denyTitle() }}"@endif
                            @click="open=false" {!! $act->getNavAttr($aId, $row) !!}>
                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>@endif
                        {{ $act->label }}
                    </button>
                    @elseif($act->confirm || $act->confirmPopover)
                    @php $_cMsg = $act->confirmPopover ?: $act->confirm; $_cType = $act->confirmPopover ? "'popover'" : "null"; @endphp
                    <button type="button" class="{{ $diCls }}" {!! $act->stateAttrs($row) !!}@if($act->denyTitle()) title="{{ $act->denyTitle() }}"@endif
                            @click="open=false; confirmAction({{ json_encode($_cMsg) }}, () => $root.dispatchEvent(new CustomEvent('mad-dg-call',{detail:{method:{{ json_encode($act->method) }},params:[{{ $aIdJs }}{{ $ep }}]},bubbles:true})), $event, {{ $_cType }})">
                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>@endif
                        {{ $act->label }}
                    </button>
                    @else
                    <button type="button" class="{{ $diCls }}" {!! $act->stateAttrs($row) !!}@if($act->denyTitle()) title="{{ $act->denyTitle() }}"@endif
                            @click="open=false; $root.dispatchEvent(new CustomEvent('mad-dg-call',{detail:{method:'{{ $act->method }}',params:[{{ $aIdJs }}{{ $ep }}]},bubbles:true}))">
                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:13px;height:13px;"></i>@endif
                        {{ $act->label }}
                    </button>
                    @endif
                    @endforeach
                </div>
                </template>
            </div>
            @endif
            @endforeach
        </div>
    </td>
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
