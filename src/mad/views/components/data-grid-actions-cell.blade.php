{{-- Célula das ações de uma linha da grade (botões + grupos "..."). Uma cópia
     só para os quatro laços que desenham linhas (agrupado e plano do
     data-grid, e o data-grid-row do manage_row), dos dois lados: herda do
     include $actions, $actionGroups, $row, $rowId e $gridActionsInline. --}}
@php
    // Menu ⋮ (fw#494): com mais ações visíveis que N (actions-inline; 2 = o
    // padrão do ERP), as N-1 primeiras ficam na linha e o resto vai para o ⋮,
    // que ocupa a N-ésima vaga. As que vão para o menu saem também na linha
    // com `data-act-over`: quem escolhe entre as duas é o CSS (ERP, ou
    // data-actions-inline no default), porque o tema é escolha do navegador.
    // A 1ª ação fica sempre na linha (com N >= 2): é ela que o row-click dispara.
    $_rowActs = array_values(array_filter($actions ?? [], fn($a)=>$a->isVisible($row)));
    $_inlineN = ($gridActionsInline ?? 0) === 'all' ? PHP_INT_MAX : ((int) ($gridActionsInline ?? 0) ?: 2);
    $_overAt  = count($_rowActs) > $_inlineN ? $_inlineN - 1 : PHP_INT_MAX;
    // Ação do clique na linha (a 1ª que não é de exclusão, como o onRowClick):
    // no cartão do celular (fw#495) o cartão inteiro faz o clique e o botão
    // dela sai do cartão — fica o ⋮ no canto. Sem row-click, só ação de abrir
    // (mad-nav, ícone de ver/editar): "Aprovar" segue como botão.
    $_rcAt = PHP_INT_MAX;
    foreach ($_rowActs as $_i => $_a) {
        if ($_a->isDanger) continue;
        if (!empty($rowClick) || $_a->opensRecord()) $_rcAt = $_i;
        break;
    }
    // Grupos e o ⋮ desenham o mesmo menu; o ⋮ vem por último, no fim da linha.
    $_menus = [];
    foreach (($actionGroups ?? []) as $grpAct) {
        // Grupo cujas ações sumiram todas (perfil sem permissão) não deixa o
        // "..." órfão na linha: botão que abre um menu vazio e botão morto.
        $_grpActs = array_filter($grpAct->actions, fn($a)=>$a->isVisible($row));
        if ($_grpActs) $_menus[] = ['icon' => $grpAct->icon, 'label' => $grpAct->label, 'title' => $grpAct->label, 'acts' => $_grpActs, 'more' => false];
    }
    if ($_overAt < count($_rowActs)) {
        $_menus[] = ['icon' => 'more-vertical', 'label' => '', 'title' => mad_t('mad.grid.more_actions'), 'acts' => array_slice($_rowActs, $_overAt), 'more' => true];
    }
@endphp
<td class="mad-dg-cell mad-dg-actions-cell">
    <div class="mad-dg-actions">
        @foreach($_rowActs as $act)
        @php $aId=$row[$act->idField]??$rowId; $aIdJs=\Mad\Grid\MadDataGrid::rowIdJs($aId); $ep=!empty($act->params)?', '.json_encode($act->rowParams($row)):''; $act=$act->getTransformed($row); $_over=($loop->index >= $_overAt ? ' data-act-over' : '') . ($loop->index === $_rcAt ? ' data-row-act' : ''); @endphp
        @if($act->isNav)
        <button type="button" class="{{ $act->btnClass() }}"{{ $_over }} {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                {!! $act->getNavAttr($aId, $row) !!}>
            @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
            @if($act->label&&!$act->icon){{ $act->label }}@elseif($act->label&&$act->showLabel)<span class="mad-dg-action-btn-label">{{ $act->label }}</span>@endif
        </button>
        @elseif($act->confirm || $act->confirmPopover)
        @php $_cMsg = $act->confirmPopover ?: $act->confirm; $_cType = $act->confirmPopover ? "'popover'" : "null"; @endphp
        <button type="button" class="{{ $act->btnClass() }}"{{ $_over }} {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                @click="confirmAction({{ json_encode($_cMsg) }}, () => $dispatch('mad-dg-call',{method:{{ json_encode($act->method) }},params:[{{ $aIdJs }}{{ $ep }}]}), $event, {{ $_cType }})">
            @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
            @if($act->label&&!$act->icon){{ $act->label }}@elseif($act->label&&$act->showLabel)<span class="mad-dg-action-btn-label">{{ $act->label }}</span>@endif
        </button>
        @else
        <button type="button" class="{{ $act->btnClass() }}"{{ $_over }} {!! $act->stateAttrs($row) !!} title="{{ $act->tooltip() }}"
                mad:click="{{ $act->method }}({{ $aIdJs }}{{ $ep }})">
            @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
            @if($act->label&&!$act->icon){{ $act->label }}@elseif($act->label&&$act->showLabel)<span class="mad-dg-action-btn-label">{{ $act->label }}</span>@endif
        </button>
        @endif
        @endforeach
        @foreach($_menus as $_menu)
        {{-- Menu (grupo ou ⋮): Esc fecha e devolve o foco ao botão; abrir um
             fecha o que estiver aberto (o @click.stop do botão não deixa o
             @click.outside do outro saber do clique); abre para dentro da
             grade — alinhado pela esquerda quando o botão está na metade
             esquerda (coluna de ações à esquerda), senão pela direita. --}}
        <div class="mad-dg-dropdown-wrap{{ $_menu['more'] ? ' mad-dg-act-more' : '' }}" x-data="{{ \Mad\Grid\MadDataGrid::ROW_MENU_XDATA }}" x-effect="if(open)place()" @scroll.window="open=false" @resize.window="open=false" @keydown.escape.window="if(open){open=false;$refs.btn.focus()}" @mad-dg-menu-open.window="if($event.detail!==$refs.btn)open=false" style="position:relative;">
            <button type="button" class="mad-dg-action-btn{{ $_menu['label'] ? ' mad-dg-action-btn-group' : '' }}" x-ref="btn" @click.stop="open=!open;if(open)$dispatch('mad-dg-menu-open',$refs.btn)" title="{{ $_menu['title'] }}"@if($_menu['more']) aria-label="{{ $_menu['title'] }}" aria-haspopup="menu" :aria-expanded="open"@endif>
                @if($_menu['icon'])<i data-lucide="{{ $_menu['icon'] }}" style="width:14px;height:14px;"></i>@endif
                @if($_menu['label'])<span class="mad-dg-action-btn-label">{{ $_menu['label'] }}</span>@endif
            </button>
            <template x-teleport="body"><div class="mad-dg-dropdown" x-init="menu=$el" x-show="open" x-cloak @click.outside="open=false" :style="'position:fixed;top:'+pos.top+';'+pos.side+':'+pos.x+';z-index:var(--mad-z-float,9999);'">
                @foreach($_menu['acts'] as $act)
                @php $aId=$row[$act->idField]??$rowId; $aIdJs=\Mad\Grid\MadDataGrid::rowIdJs($aId); $ep=!empty($act->params)?', '.json_encode($act->rowParams($row)):''; $act=$act->getTransformed($row); $diCls=$act->menuClass(); @endphp
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
        @endforeach
    </div>
</td>
