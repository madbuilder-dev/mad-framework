{{-- ====================================================================
    Célula do checkbox de seleção de UMA linha (<mad-grid selectable>).

    Usado pelo data-grid.blade.php (linhas normais e agrupadas) e pelo
    data-grid-row.blade.php (manage_row). O estado vive no Alpine
    `madDataGrid` (isSelected/toggleRow); sem `name` de propósito: não é
    campo de formulário — a seleção viaja no campo oculto `__mad_grid_sel`.

    Variáveis: $rowId (int|string)
==================================================================== --}}
@php $_selId = json_encode((string) $rowId, JSON_UNESCAPED_UNICODE); @endphp
<td class="mad-dg-cell mad-dg-select-cell" @click.stop>
    <input type="checkbox" class="mad-dg-select" value="{{ $rowId }}"
           aria-label="{{ __('grid.select_row') }}"
           :checked="isSelected({{ $_selId }})"
           @change="toggleRow({{ $_selId }}, $event.target.checked)">
</td>
