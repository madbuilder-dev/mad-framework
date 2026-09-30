{{--
    mad-data-table — read-only embeddable grid.

    Props (resolvidos por GridRenderHelpers::renderDataTable):
      columns    - GridColumn[]
      rows       - array<int, array> (com __record)  (usado quando groupData vazio)
      groupData  - lista flatten de items (group/row/group-total) — quando ha group-by
      groupBy    - string[] (campos de agrupamento; vazio = sem grupo)
      groupTotal - bool (mostrar subtotal por grupo)
      totals     - [field => htmlFormatado] (footer geral; vazio = sem footer)
      emptyText  - mensagem quando empty
      zebra      - linhas alternadas
      bordered   - bordas em todas celulas
      compact    - padding menor
      class      - classes extras
--}}
@php
    $cols       = $columns   ?? [];
    $rows       = $rows      ?? [];
    $groupData  = $groupData ?? [];
    $groupBy    = $groupBy   ?? [];
    $groupTotal = $groupTotal ?? false;
    $totals     = $totals    ?? [];
    $hasGroups  = !empty($groupData);
    $colCount   = count($cols);
    $tblClasses = 'mad-dt';
    if (!empty($zebra))    $tblClasses .= ' mad-dt-zebra';
    if (!empty($bordered)) $tblClasses .= ' mad-dt-bordered';
    if (!empty($compact))  $tblClasses .= ' mad-dt-compact';
    if (!empty($class))    $tblClasses .= ' ' . $class;
@endphp

@if (empty($rows))
    <div class="mad-dt-empty">
        <i data-lucide="inbox" style="width:32px;height:32px;color:#9ca3af;"></i>
        <div style="margin-top:8px;color:#6b7280;font-size:13px;">{{ $emptyText ?? 'Sem registros' }}</div>
    </div>
@else
<table class="{{ $tblClasses }}">
    <thead>
        <tr>
            @foreach ($cols as $col)
                @php
                    $thStyle = '';
                    if ($col->align)  $thStyle .= 'text-align:' . $col->align . ';';
                    $_w = \Mad\Support\CssUnits::length((string) ($col->width ?? ''));
                    if ($_w !== '') $thStyle .= 'width:' . $_w . ';';
                @endphp
                <th @if($thStyle) style="{{ $thStyle }}" @endif>{{ $col->label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @if ($hasGroups)
            @foreach ($groupData as $item)
                @if ($item['type'] === 'group')
                    @php $indent = 8 + (int)$item['level'] * 16; @endphp
                    <tr class="mad-dt-group mad-dt-group-l{{ $item['level'] }}">
                        <td colspan="{{ $colCount }}" style="padding-left:{{ $indent }}px;">
                            <strong>{{ $item['label'] }}</strong>
                            <span class="mad-dt-group-count">({{ $item['count'] }})</span>
                            @if ($groupTotal && !empty($item['totals']))
                                <span class="mad-dt-group-subtotals">
                                    @foreach ($item['totals'] as $f => $v)
                                        <span class="mad-dt-group-subtotal">
                                            <span class="mad-dt-st-lbl">Total:</span>
                                            {{-- RAW por contrato: totals são HTML pré-formatado do render helper.
                                                 Caller DEVE passar conteúdo pré-escapado/numérico (nunca input cru). --}}
                                            <span class="mad-dt-st-val">{!! $v !!}</span>
                                        </span>
                                    @endforeach
                                </span>
                            @endif
                        </td>
                    </tr>
                @elseif ($item['type'] === 'row')
                    @php $indent = 8 + (int)$item['level'] * 16; $row = $item['data']; @endphp
                    <tr>
                        @foreach ($cols as $i => $col)
                            @php
                                $val = $row[$col->field] ?? '';
                                $tdStyle = '';
                                if ($col->align) $tdStyle .= 'text-align:' . $col->align . ';';
                                if ($i === 0)    $tdStyle .= 'padding-left:' . $indent . 'px;';
                            @endphp
                            <td @if($tdStyle) style="{{ $tdStyle }}" @endif>{!! $col->renderValue($val, $row) !!}</td>
                        @endforeach
                    </tr>
                @elseif ($item['type'] === 'group-total' && !empty($item['totals']))
                    @php $indent = 8 + (int)$item['level'] * 16; @endphp
                    <tr class="mad-dt-group-total mad-dt-group-total-l{{ $item['level'] }}">
                        @foreach ($cols as $i => $col)
                            @php
                                $tdStyle = '';
                                if ($col->align) $tdStyle .= 'text-align:' . $col->align . ';';
                                if ($i === 0)    $tdStyle .= 'padding-left:' . $indent . 'px;';
                            @endphp
                            <td @if($tdStyle) style="{{ $tdStyle }}" @endif>
                                @if ($i === 0)
                                    <em>Subtotal {{ $item['label'] }}</em>
                                @else
                                    {{-- RAW por contrato: total pré-formatado. Caller DEVE passar
                                         conteúdo pré-escapado/numérico (nunca input cru). --}}
                                    {!! $item['totals'][$col->field] ?? '' !!}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            @endforeach
        @else
            @foreach ($rows as $row)
                <tr>
                    @foreach ($cols as $col)
                        @php $val = $row[$col->field] ?? ''; @endphp
                        <td @if($col->align) style="text-align:{{ $col->align }};" @endif>{!! $col->renderValue($val, $row) !!}</td>
                    @endforeach
                </tr>
            @endforeach
        @endif
    </tbody>
    @if (!empty($totals))
    <tfoot>
        <tr>
            @foreach ($cols as $col)
                <td @if($col->align) style="text-align:{{ $col->align }};" @endif>
                    {{-- RAW por contrato: footer totals são HTML pré-formatado do render helper.
                         Caller DEVE passar conteúdo pré-escapado/numérico (nunca input cru). --}}
                    {!! $totals[$col->field] ?? '' !!}
                </td>
            @endforeach
        </tr>
    </tfoot>
    @endif
</table>
@endif
