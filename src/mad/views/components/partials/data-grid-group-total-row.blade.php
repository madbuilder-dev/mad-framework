{{-- ====================================================================
    Subtotal de UM grupo da quebra (`group-total`) do MadDataGrid.

    O mesmo markup do laço de quebra do data-grid.blade.php, num partial para
    o relatório por janelas também desenhá-lo.

    Variáveis: $item (type=group-total: level, label, totals, totalLabel?),
    $visibleColumns, $selectable, $hasExpand, $hasActions, $actionSide.
==================================================================== --}}
@php
    $_gtCustom = (string) ($item['totalLabel'] ?? '');
    $_gtCaption = $_gtCustom !== '' ? $_gtCustom : __($item['level'] === 0 ? 'grid.total' : 'grid.subtotal');
    $_gtContext = $_gtCustom !== '' ? '' : $item['label'];
@endphp
<tr class="mad-dg-group-total mad-dg-group-total-level-{{ $item['level'] }}" data-level="{{ $item['level'] }}" style="--mad-dg-total-indent:{{ 12 + $item['level'] * 16 }}px;">
    @if($selectable)<td class="mad-dg-cell mad-dg-select-cell"></td>@endif
    @if($hasExpand)<td class="mad-dg-cell mad-dg-expand-cell"></td>@endif
    @if($hasActions && $actionSide === 'left')<td class="mad-dg-cell"></td>@endif
    @include('components.data-grid-total-cells', [
        'summaryColumns' => $visibleColumns,
        'summaryTotals' => $item['totals'],
        'summaryCaption' => $_gtCaption,
        'summaryContext' => $_gtContext,
    ])
    @if($hasActions && $actionSide === 'right')<td class="mad-dg-cell"></td>@endif
</tr>
