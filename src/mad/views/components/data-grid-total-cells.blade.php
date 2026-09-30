@php
    // Reserva a primeira sequência de colunas sem cálculo para o rótulo.
    // O colspan acompanha o seletor de colunas; nenhum total é substituído.
    $summaryLabelIndexes = [];
    foreach ($summaryColumns as $summaryIndex => $summaryColumn) {
        if (!isset($summaryTotals[$summaryColumn->field])) {
            $summaryLabelIndexes[] = $summaryIndex;
        } elseif ($summaryLabelIndexes !== []) {
            break;
        }
    }
    $summaryLabelKeys = array_map(fn($index) => $summaryColumns[$index]->fieldKey, $summaryLabelIndexes);
    $summaryLabelKeysJs = json_encode($summaryLabelKeys);
    $summaryPreviousKeys = [];
@endphp
@foreach($summaryColumns as $summaryIndex => $summaryColumn)
    @if(in_array($summaryIndex, $summaryLabelIndexes, true))
        @if($summaryIndex === $summaryLabelIndexes[0])
        <td class="mad-dg-cell mad-dg-total-cell mad-dg-summary-label-cell" colspan="{{ count($summaryLabelIndexes) }}"
            :colspan="Math.max(1, {{ $summaryLabelKeysJs }}.filter(key => !isColHidden(key)).length)"
            :class="{ 'mad-dg-col-hidden': !{{ $summaryLabelKeysJs }}.some(key => !isColHidden(key)) }">
            <span class="mad-dg-group-total-label">
                <span class="mad-dg-total-caption">{{ $summaryCaption }}</span>
                @if($summaryContext !== '')<span class="mad-dg-total-context">{{ $summaryContext }}</span>@endif
            </span>
        </td>
        @endif
    @else
        <td class="mad-dg-cell mad-dg-total-cell" style="text-align:{{ $summaryColumn->align }};"
            :class="{ 'mad-dg-col-hidden': isColHidden({{ json_encode($summaryColumn->fieldKey) }}) }">
            {{-- Se as colunas do rótulo forem ocultadas (ou todas calcularem),
                 identifica o resumo dentro da primeira célula ainda visível. --}}
            <span class="mad-dg-group-total-label mad-dg-total-label-fallback" x-cloak
                :class="{ 'mad-dg-col-hidden': {{ $summaryLabelKeysJs }}.some(key => !isColHidden(key)) || {{ json_encode($summaryPreviousKeys) }}.some(key => !isColHidden(key)) }">
                <span class="mad-dg-total-caption">{{ $summaryCaption }}</span>
                @if($summaryContext !== '')<span class="mad-dg-total-context">{{ $summaryContext }}</span>@endif
            </span>
            @if(isset($summaryTotals[$summaryColumn->field])){!! $summaryTotals[$summaryColumn->field] !!}@endif
        </td>
    @endif
    @php $summaryPreviousKeys[] = $summaryColumn->fieldKey; @endphp
@endforeach
