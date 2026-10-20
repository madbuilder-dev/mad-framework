{{-- ====================================================================
    Cabeçalho de UM grupo da quebra (`group-by`) do MadDataGrid.

    O mesmo markup do laço de quebra do data-grid.blade.php, num partial para
    o relatório por janelas também desenhá-lo: as linhas que chegam rolando a
    tela trazem os cabeçalhos dos grupos que começam nelas.

    Variáveis: $item (type=group: level, label, count, totals),
    $visibleColumns, $groupBandCells, $hasGroupFooters, $selectable,
    $hasExpand, $hasActions, $actionSide.
==================================================================== --}}
@php
    $lvlIcon = $item['level'] === 0 ? 'layers' : 'corner-down-right';
    $iconSz  = $item['level'] === 0 ? '14px' : '12px';
    // group-band="cells": quantas colunas livres existem à
    // esquerda até a 1ª totalizada. Zero = não há onde pôr o
    // rótulo sem engolir um valor → cai na banda inline.
    $_gbSpan = 0;
    if ($groupBandCells && !empty($item['totals'])) {
        $_gbSpan = \Mad\Grid\MadGridExporter::groupTotalCells($visibleColumns, $item['totals'], '')['labelSpan'];
    }
    // O colspan do rótulo acompanha o seletor de colunas: as
    // colunas que ele cobre podem ser ocultadas em tempo de
    // execução e, com o colspan fixo, a banda ficava mais larga
    // que a tabela e TODOS os totais da quebra escorregavam uma
    // coluna para a direita. Mesma conta da linha de sub-total
    // (data-grid-total-cells).
    $_gbKeysJs = json_encode(array_map(
        fn($c) => $c->fieldKey,
        array_slice($visibleColumns, 0, $_gbSpan)
    ));
@endphp
@if($_gbSpan >= 1)
{{-- Banda alinhada: cada total sob a SUA coluna e o rótulo em
     colspan. O sticky de quebra é desligado neste modo (o proxy
     flutuante desalinharia o cabeçalho — ver mad-ui.js). --}}
<tr class="mad-dg-group-row mad-dg-group-cells mad-dg-group-level-{{ $item['level'] }}" data-level="{{ $item['level'] }}">
    @if($selectable)<td class="mad-dg-cell mad-dg-select-cell"></td>@endif
    @if($hasExpand)<td class="mad-dg-cell mad-dg-expand-cell"></td>@endif
    @if($hasActions && $actionSide === 'left')<td class="mad-dg-cell"></td>@endif
    <td colspan="{{ $_gbSpan }}"
        :colspan="Math.max(1, {{ $_gbKeysJs }}.filter(key => !isColHidden(key)).length)"
        :class="{ 'mad-dg-col-hidden': !{{ $_gbKeysJs }}.some(key => !isColHidden(key)) }">
        <div class="mad-dg-group-cell" style="padding-left:{{ 12 + $item['level'] * 16 }}px;">
            <span class="mad-dg-group-icon"><i data-lucide="{{ $lvlIcon }}" style="width:{{ $iconSz }};height:{{ $iconSz }};"></i></span>
            <span class="mad-dg-group-label">{{ $item['label'] }}</span>
            <span class="mad-dg-group-count">{{ trans_choice('grid.group_records', $item['count'], ['count' => $item['count']]) }}</span>
        </div>
    </td>
    @foreach(array_slice($visibleColumns, $_gbSpan) as $col)
    <td class="mad-dg-cell mad-dg-total-cell{{ $col->nowrapClass() }}" :class="{ 'mad-dg-col-hidden': isColHidden('{{ $col->fieldKey }}') }" style="text-align:{{ $col->align }};">
        @if(isset($item['totals'][$col->field])){!! $item['totals'][$col->field] !!}@endif
    </td>
    @endforeach
    @if($hasActions && $actionSide === 'right')<td class="mad-dg-cell"></td>@endif
</tr>
@else
<tr class="mad-dg-group-row mad-dg-group-level-{{ $item['level'] }}" data-level="{{ $item['level'] }}">
    <td :colspan="visibleColCount()">
        <div class="mad-dg-group-cell" style="padding-left:{{ 12 + $item['level'] * 16 }}px;">
            <span class="mad-dg-group-icon"><i data-lucide="{{ $lvlIcon }}" style="width:{{ $iconSz }};height:{{ $iconSz }};"></i></span>
            <span class="mad-dg-group-label">{{ $item['label'] }}</span>
            <span class="mad-dg-group-count">{{ trans_choice('grid.group_records', $item['count'], ['count' => $item['count']]) }}</span>
            @if(!$hasGroupFooters && !empty($item['totals']))
            <span class="mad-dg-group-subtotals">
                @foreach($visibleColumns as $col)
                @if(isset($item['totals'][$col->field]))
                <span class="mad-dg-group-subtotal-item">
                    <span class="mad-dg-group-subtotal-label">{{ $col->label }}:</span>
                    <span class="mad-dg-group-subtotal-value">{!! $item['totals'][$col->field] !!}</span>
                </span>
                @endif
                @endforeach
            </span>
            @endif
        </div>
    </td>
</tr>
@endif
