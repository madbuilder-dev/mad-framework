<?php

namespace Mad\Ai;

/**
 * BlockValidator — espelho PHP de embed/contract/validate.ts.
 *
 * Valida/normaliza o input de uma render tool num bloco (array com 'type'), ou
 * null se inutilizavel. Itens invalidos dentro de arrays sao descartados; bloco
 * sem item usavel vira null. Mantem a MESMA semantica do front (que tambem
 * revalida no LiveTransport) — nao divergir.
 */
final class BlockValidator
{
    private const TONES = ['pos', 'warn', 'neg', 'neutral'];

    private const SERIES_PALETTE = ['var(--c-1)', 'var(--c-2)', 'var(--c-3)', 'var(--c-warn)', 'var(--c-neg)'];

    private static int $confirmSeq = 1;

    private static int $dashSeq = 1;

    /**
     * @param array<string, mixed> $input  input da tool (sem 'type')
     * @return array<string, mixed>|null
     */
    public static function build(string $type, array $input): ?array
    {
        switch ($type) {
            case 'kpis':
                $items = [];
                foreach (self::arr($input['items'] ?? null) as $it) {
                    if (! self::isObj($it)) {
                        continue;
                    }
                    $label = self::str($it['label'] ?? null);
                    $value = self::str($it['value'] ?? null);
                    if ($label === null || $value === null) {
                        continue;
                    }
                    $kpi = ['label' => $label, 'value' => $value];
                    self::opt($kpi, 'sub', self::str($it['sub'] ?? null));
                    self::opt($kpi, 'delta', self::str($it['delta'] ?? null));
                    $dir = ($it['dir'] ?? null);
                    if ($dir === 'up' || $dir === 'down') {
                        $kpi['dir'] = $dir;
                    }
                    self::opt($kpi, 'tone', self::tone($it['tone'] ?? null));
                    $items[] = $kpi;
                }
                return $items ? ['type' => 'kpis', 'items' => $items] : null;

            case 'bar':
                $data = [];
                foreach (self::arr($input['data'] ?? null) as $d) {
                    if (! self::isObj($d)) {
                        continue;
                    }
                    $label = self::str($d['label'] ?? null);
                    $value = self::num($d['value'] ?? null);
                    if ($label === null || $value === null) {
                        continue;
                    }
                    $datum = ['label' => $label, 'value' => $value];
                    self::opt($datum, 'color', self::str($d['color'] ?? null));
                    $data[] = $datum;
                }
                if (! $data) {
                    return null;
                }
                $block = ['type' => 'bar', 'horizontal' => (($input['horizontal'] ?? null) === true), 'data' => $data];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'line':
            case 'area':
                $xLabels = [];
                foreach (self::arr($input['xLabels'] ?? null) as $x) {
                    $s = self::str($x);
                    if ($s !== null) {
                        $xLabels[] = $s;
                    }
                }
                $series = [];
                $i = 0;
                foreach (self::arr($input['series'] ?? null) as $s) {
                    if (! self::isObj($s)) {
                        $i++;
                        continue;
                    }
                    $name = self::str($s['name'] ?? null);
                    $points = [];
                    foreach (self::arr($s['points'] ?? null) as $p) {
                        $n = self::num($p);
                        if ($n !== null) {
                            $points[] = $n;
                        }
                    }
                    if ($name === null || ! $points) {
                        $i++;
                        continue;
                    }
                    $serie = [
                        'name'   => $name,
                        'color'  => self::str($s['color'] ?? null) ?? self::SERIES_PALETTE[$i % count(self::SERIES_PALETTE)],
                        'points' => $points,
                    ];
                    if ($type === 'line') {
                        $serie['dashed'] = (($s['dashed'] ?? null) === true);
                        $serie['area']   = (($s['area'] ?? null) === true);
                    }
                    $series[] = $serie;
                    $i++;
                }
                if (! $xLabels || ! $series) {
                    return null;
                }
                $block = ['type' => $type, 'xLabels' => $xLabels, 'series' => $series];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'donut':
                $data = [];
                foreach (self::arr($input['data'] ?? null) as $d) {
                    if (! self::isObj($d)) {
                        continue;
                    }
                    $label = self::str($d['label'] ?? null);
                    $value = self::num($d['value'] ?? null);
                    if ($label === null || $value === null) {
                        continue;
                    }
                    $datum = ['label' => $label, 'value' => $value];
                    self::opt($datum, 'color', self::str($d['color'] ?? null));
                    $data[] = $datum;
                }
                if (! $data) {
                    return null;
                }
                $block = ['type' => 'donut', 'data' => $data];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'centerValue', self::str($input['centerValue'] ?? null));
                self::opt($block, 'centerLabel', self::str($input['centerLabel'] ?? null));
                return $block;

            case 'table':
                $columns = [];
                foreach (self::arr($input['columns'] ?? null) as $c) {
                    if (! self::isObj($c)) {
                        continue;
                    }
                    $key = self::str($c['key'] ?? null);
                    $label = self::str($c['label'] ?? null);
                    if ($key === null || $label === null) {
                        continue;
                    }
                    $col = ['key' => $key, 'label' => $label];
                    $align = ($c['align'] ?? null);
                    if ($align === 'right' || $align === 'left') {
                        $col['align'] = $align;
                    }
                    if (($c['mono'] ?? null) === true) {
                        $col['mono'] = true;
                    }
                    if (($c['strong'] ?? null) === true) {
                        $col['strong'] = true;
                    }
                    if (($c['type'] ?? null) === 'badge') {
                        $col['type'] = 'badge';
                    }
                    $columns[] = $col;
                }
                if (! $columns) {
                    return null;
                }
                $rows = [];
                foreach (self::arr($input['rows'] ?? null) as $r) {
                    if (self::isObj($r)) {
                        $rows[] = $r;
                    }
                }
                $block = ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'note', self::str($input['note'] ?? null));

                // Agrupamento com quebras/somatórios: groupBy/totals só passam
                // se referenciarem colunas reais (o renderer soma e formata).
                $keys = array_column($columns, 'key');
                $groupBy = self::str($input['groupBy'] ?? null);
                if ($groupBy !== null && in_array($groupBy, $keys, true)) {
                    $block['groupBy'] = $groupBy;
                }
                $totals = [];
                foreach (self::arr($input['totals'] ?? null) as $tk) {
                    $tk = self::str($tk);
                    if ($tk !== null && in_array($tk, $keys, true)) {
                        $totals[] = $tk;
                    }
                }
                if ($totals !== []) {
                    $block['totals'] = $totals;
                    // Coerção: modelo às vezes manda "89002.25" como string —
                    // sem number o renderer não soma nem formata.
                    foreach ($block['rows'] as $ri => $row) {
                        foreach ($totals as $tk) {
                            $v = $row[$tk] ?? null;
                            if (is_string($v) && is_numeric($v)) {
                                $block['rows'][$ri][$tk] = $v + 0;
                            }
                        }
                    }
                }
                if (($input['grandTotal'] ?? null) === false) {
                    $block['grandTotal'] = false;
                }
                return $block;

            case 'list':
                $items = [];
                foreach (self::arr($input['items'] ?? null) as $it) {
                    if (! self::isObj($it)) {
                        continue;
                    }
                    $title = self::str($it['title'] ?? null);
                    if ($title === null) {
                        continue;
                    }
                    $item = ['title' => $title];
                    self::opt($item, 'sub', self::str($it['sub'] ?? null));
                    self::opt($item, 'right', self::str($it['right'] ?? null));
                    $badge = self::badge($it['badge'] ?? null);
                    if ($badge !== null) {
                        $item['badge'] = $badge;
                    }
                    $items[] = $item;
                }
                if (! $items) {
                    return null;
                }
                $block = ['type' => 'list', 'items' => $items];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                return $block;

            case 'badges':
                $items = [];
                foreach (self::arr($input['items'] ?? null) as $b) {
                    $badge = self::badge($b);
                    if ($badge !== null) {
                        $items[] = $badge;
                    }
                }
                return $items ? ['type' => 'badges', 'items' => $items] : null;

            case 'confirm':
                $tool  = self::str($input['tool'] ?? null);
                $title = self::str($input['title'] ?? null);
                if ($tool === null || $title === null) {
                    return null;
                }
                $fields = [];
                foreach (self::arr($input['fields'] ?? null) as $f) {
                    if (! self::isObj($f)) {
                        continue;
                    }
                    $k = self::str($f['k'] ?? null);
                    $v = self::str($f['v'] ?? null);
                    if ($k === null || $v === null) {
                        continue;
                    }
                    $fields[] = ['k' => $k, 'v' => $v];
                }
                $block = [
                    'type'   => 'confirm',
                    'id'     => self::str($input['id'] ?? null) ?? ('confirm-' . self::$confirmSeq++),
                    'tool'   => $tool,
                    'title'  => $title,
                    'danger' => (($input['danger'] ?? null) === true),
                    'fields' => $fields,
                ];
                self::opt($block, 'desc', self::str($input['desc'] ?? null));
                self::opt($block, 'confirmLabel', self::str($input['confirmLabel'] ?? null));
                return $block;

            case 'gauge':
                $value = self::num($input['value'] ?? null);
                $max   = self::num($input['max'] ?? null);
                if ($value === null || $max === null) {
                    return null;
                }
                $zones = [];
                foreach (self::arr($input['zones'] ?? null) as $z) {
                    if (! self::isObj($z)) {
                        continue;
                    }
                    $upTo = self::num($z['upTo'] ?? null);
                    $t = self::tone($z['tone'] ?? null);
                    if ($upTo === null || $t === null) {
                        continue;
                    }
                    $zones[] = ['upTo' => $upTo, 'tone' => $t];
                }
                $block = ['type' => 'gauge', 'value' => $value, 'max' => $max];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'min', self::num($input['min'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                self::opt($block, 'label', self::str($input['label'] ?? null));
                if ($zones) {
                    $block['zones'] = $zones;
                }
                return $block;

            case 'funnel':
                $steps = [];
                foreach (self::arr($input['steps'] ?? null) as $s) {
                    if (! self::isObj($s)) {
                        continue;
                    }
                    $label = self::str($s['label'] ?? null);
                    $value = self::num($s['value'] ?? null);
                    if ($label === null || $value === null) {
                        continue;
                    }
                    $step = ['label' => $label, 'value' => $value];
                    self::opt($step, 'color', self::str($s['color'] ?? null));
                    $steps[] = $step;
                }
                if (! $steps) {
                    return null;
                }
                $block = ['type' => 'funnel', 'steps' => $steps];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'progress':
                $items = [];
                foreach (self::arr($input['items'] ?? null) as $it) {
                    if (! self::isObj($it)) {
                        continue;
                    }
                    $label = self::str($it['label'] ?? null);
                    $value = self::num($it['value'] ?? null);
                    if ($label === null || $value === null) {
                        continue;
                    }
                    $item = ['label' => $label, 'value' => $value];
                    self::opt($item, 'max', self::num($it['max'] ?? null));
                    self::opt($item, 'sub', self::str($it['sub'] ?? null));
                    self::opt($item, 'tone', self::tone($it['tone'] ?? null));
                    $items[] = $item;
                }
                if (! $items) {
                    return null;
                }
                $block = ['type' => 'progress', 'items' => $items];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                return $block;

            case 'timeline':
                $items = [];
                foreach (self::arr($input['items'] ?? null) as $it) {
                    if (! self::isObj($it)) {
                        continue;
                    }
                    $title = self::str($it['title'] ?? null);
                    if ($title === null) {
                        continue;
                    }
                    $item = ['title' => $title];
                    self::opt($item, 'time', self::str($it['time'] ?? null));
                    self::opt($item, 'sub', self::str($it['sub'] ?? null));
                    self::opt($item, 'tone', self::tone($it['tone'] ?? null));
                    $items[] = $item;
                }
                if (! $items) {
                    return null;
                }
                $block = ['type' => 'timeline', 'items' => $items];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                return $block;

            case 'callout':
                $text = self::str($input['text'] ?? null);
                if ($text === null) {
                    return null;
                }
                $block = ['type' => 'callout', 'text' => $text];
                $intent = ($input['intent'] ?? null);
                if (in_array($intent, ['info', 'pos', 'warn', 'neg'], true)) {
                    $block['intent'] = $intent;
                }
                self::opt($block, 'title', self::str($input['title'] ?? null));
                return $block;

            case 'detail':
                $items = [];
                foreach (self::arr($input['items'] ?? null) as $it) {
                    if (! self::isObj($it)) {
                        continue;
                    }
                    $label = self::str($it['label'] ?? null);
                    $value = self::str($it['value'] ?? null);
                    if ($label === null || $value === null) {
                        continue;
                    }
                    $item = ['label' => $label, 'value' => $value];
                    self::opt($item, 'tone', self::tone($it['tone'] ?? null));
                    $items[] = $item;
                }
                if (! $items) {
                    return null;
                }
                $block = ['type' => 'detail', 'items' => $items];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                return $block;

            case 'bars':
                $categories = [];
                foreach (self::arr($input['categories'] ?? null) as $c) {
                    $s = self::str($c);
                    if ($s !== null) {
                        $categories[] = $s;
                    }
                }
                $series = self::multiSeries($input['series'] ?? null, 'values');
                if (! $categories || ! $series) {
                    return null;
                }
                $block = ['type' => 'bars', 'categories' => $categories, 'series' => $series];
                if (($input['stacked'] ?? null) === true) {
                    $block['stacked'] = true;
                }
                if (($input['horizontal'] ?? null) === true) {
                    $block['horizontal'] = true;
                }
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'scatter':
                $points = [];
                foreach (self::arr($input['points'] ?? null) as $p) {
                    if (! self::isObj($p)) {
                        continue;
                    }
                    $x = self::num($p['x'] ?? null);
                    $y = self::num($p['y'] ?? null);
                    if ($x === null || $y === null) {
                        continue;
                    }
                    $pt = ['x' => $x, 'y' => $y];
                    self::opt($pt, 'label', self::str($p['label'] ?? null));
                    $size = self::num($p['size'] ?? null);
                    if ($size !== null) {
                        $pt['size'] = $size;
                    }
                    $points[] = $pt;
                }
                if (! $points) {
                    return null;
                }
                $block = ['type' => 'scatter', 'points' => $points];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'xLabel', self::str($input['xLabel'] ?? null));
                self::opt($block, 'yLabel', self::str($input['yLabel'] ?? null));
                return $block;

            case 'heatmap':
                $xLabels = [];
                foreach (self::arr($input['xLabels'] ?? null) as $x) {
                    $s = self::str($x);
                    if ($s !== null) {
                        $xLabels[] = $s;
                    }
                }
                $yLabels = [];
                foreach (self::arr($input['yLabels'] ?? null) as $y) {
                    $s = self::str($y);
                    if ($s !== null) {
                        $yLabels[] = $s;
                    }
                }
                $values = [];
                foreach (self::arr($input['values'] ?? null) as $row) {
                    $vals = [];
                    foreach (self::arr($row) as $v) {
                        $vals[] = self::num($v) ?? 0;
                    }
                    $values[] = $vals;
                }
                // Matriz consistente: uma linha por yLabel, uma coluna por xLabel.
                if (! $xLabels || ! $yLabels || count($values) !== count($yLabels)) {
                    return null;
                }
                foreach ($values as $row) {
                    if (count($row) !== count($xLabels)) {
                        return null;
                    }
                }
                $block = ['type' => 'heatmap', 'xLabels' => $xLabels, 'yLabels' => $yLabels, 'values' => $values];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'combo':
                $categories = [];
                foreach (self::arr($input['categories'] ?? null) as $c) {
                    $s = self::str($c);
                    if ($s !== null) {
                        $categories[] = $s;
                    }
                }
                $bars  = self::multiSeries($input['bars'] ?? null, 'values');
                $lines = self::multiSeries($input['lines'] ?? null, 'points', count($bars));
                foreach ($lines as $k => $l) {
                    $lines[$k]['dashed'] = (($input['lines'][$k]['dashed'] ?? null) === true);
                }
                if (! $categories || ! $bars || ! $lines) {
                    return null;
                }
                $block = ['type' => 'combo', 'categories' => $categories, 'bars' => $bars, 'lines' => $lines];
                if (($input['dualAxis'] ?? null) === true) {
                    $block['dualAxis'] = true;
                }
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'map_br':
                $data = [];
                foreach (self::arr($input['data'] ?? null) as $d) {
                    if (! self::isObj($d)) {
                        continue;
                    }
                    $uf = strtoupper(trim((string) self::str($d['uf'] ?? null)));
                    $v  = self::num($d['value'] ?? null);
                    if (strlen($uf) !== 2 || $v === null) {
                        continue;
                    }
                    $data[] = ['uf' => $uf, 'value' => $v];
                }
                if (! $data) {
                    return null;
                }
                $block = ['type' => 'map_br', 'data' => $data];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'unit', self::str($input['unit'] ?? null));
                return $block;

            case 'dashboard':
                // Tolerancia a modelos fracos: widgets pode vir como STRING
                // JSON do array inteiro; cada widget pode ser {span, block}
                // OU o proprio bloco direto (sem wrapper).
                $widgetsRaw = $input['widgets'] ?? null;
                if (is_string($widgetsRaw)) {
                    $decoded    = json_decode($widgetsRaw, true);
                    $widgetsRaw = is_array($decoded) ? $decoded : null;
                }
                $widgets = [];
                foreach (self::arr($widgetsRaw) as $w) {
                    if (is_string($w)) {
                        $decoded = json_decode($w, true);
                        $w       = is_array($decoded) ? $decoded : null;
                    }
                    if (! self::isObj($w)) {
                        continue;
                    }
                    // block: objeto OU string JSON (o schema do laravel/ai nao
                    // suporta objeto livre aninhado — precedente: confirm.args).
                    // Sem chave 'block' mas com 'type'? O widget E o bloco.
                    $raw = $w['block'] ?? (isset($w['type']) ? $w : null);
                    if (is_string($raw)) {
                        $decoded = json_decode($raw, true);
                        $raw     = is_array($decoded) ? $decoded : null;
                    }
                    if (! is_array($raw)) {
                        continue;
                    }
                    $wType = $raw['type'] ?? null;
                    // confirm (interativo) e dashboard (recursao) proibidos como widget
                    if (! is_string($wType) || $wType === 'confirm' || $wType === 'dashboard') {
                        continue;
                    }
                    $block = self::build($wType, $raw);
                    if ($block === null) {
                        continue;
                    }
                    // Preserva proveniencia do widget (espelho do withMeta do
                    // front): id permite o front ABSORVER o bloco solto pra
                    // dentro da moldura; source alimenta o spec por widget.
                    if (is_string($raw['id'] ?? null) && $raw['id'] !== '') {
                        $block['id'] = $raw['id'];
                    }
                    if (is_array($raw['source'] ?? null) && is_string($raw['source']['tool'] ?? null)) {
                        $block['source'] = [
                            'tool' => $raw['source']['tool'],
                            'args' => is_array($raw['source']['args'] ?? null) ? $raw['source']['args'] : [],
                        ];
                    }
                    $widget = ['block' => $block];
                    if (($w['span'] ?? null) === 2 || ($w['span'] ?? null) === 2.0 || ($w['span'] ?? null) === '2') {
                        $widget['span'] = 2;
                    }
                    $widgets[] = $widget;
                }
                if (! $widgets) {
                    return null;
                }

                $filters = [];
                foreach (self::arr($input['filters'] ?? null) as $f) {
                    if (! self::isObj($f)) {
                        continue;
                    }
                    $key   = self::str($f['key'] ?? null);
                    $label = self::str($f['label'] ?? null);
                    if ($key === null || $label === null) {
                        continue;
                    }
                    $kind   = (($f['kind'] ?? null) === 'select') ? 'select' : 'search';
                    $filter = ['key' => $key, 'label' => $label, 'kind' => $kind];
                    if ($kind === 'select') {
                        $options = [];
                        foreach (self::arr($f['options'] ?? null) as $o) {
                            $s = self::str($o);
                            if ($s !== null) {
                                $options[] = $s;
                            }
                        }
                        if ($options) {
                            $filter['options'] = $options;
                        }
                    }
                    self::opt($filter, 'value', self::str($f['value'] ?? null));
                    $filters[] = $filter;
                }

                $block = [
                    'type'    => 'dashboard',
                    'id'      => self::str($input['id'] ?? null) ?? ('dash-' . self::$dashSeq++),
                    'widgets' => $widgets,
                ];
                self::opt($block, 'title', self::str($input['title'] ?? null));
                self::opt($block, 'desc', self::str($input['desc'] ?? null));
                if ($filters) {
                    $block['filters'] = $filters;
                }
                return $block;

            default:
                return null;
        }
    }

    private static function isObj(mixed $x): bool
    {
        return is_array($x) && ! array_is_list($x);
    }

    private static function str(mixed $x): ?string
    {
        return is_string($x) ? $x : null;
    }

    /**
     * Série multi-valor genérica (bars/combo): [{name, color?, <chave>: number[]}].
     * `$paletteOffset` desloca a cor default (linhas do combo continuam a
     * paleta depois das barras).
     *
     * @return list<array{name: string, color: string, values?: list<int|float>, points?: list<int|float>}>
     */
    private static function multiSeries(mixed $raw, string $chave, int $paletteOffset = 0): array
    {
        $out = [];
        $i = 0;
        foreach (self::arr($raw) as $s) {
            if (! self::isObj($s)) {
                $i++;
                continue;
            }
            $name = self::str($s['name'] ?? null);
            $vals = [];
            foreach (self::arr($s[$chave] ?? ($s['values'] ?? null)) as $v) {
                $n = self::num($v);
                if ($n !== null) {
                    $vals[] = $n;
                }
            }
            if ($name === null || ! $vals) {
                $i++;
                continue;
            }
            $out[] = [
                'name'  => $name,
                'color' => self::str($s['color'] ?? null) ?? self::SERIES_PALETTE[($paletteOffset + $i) % count(self::SERIES_PALETTE)],
                $chave  => $vals,
            ];
            $i++;
        }

        return $out;
    }

    private static function num(mixed $x): int|float|null
    {
        if ((is_int($x) || is_float($x)) && is_finite((float) $x)) {
            return $x;
        }
        if (is_string($x) && trim($x) !== '' && is_numeric($x)) {
            return $x + 0;
        }
        return null;
    }

    private static function tone(mixed $x): ?string
    {
        return is_string($x) && in_array($x, self::TONES, true) ? $x : null;
    }

    /** @return list<mixed> */
    private static function arr(mixed $x): array
    {
        return is_array($x) && array_is_list($x) ? $x : [];
    }

    /** @return array{label: string, tone?: string}|null */
    private static function badge(mixed $x): ?array
    {
        if (! self::isObj($x)) {
            return null;
        }
        $label = self::str($x['label'] ?? null);
        if ($label === null) {
            return null;
        }
        $badge = ['label' => $label];
        $tone = self::tone($x['tone'] ?? null);
        if ($tone !== null) {
            $badge['tone'] = $tone;
        }
        return $badge;
    }

    /** Adiciona a chave apenas se o valor nao for null (mantem 0/false/''). */
    private static function opt(array &$target, string $key, mixed $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }
}
