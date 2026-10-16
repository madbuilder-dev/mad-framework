<?php

declare(strict_types=1);

namespace Mad\Chart;

use Mad\Database\QuerySource;
use Mad\Form\ModelOptionsLoader;

/**
 * MadHeatmap — monta a matriz do <mad-heatmap> (N×M, cor por faixa, contagem).
 *
 * Só dados: o Blade (`components.heatmap`) desenha o que sai de `build()`.
 *
 *   MadHeatmap::build([
 *       'model'   => 'Risco', 'xField' => 'impacto', 'yField' => 'probabilidade',
 *       'xLabels' => [1 => 'Muito baixo', …, 5 => 'Muito alto'],
 *       'yLabels' => [1 => 'Rara', …, 5 => 'Quase certa'],
 *       'preset'  => 'risk',                 // faixas P×I + eixo Y de cima para baixo
 *   ]);
 *
 * Fontes (uma delas):
 *   - `model` (+ `filters`, `database`): Eloquent com os escopos globais do model
 *     (unidade, empresa, exclusão lógica, escopo próprio do app);
 *   - `query`: Eloquent/Query Builder pronto (o app aplica o escopo que quiser);
 *   - `data`: array do PHP — linhas [{x, y, value?, items?}] (sem `value`, cada
 *     linha conta 1; com `x-field`/`y-field`, lê essas chaves) ou matriz
 *     [y => [x => valor]].
 *
 * Valor da célula: `total` = count (padrão) | sum | avg | min | max sobre `field`.
 *
 * Faixas (`bands`): lista ordenada de ['max' => n, 'label' => 'Baixo',
 * 'tone' => 'success'] (a última pode não ter `max`). `bandBy` = 'value'
 * (o valor da célula; célula vazia fica neutra) ou 'product' (x × y — chave
 * numérica do eixo, ou a posição 1..n quando a chave não é número; toda célula
 * ganha cor, como numa matriz de risco). Sem faixas: escala de intensidade
 * (5 tons da cor primária) pelo valor.
 */
final class MadHeatmap
{
    public const TONES = ['success', 'info', 'warning', 'orange', 'danger', 'critical', 'neutral', 'primary'];

    private const TOTALS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * @param array<string,mixed> $c
     * @return array{x: list<array{key:string,label:string,num:float}>, y: list<array{key:string,label:string,num:float}>,
     *               cells: array<string,array<string,array<string,mixed>>>, bands: list<array<string,mixed>>,
     *               bandBy: string, max: float, total: float, error: ?string, sql: ?string, binds: array}
     */
    public static function build(array $c): array
    {
        $preset = strtolower(trim((string) ($c['preset'] ?? '')));
        $bandBy = strtolower(trim((string) ($c['bandBy'] ?? '')));
        $bands = self::bands($c['bands'] ?? null, $preset);
        if ($bandBy === '') {
            $bandBy = $preset === 'risk' ? 'product' : 'value';
        }
        if (!in_array($bandBy, ['value', 'product'], true)) {
            $bandBy = 'value';
        }
        $yOrder = strtolower(trim((string) ($c['yOrder'] ?? ($preset === 'risk' ? 'desc' : 'asc'))));
        $xOrder = strtolower(trim((string) ($c['xOrder'] ?? 'asc')));

        $total = strtolower(trim((string) ($c['total'] ?? 'count')));
        if (!in_array($total, self::TOTALS, true)) {
            $total = 'count';
        }

        $error = null;
        $sql = null;
        $binds = [];
        $raw = [];   // "x\x1fy" => ['x'=>, 'y'=>, 'value'=>, 'items'=>[]]
        try {
            if (isset($c['data']) && is_array($c['data'])) {
                $raw = self::fromArray($c['data'], $c);
            } else {
                [$raw, $sql, $binds] = self::fromQuery($c, $total);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $raw = [];
        }

        $xAxis = self::axis($c['xLabels'] ?? null, array_column($raw, 'x'), $xOrder);
        $yAxis = self::axis($c['yLabels'] ?? null, array_column($raw, 'y'), $yOrder);

        $format = (string) ($c['format'] ?? '');
        $fmt = $format !== '' ? \Closure::fromCallable(MadChartFormatter::make($format)) : null;

        $max = 0.0;
        $sum = 0.0;
        foreach ($raw as $r) {
            $max = max($max, (float) $r['value']);
        }

        $cells = [];
        foreach ($yAxis as $y) {
            foreach ($xAxis as $x) {
                $r = $raw[$x['key'] . "\x1f" . $y['key']] ?? null;
                $value = $r !== null ? (float) $r['value'] : 0.0;
                $sum += $value;
                $score = $bandBy === 'product' ? $x['num'] * $y['num'] : $value;
                $band = null;
                if ($bands !== [] && ($bandBy === 'product' || $value > 0)) {
                    $band = self::bandIndex($bands, $score);
                }
                $items = $r['items'] ?? [];
                $cells[$y['key']][$x['key']] = [
                    'value'   => $value,
                    'display' => $value > 0 ? self::display($value, $fmt) : '',
                    'score'   => $score,
                    'band'    => $band,
                    'heat'    => ($bands === [] && $value > 0 && $max > 0) ? max(1, (int) ceil($value / $max * 5)) : 0,
                    'size'    => $max > 0 ? round($value / $max, 3) : 0.0,
                    'items'   => $items,
                    'more'    => ($total === 'count' && $items !== []) ? max(0, (int) $value - count($items)) : 0,
                ];
            }
        }

        foreach ($bands as $i => &$b) {
            $b['range'] = self::rangeText($bands, $i);
        }
        unset($b);

        return [
            'x' => $xAxis, 'y' => $yAxis, 'cells' => $cells, 'bands' => $bands, 'bandBy' => $bandBy,
            'max' => $max, 'total' => $sum, 'error' => $error, 'sql' => $sql, 'binds' => $binds,
        ];
    }

    /**
     * Faixas normalizadas. `preset="risk"` sem `bands` = as quatro da matriz P×I
     * 5×5 (até 4 Baixo · até 9 Médio · até 16 Alto · acima Crítico).
     *
     * @return list<array{label:string,tone:string,color:string,min:?float,max:?float}>
     */
    public static function bands(mixed $bands, string $preset = ''): array
    {
        if (is_string($bands) && trim($bands) !== '') {
            $decoded = json_decode($bands, true);
            $bands = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($bands) || $bands === []) {
            if ($preset !== 'risk') {
                return [];
            }
            $bands = [
                ['max' => 4,  'label' => mad_t('mad.heatmap.band_low'),      'tone' => 'success'],
                ['max' => 9,  'label' => mad_t('mad.heatmap.band_medium'),   'tone' => 'warning'],
                ['max' => 16, 'label' => mad_t('mad.heatmap.band_high'),     'tone' => 'orange'],
                ['label' => mad_t('mad.heatmap.band_critical'),              'tone' => 'danger'],
            ];
        }

        $out = [];
        foreach (array_values($bands) as $i => $b) {
            if (!is_array($b)) {
                continue;
            }
            $tone = strtolower(trim((string) ($b['tone'] ?? '')));
            $color = trim((string) ($b['color'] ?? ''));
            if ($color !== '' && !preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl|oklch)a?\([^;{}]*\)|var\(--[a-z0-9-]+\))$/i', $color)) {
                $color = '';   // só cor literal ou token: o valor vai para um style
            }
            if (!in_array($tone, self::TONES, true)) {
                $tone = $color !== '' ? 'custom' : self::TONES[min($i, 5)];
            }
            $out[] = [
                'label' => (string) ($b['label'] ?? ''),
                'tone'  => $tone,
                'color' => $color,
                'min'   => isset($b['min']) && is_numeric($b['min']) ? (float) $b['min'] : null,
                'max'   => isset($b['max']) && is_numeric($b['max']) ? (float) $b['max'] : (isset($b['to']) && is_numeric($b['to']) ? (float) $b['to'] : null),
            ];
        }

        return $out;
    }

    /** Primeira faixa cujo intervalo contém o score; a última sem `max` pega o resto. */
    public static function bandIndex(array $bands, float $score): ?int
    {
        foreach ($bands as $i => $b) {
            if ($b['min'] !== null && $score < $b['min']) {
                continue;
            }
            if ($b['max'] === null || $score <= $b['max']) {
                return $i;
            }
        }

        return null;
    }

    // ── fontes ───────────────────────────────────────────────────────────

    /** @return array{0: array, 1: ?string, 2: array} */
    private static function fromQuery(array $c, string $total): array
    {
        $xField = self::column((string) ($c['xField'] ?? ''), 'x-field');
        $yField = self::column((string) ($c['yField'] ?? ''), 'y-field');
        $field = trim((string) ($c['field'] ?? ''));
        $field = $field === '' ? ($total === 'count' ? '*' : '') : self::column($field, 'field');
        if ($field === '') {
            throw new \InvalidArgumentException('<mad-heatmap>: total="' . $total . '" precisa de field="coluna".');
        }

        $source = $c['query'] ?? null;
        if (QuerySource::isQuery($source)) {
            $base = QuerySource::toBaseQuery($source);
        } else {
            $model = trim((string) ($c['model'] ?? ''));
            if ($model === '') {
                throw new \InvalidArgumentException('<mad-heatmap>: informe model, :query ou :data.');
            }
            $cls = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
            $db = trim((string) ($c['database'] ?? ''));
            $base = QuerySource::modelBaseQuery($cls, $db !== '' ? $db : null);
        }
        if (!empty($c['filters']) && is_array($c['filters'])) {
            QuerySource::applyArrayFilters($base, $c['filters']);
        }

        $grammar = $base->getGrammar();
        $wx = $grammar->wrap($xField);
        $wy = $grammar->wrap($yField);
        $agg = $field === '*' ? 'count(*)' : $total . '(' . $grammar->wrap($field) . ')';

        $q = clone $base;
        $q->columns = null;
        $q->orders = null;
        $q->limit = null;
        $q->offset = null;
        $q->selectRaw("{$wx} as hm_x, {$wy} as hm_y, {$agg} as hm_v")
            ->groupByRaw("{$wx}, {$wy}");

        $sql = $q->toSql();
        $binds = $q->getBindings();

        $raw = [];
        foreach ($q->get() as $row) {
            if ($row->hm_x === null || $row->hm_y === null) {
                continue;
            }
            $k = self::key($row->hm_x) . "\x1f" . self::key($row->hm_y);
            $raw[$k] = ['x' => self::key($row->hm_x), 'y' => self::key($row->hm_y), 'value' => (float) $row->hm_v, 'items' => []];
        }

        // Lista dos registros por célula (opcional): uma consulta só, com teto.
        $listField = trim((string) ($c['listField'] ?? ''));
        if ($listField !== '' && $raw !== []) {
            $per = max(1, min(20, (int) ($c['listLimit'] ?? 5)));
            $cap = max($per, min(5000, (int) ($c['listMax'] ?? 1000)));
            $label = self::column($listField, 'list-field');
            $l = clone $base;
            $l->columns = null;
            $l->limit = null;
            $l->offset = null;
            $l->selectRaw("{$wx} as hm_x, {$wy} as hm_y, " . $grammar->wrap($label) . ' as hm_l');
            if (empty($l->orders)) {
                $l->orderByRaw($grammar->wrap($label));
            }
            foreach ($l->limit($cap)->get() as $row) {
                $k = self::key($row->hm_x) . "\x1f" . self::key($row->hm_y);
                if (isset($raw[$k]) && count($raw[$k]['items']) < $per && $row->hm_l !== null && $row->hm_l !== '') {
                    $raw[$k]['items'][] = (string) $row->hm_l;
                }
            }
        }

        return [$raw, $sql, $binds];
    }

    private static function fromArray(array $data, array $c): array
    {
        $raw = [];
        $xKey = trim((string) ($c['xField'] ?? ''));
        $yKey = trim((string) ($c['yField'] ?? ''));
        $vKey = trim((string) ($c['field'] ?? ''));
        $per = max(1, min(20, (int) ($c['listLimit'] ?? 5)));

        $isRows = array_is_list($data) && ($data === [] || is_array($data[0]) || is_object($data[0]));
        if ($isRows) {
            foreach ($data as $row) {
                $row = is_object($row) ? (method_exists($row, 'toArray') ? $row->toArray() : (array) $row) : (array) $row;
                $x = $row['x'] ?? ($xKey !== '' ? ($row[$xKey] ?? null) : null);
                $y = $row['y'] ?? ($yKey !== '' ? ($row[$yKey] ?? null) : null);
                if ($x === null || $y === null || (!is_scalar($x)) || (!is_scalar($y))) {
                    continue;
                }
                $v = $row['value'] ?? $row['count'] ?? ($vKey !== '' ? ($row[$vKey] ?? null) : null) ?? 1;
                $k = self::key($x) . "\x1f" . self::key($y);
                $raw[$k] ??= ['x' => self::key($x), 'y' => self::key($y), 'value' => 0.0, 'items' => []];
                $raw[$k]['value'] += is_numeric($v) ? (float) $v : 0.0;
                foreach ((array) ($row['items'] ?? []) as $item) {
                    if (count($raw[$k]['items']) < $per && is_scalar($item)) {
                        $raw[$k]['items'][] = (string) $item;
                    }
                }
                $listField = trim((string) ($c['listField'] ?? ''));
                if ($listField !== '' && isset($row[$listField]) && is_scalar($row[$listField]) && count($raw[$k]['items']) < $per) {
                    $raw[$k]['items'][] = (string) $row[$listField];
                }
            }

            return $raw;
        }

        // Matriz [y => [x => valor]]
        foreach ($data as $y => $cols) {
            foreach ((array) $cols as $x => $v) {
                if (!is_numeric($v)) {
                    continue;
                }
                $k = self::key($x) . "\x1f" . self::key($y);
                $raw[$k] = ['x' => self::key($x), 'y' => self::key($y), 'value' => (float) $v, 'items' => []];
            }
        }

        return $raw;
    }

    // ── eixos ────────────────────────────────────────────────────────────

    /**
     * Eixo: rótulos declarados (assoc valor => rótulo, lista de rótulos, ou lista
     * de ['value' => …, 'label' => …]) ou, sem rótulos, os valores que vieram nos
     * dados em ordem natural. `num` = chave numérica, ou a posição 1..n.
     *
     * @return list<array{key:string,label:string,num:float}>
     */
    public static function axis(mixed $labels, array $seen, string $order = 'asc'): array
    {
        if (is_string($labels) && trim($labels) !== '') {
            $decoded = json_decode($labels, true);
            $labels = is_array($decoded) ? $decoded : array_map('trim', explode(',', $labels));
        }

        $pairs = [];
        if (is_array($labels) && $labels !== []) {
            if (array_is_list($labels)) {
                foreach ($labels as $l) {
                    if (is_array($l)) {
                        $v = $l['value'] ?? $l['key'] ?? $l['id'] ?? null;
                        if ($v !== null) {
                            $pairs[] = [self::key($v), (string) ($l['label'] ?? $v)];
                        }
                    } elseif (is_scalar($l)) {
                        $pairs[] = [self::key($l), (string) $l];
                    }
                }
            } else {
                foreach ($labels as $k => $l) {
                    $pairs[] = [self::key($k), (string) $l];
                }
            }
        } else {
            $vals = array_values(array_unique(array_map('strval', $seen)));
            usort($vals, static fn ($a, $b) => strnatcasecmp($a, $b));
            foreach ($vals as $v) {
                $pairs[] = [$v, $v];
            }
        }

        $out = [];
        $allNumeric = $pairs !== [] && !in_array(false, array_map(static fn ($p) => is_numeric($p[0]), $pairs), true);
        foreach ($pairs as $i => [$k, $l]) {
            $out[] = ['key' => $k, 'label' => $l, 'num' => $allNumeric ? (float) $k : (float) ($i + 1)];
        }

        return $order === 'desc' ? array_reverse($out) : $out;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private static int $seq = 0;

    /** Id do elemento: estável pela configuração + sequência (vários na mesma página). */
    public static function domId(string $seed): string
    {
        return 'mad-hm-' . substr(md5($seed), 0, 8) . '-' . (++self::$seq);
    }

    private static function key(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v) && floor($v) === $v) {
            return (string) (int) $v;
        }
        if ($v instanceof \BackedEnum) {
            return (string) $v->value;
        }

        return trim((string) $v);
    }

    /** Coluna crua do SQL: só identificador (tabela.coluna), nunca expressão. */
    private static function column(string $col, string $attr): string
    {
        $col = trim($col);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $col)) {
            throw new \InvalidArgumentException("<mad-heatmap>: {$attr}=\"{$col}\" precisa ser o nome de uma coluna.");
        }

        return $col;
    }

    private static function display(float $v, ?\Closure $fmt): string
    {
        if ($fmt !== null) {
            return (string) $fmt($v);
        }

        return floor($v) === $v ? (string) (int) $v : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }

    private static function rangeText(array $bands, int $i): string
    {
        $b = $bands[$i];
        $num = static fn (float $n) => floor($n) === $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        if ($b['min'] !== null) {
            $loText = $num($b['min']);
        } elseif ($i > 0 && $bands[$i - 1]['max'] !== null) {
            $prev = $bands[$i - 1]['max'];
            $loText = floor($prev) === $prev ? $num($prev + 1) : '> ' . $num($prev);
        } else {
            $loText = null;
        }
        if ($b['max'] === null) {
            return $loText !== null ? (str_starts_with($loText, '>') ? $loText : '≥ ' . $loText) : '';
        }
        if ($loText === null) {
            return '≤ ' . $num($b['max']);
        }

        return str_starts_with($loText, '>') ? $loText . ' a ' . $num($b['max']) : $loText . '–' . $num($b['max']);
    }
}
