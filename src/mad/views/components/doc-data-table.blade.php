{{--
    Tabular component bound to a collection on the master record.

    Example (syntax shown without tag brackets — the MadBladeOne
    preprocessor replaces `mad-*` tag tokens in source, which would
    recursively invoke this component if placed inside a comment):

        mad-doc-data-table
            :rows="$record->itens"
            :columns="[
                ['label' => 'Produto', 'field' => 'produto.nome',       'align' => 'left'],
                ['label' => 'Qtd',     'field' => 'quantidade',         'align' => 'right', 'format' => 'integer', 'total' => 'sum'],
                ['label' => 'Valor',   'field' => 'valor_unit',         'align' => 'right', 'format' => 'money',   'total' => 'sum'],
                ['label' => 'Total',   'formula' => '{quantidade}*{valor_unit}', 'field_name' => 'subtotal',
                                       'align' => 'right', 'format' => 'money', 'total' => 'sum', 'total_name' => 'valor_total'],
            ]"
            group-by="categoria"
            :zebra="true"

    Column config keys:
      label, field|formula, attrs[], separator, field_name (only for formula),
      align (left/center/right), format (text|money|number|integer|date|datetime|boolean),
      width, total (sum|count|avg|min|max), total_name.
--}}
@props([
    'rows' => [],
    'columns' => [],
    'groupBy' => null,
    'groupHeader' => true,
    'groupSubtotals' => true,
    'headerBg' => '#f3f4f6',
    'zebra' => true,
    'borders' => true,
    'cellPadding' => 6,
    'fontSize' => 10,
    'compact' => false,
    'totals' => null,
])

@php
    use Mad\Doc\MadDocRuntime;

    // Normalize styles
    $borderStyle = $borders ? '1px solid #d1d5db' : 'none';
    $cellPad     = $compact ? 3 : (int) $cellPadding;
    $fontSizePt  = (int) $fontSize;

    // Normalize rows into both an array version (for formulas) and the
    // original (for attribute accessor on objects/Eloquent models).
    $rowList = [];
    $arrayRows = [];
    foreach ($rows as $idx => $r) {
        $rowList[$idx] = $r;
        $arrayRows[$idx] = is_array($r) ? $r : (is_object($r) ? (array) $r : []);
    }

    // cellText[$ri][$ci] = display string
    // cellNum[$ci][$ri]  = float|null (for totals)
    $cellText = [];
    $cellNum  = array_fill(0, count($columns), []);

    foreach ($columns as $ci => $col) {
        foreach ($arrayRows as $ri => $rowArr) {
            $raw = null;

            if (! empty($col['formula'])) {
                $raw = MadDocRuntime::evaluateFormula((string) $col['formula'], $arrayRows[$ri]);
                if (! empty($col['field_name'])) {
                    // publish into the row so subsequent computed cols can chain.
                    $arrayRows[$ri][$col['field_name']] = $raw;
                }
            } elseif (! empty($col['mask'])) {
                // Free-form template: each `{path}` token is pulled from the
                // row; literal text between tokens is preserved verbatim.
                $raw = preg_replace_callback(
                    '/\{([^{}]+)\}/',
                    fn ($m) => (string) MadDocRuntime::pull($rowList[$ri], trim($m[1])),
                    (string) $col['mask'],
                );
            } elseif (! empty($col['attrs']) && is_array($col['attrs'])) {
                $sep = (string) ($col['separator'] ?? ' ');
                $parts = [];
                foreach ($col['attrs'] as $a) {
                    $parts[] = MadDocRuntime::pull($rowList[$ri], (string) $a);
                }
                $raw = count($parts) === 1 ? $parts[0] : implode($sep, array_map('strval', $parts));
            } elseif (! empty($col['field'])) {
                $raw = MadDocRuntime::pull($rowList[$ri], (string) $col['field']);
            }

            $fmt = (string) ($col['format'] ?? 'text');
            $cellText[$ri][$ci] = MadDocRuntime::applyFormat($raw, $fmt);
            $cellNum[$ci][$ri]  = is_numeric($raw) ? (float) $raw : null;
        }
    }

    // Global totals row.
    $hasAnyTotal = false;
    $totalsRow = [];
    foreach ($columns as $ci => $col) {
        $op = strtolower((string) ($col['total'] ?? ''));
        if ($op === '') { $totalsRow[$ci] = ''; continue; }
        $hasAnyTotal = true;
        $nums = array_filter($cellNum[$ci], fn ($v) => $v !== null);
        $value = match ($op) {
            'count' => count($arrayRows),
            'sum'   => array_sum($nums),
            'avg'   => count($nums) > 0 ? array_sum($nums) / count($nums) : 0,
            'min'   => $nums ? min($nums) : 0,
            'max'   => $nums ? max($nums) : 0,
            default => array_sum($nums),
        };
        $fmt = (string) ($col['format'] ?? ($op === 'count' ? 'integer' : 'money'));
        $totalsRow[$ci] = MadDocRuntime::applyFormat($value, $fmt);

        // Publish named total into the shared $totals bag (ArrayObject is
        // passed by ref in Blade component slots). Chips that reference
        // `$totals['name']` elsewhere in the doc then resolve.
        if (! empty($col['total_name']) && $totals !== null) {
            $name = (string) $col['total_name'];
            if ($totals instanceof \ArrayAccess || is_array($totals)) {
                $totals[$name] = $totalsRow[$ci];
            }
        }
    }

    // Grouping: bucket rows by a scalar accessor, preserve insertion order.
    $groups = null;
    if (! empty($groupBy)) {
        $buckets = [];
        foreach ($rowList as $ri => $row) {
            $key = MadDocRuntime::pull($row, (string) $groupBy);
            $label = is_scalar($key) ? (string) $key : '';
            if (! isset($buckets[$label])) {
                $buckets[$label] = ['label' => $label, 'rowIds' => [], 'subtotals' => []];
            }
            $buckets[$label]['rowIds'][] = $ri;
        }
        foreach ($buckets as &$b) {
            foreach ($columns as $ci => $col) {
                $op = strtolower((string) ($col['total'] ?? ''));
                if ($op === '') { $b['subtotals'][$ci] = ''; continue; }
                $scoped = array_filter(
                    array_intersect_key($cellNum[$ci], array_flip($b['rowIds'])),
                    fn ($v) => $v !== null,
                );
                $value = match ($op) {
                    'count' => count($b['rowIds']),
                    'sum'   => array_sum($scoped),
                    'avg'   => count($scoped) > 0 ? array_sum($scoped) / count($scoped) : 0,
                    'min'   => $scoped ? min($scoped) : 0,
                    'max'   => $scoped ? max($scoped) : 0,
                    default => array_sum($scoped),
                };
                $fmt = (string) ($col['format'] ?? ($op === 'count' ? 'integer' : 'money'));
                $b['subtotals'][$ci] = MadDocRuntime::applyFormat($value, $fmt);
            }
        }
        unset($b);
        $groups = array_values($buckets);
    }

    $colCount = count($columns);
@endphp

<table style="width:100%;border-collapse:collapse;font-size:{{ $fontSizePt }}pt;margin:2pt 0;">
    <thead>
        <tr style="background:{{ $headerBg }};">
            @foreach($columns as $col)
                <th @if(! empty($col['width'])) width="{{ $col['width'] }}" @endif
                    style="border:{{ $borderStyle }};padding:{{ $cellPad }}px;text-align:{{ $col['align'] ?? 'left' }};font-weight:600;">
                    {{ $col['label'] ?? '' }}
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @if($groups !== null)
            @foreach($groups as $g)
                @if($groupHeader)
                <tr>
                    <td colspan="{{ $colCount }}" style="border:{{ $borderStyle }};padding:{{ $cellPad }}px;background:#eff6ff;color:#1e40af;font-weight:600;">
                        ▸ {{ $g['label'] !== '' ? $g['label'] : '(sem valor)' }}
                    </td>
                </tr>
                @endif
                @foreach($g['rowIds'] as $ri)
                    <tr style="background:{{ $zebra && $loop->index % 2 === 1 ? '#f9fafb' : 'transparent' }};">
                        @foreach($columns as $ci => $col)
                            <td style="border:{{ $borderStyle }};padding:{{ $cellPad }}px;text-align:{{ $col['align'] ?? 'left' }};vertical-align:top;">
                                {{ $cellText[$ri][$ci] ?? '' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                @if($groupSubtotals)
                <tr style="background:#fef3c7;font-weight:600;">
                    @foreach($columns as $ci => $col)
                        <td style="border:{{ $borderStyle }};padding:{{ $cellPad }}px;text-align:{{ $col['align'] ?? 'left' }};color:#92400e;">
                            {{ $g['subtotals'][$ci] ?? '' }}
                        </td>
                    @endforeach
                </tr>
                @endif
            @endforeach
        @else
            @foreach($arrayRows as $ri => $_)
                <tr style="background:{{ $zebra && $loop->index % 2 === 1 ? '#f9fafb' : 'transparent' }};">
                    @foreach($columns as $ci => $col)
                        <td style="border:{{ $borderStyle }};padding:{{ $cellPad }}px;text-align:{{ $col['align'] ?? 'left' }};vertical-align:top;">
                            {{ $cellText[$ri][$ci] ?? '' }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        @endif
    </tbody>
    @if($hasAnyTotal)
        <tfoot>
            <tr style="background:#f3f4f6;font-weight:600;">
                @foreach($columns as $ci => $col)
                    <td style="border:{{ $borderStyle }};padding:{{ $cellPad }}px;text-align:{{ $col['align'] ?? 'left' }};">
                        {{ $totalsRow[$ci] ?? '' }}
                    </td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>
