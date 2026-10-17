{{-- ====================================================================
    Chevron do <mad-row-expand> de UMA linha (fórum #127).

    Usado pelo data-grid.blade.php (linhas normais, agrupadas e cartão), pelo
    data-grid-row.blade.php (manage_row) e pelo MadDataGrid::_buildCardHtml().
    O clique é o Mad.rowAttach com `expand`: abre a tela alvo numa linha logo
    abaixo e fecha no segundo clique (aria-expanded acompanha). No cartão não
    há <tr>: o botão vai no rodapé e a tela abre na cortina lateral.

    Linha que não passa na condição (display-condition / when-*) ou perfil sem
    acesso à tela alvo (modo "ocultos"): a célula fica vazia — a coluna segue
    alinhada.

    Variáveis: $rxAction (GridAction|null), $row (array), $rowId,
               $card (bool, opcional — botão do rodapé do cartão)
==================================================================== --}}
@php
    $_rxCard = (bool) ($card ?? false);
    $_rx = (($rxAction ?? null) instanceof \Mad\Grid\GridAction && $rxAction->isVisible($row))
        ? $rxAction->getTransformed($row)
        : null;
    $_rxId = $_rx ? ($row[$_rx->idField] ?? $rowId) : null;
@endphp
@if($_rxCard)
@if($_rx)
<button type="button" class="mad-dg-card-action mad-dg-expand-btn" {!! $_rx->stateAttrs($row) !!}
        title="{{ $_rx->tooltip() }}" aria-expanded="false" {!! $_rx->getNavAttr($_rxId, $row) !!}>
    <i data-lucide="{{ $_rx->icon }}" style="width:14px;height:14px;"></i><span>{{ $_rx->label }}</span>
</button>
@endif
@else
<td class="mad-dg-cell mad-dg-expand-cell">
    @if($_rx)
    <button type="button" class="mad-dg-expand-btn" {!! $_rx->stateAttrs($row) !!}
            title="{{ $_rx->tooltip() }}" aria-label="{{ $_rx->label }}" aria-expanded="false"
            {!! $_rx->getNavAttr($_rxId, $row) !!}>
        <i data-lucide="{{ $_rx->icon }}" style="width:16px;height:16px;"></i>
    </button>
    @endif
</td>
@endif
