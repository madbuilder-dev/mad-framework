<?php

namespace Mad\Chart\Engine;

use InvalidArgumentException;

/**
 * Recursos do gráfico que não passam pelo agrupamento do banco:
 *
 *   - séries montadas no PHP (curva S, previsto × realizado, horas × contratado):
 *     cada série com tipo (barra, linha, pontos), eixo, empilhamento, área,
 *     tracejado, cor, acumulado e destaque do último ponto;
 *   - marcadores: linha vertical numa categoria ("Hoje", "Go-live") ou
 *     horizontal num valor ("Contratado"), em qualquer gráfico de eixos;
 *   - donut de progresso / donut com meta marcada no anel e texto no centro;
 *   - descrição para leitor de tela e cores pelos tokens do tema
 *     (`var(--mad-…)`, resolvidas no navegador, claro e escuro).
 *
 * Nada aqui roda quando o gráfico não pede: o gráfico de sempre sai igual.
 */
trait SeriesExtras
{
    /** @var array{labels: list<string>, series: list<array<string,mixed>>}|null */
    protected ?array $manualSeries = null;

    /** @var list<array<string,mixed>> */
    protected array $markers = [];

    /** @var array<string,mixed>|null */
    protected ?array $donutExtras = null;

    protected bool $themeTokens = false;

    /** O caller escolheu as cores (:colors)? Sem isso, as séries do PHP usam os tokens do tema. */
    protected bool $customColors = false;

    /**
     * Cores das séries quando o caller não escolhe: a paleta automática do tema
     * (--mad-chart-auto-N, a mesma das fatias; no ERP o navegador ainda ajusta
     * ao card) e, sem ela, os tokens do tema com reserva — o preset default sai
     * como sempre (fw#498).
     */
    public const SERIES_PALETTE = [
        'var(--mad-chart-auto-1, var(--mad-chart-1, #2563eb))',
        'var(--mad-chart-auto-2, var(--mad-chart-2, #f97316))',
        'var(--mad-chart-auto-3, var(--mad-chart-3, #16a34a))',
        'var(--mad-chart-auto-4, var(--mad-chart-4, #0891b2))',
        'var(--mad-chart-auto-5, var(--mad-chart-5, #d97706))',
        'var(--mad-chart-auto-6, var(--mad-chart-6, #dc2626))',
        'var(--mad-chart-auto-7, var(--mad-chart-7, #0d9488))',
        'var(--mad-chart-auto-8, var(--mad-chart-8, #64748b))',
    ];

    /**
     * Cor automática da série $i: o token --mad-chart-auto-N (até 12 no tema
     * da marca; da 9ª em diante o navegador varia a luminosidade da família,
     * fw#499) com a reserva de sempre — no preset default sai igual.
     */
    public static function paletteColor(int $i): string
    {
        return $i < 8 ? self::SERIES_PALETTE[$i] : preg_replace('/^var\(--mad-chart-auto-\d+,/', 'var(--mad-chart-auto-' . ($i % 12 + 1) . ',', self::SERIES_PALETTE[$i % 8]);
    }

    /** `chart-N` pedido pelo caller: o token do tema, fora da ordem automática. */
    private const CHART_TOKENS = [
        'var(--mad-chart-1, #2563eb)',
        'var(--mad-chart-2, #f97316)',
        'var(--mad-chart-3, #16a34a)',
        'var(--mad-chart-4, #0891b2)',
        'var(--mad-chart-5, #d97706)',
        'var(--mad-chart-6, #dc2626)',
        'var(--mad-chart-7, #0d9488)',
        'var(--mad-chart-8, #64748b)',
    ];

    /** Tons aceitos no lugar de uma cor. */
    public const SERIES_TONES = [
        'primary' => 'var(--mad-chart-1, #2563eb)',
        'info'    => 'var(--mad-chart-4, #0891b2)',
        'success' => 'var(--mad-chart-3, #16a34a)',
        'warning' => 'var(--mad-chart-5, #d97706)',
        'orange'  => 'var(--mad-chart-orange, #f97316)',
        'danger'  => 'var(--mad-chart-6, #dc2626)',
        'neutral' => 'var(--mad-chart-8, #64748b)',
        'muted'   => 'var(--mad-chart-track, #e5e7eb)',
        'text'    => 'var(--mad-text, #18181b)',
    ];

    private const MARKER_COLOR = 'var(--mad-chart-marker, #f97316)';
    private const SERIES_TYPES = ['bar', 'line', 'scatter'];
    private const SYMBOLS = ['circle', 'emptyCircle', 'rect', 'roundRect', 'triangle', 'diamond', 'pin', 'arrow', 'none'];

    // ── Entrada ────────────────────────────────────────────────────────────

    /**
     * Séries montadas no PHP. Os pontos de cada série seguem `$labels`
     * (lista alinhada, ou mapa rótulo => valor); `null` = sem ponto.
     *
     * @param list<string|int>  $labels
     * @param array<int|string,mixed> $series lista de ['name' => …, 'data' => […], …] ou ['Nome' => […]]
     */
    public function setManualSeries(array $labels, array $series): void
    {
        $norm = self::normalizeSeries($labels, $series);
        $this->manualSeries = $norm;
        $this->themeTokens = true;

        $rows = [];
        $integers = true;
        foreach ($norm['series'] as $s) {
            foreach ($s['data'] as $i => $v) {
                if ($v === null) continue;
                $rows[] = [$norm['labels'][$i], $s['name'], $v];
                $integers = $integers && floor($v) == $v;
            }
        }
        $this->fieldGroup = ['label', 'serie'];
        $this->setData($rows);
        $this->setIntegerSeries($rows !== [] && $integers);
    }

    /** @param list<array<string,mixed>> $markers */
    public function setMarkers(array $markers): void
    {
        $this->markers = self::normalizeMarkers($markers);
        if ($this->markers !== []) {
            $this->themeTokens = true;
        }
    }

    /**
     * Donut de progresso (`value` de `max`) e/ou meta no anel e texto no centro.
     *
     * @param array{value?: float|null, max?: float|null, label?: string|null, goal?: float|string|null,
     *              goalLabel?: string|null, center?: string|null, centerSub?: string|null} $extras
     */
    public function setDonutExtras(array $extras): void
    {
        $e = [
            'value' => isset($extras['value']) && is_numeric($extras['value']) ? (float) $extras['value'] : null,
            'max' => isset($extras['max']) && is_numeric($extras['max']) && (float) $extras['max'] > 0 ? (float) $extras['max'] : 100.0,
            'label' => trim((string) ($extras['label'] ?? '')),
            'goal' => null,
            'goalPct' => false,
            'goalLabel' => trim((string) ($extras['goalLabel'] ?? '')),
            'center' => isset($extras['center']) && $extras['center'] !== null ? trim((string) $extras['center']) : null,
            'centerSub' => isset($extras['centerSub']) && $extras['centerSub'] !== null ? trim((string) $extras['centerSub']) : null,
        ];
        $goal = $extras['goal'] ?? null;
        if (is_string($goal) && str_ends_with(trim($goal), '%') && is_numeric(rtrim(trim($goal), '%'))) {
            $e['goal'] = (float) rtrim(trim($goal), '%');
            $e['goalPct'] = true;
        } elseif (is_numeric($goal)) {
            $e['goal'] = (float) $goal;
        }
        if ($e['value'] === null && $e['goal'] === null && $e['center'] === null && $e['centerSub'] === null) {
            return;
        }
        $this->donutExtras = $e;
        $this->themeTokens = true;

        if ($e['value'] !== null) {
            $v = max(0.0, min($e['max'], $e['value']));
            $done = $e['label'] !== '' ? $e['label'] : mad_t('mad.chart.done');
            $this->fieldGroup = ['label'];
            $this->setData([[$done, $v], [mad_t('mad.chart.remaining'), $e['max'] - $v]]);
            $this->setIntegerSeries(floor($v) == $v && floor($e['max']) == $e['max']);
        }
    }

    /**
     * Cores do caller. Tom (`success`, `danger`…) e `chart-N` viram o token do
     * tema também nos gráficos do banco e de `:data` — antes só as séries do
     * PHP traduziam, e o tom chegava cru ao ECharts (fatia preta).
     */
    public function setColors(array $colors)
    {
        $this->customColors = $colors !== [];
        $colors = array_map(static fn ($c) => is_string($c) ? (self::seriesColor($c) ?? $c) : $c, $colors);

        return parent::setColors($colors);
    }

    /** Cores escolhidas pelo caller (vazio = paleta do tema). */
    private function callerColors(): array
    {
        return $this->customColors ? $this->strokeColors() : [];
    }

    public function usesThemeTokens(): bool
    {
        return $this->themeTokens;
    }

    // ── Normalização (pública para teste) ──────────────────────────────────

    /**
     * @return array{labels: list<string>, series: list<array<string,mixed>>}
     */
    public static function normalizeSeries(array $labels, array $series): array
    {
        $labels = array_values(array_map(static fn ($l) => (string) $l, $labels));
        if ($labels === []) {
            throw new InvalidArgumentException('séries: informe os rótulos (labels) do eixo');
        }
        $n = count($labels);
        $index = array_flip($labels);

        $list = [];
        foreach ($series as $key => $s) {
            if (is_string($key) && is_array($s) && !isset($s['data'])) {
                $s = ['name' => $key, 'data' => $s];
            } elseif (is_string($key) && is_array($s) && !isset($s['name'])) {
                $s['name'] = $key;
            }
            if (!is_array($s) || !isset($s['data']) || !is_array($s['data'])) {
                throw new InvalidArgumentException('séries: cada série precisa de name e data');
            }
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('séries: série sem name');
            }

            $values = array_fill(0, $n, null);
            if (array_is_list($s['data'])) {
                foreach (array_slice($s['data'], 0, $n) as $i => $v) {
                    $values[$i] = self::seriesValue($v, $name);
                }
            } else {
                foreach ($s['data'] as $label => $v) {
                    if (isset($index[(string) $label])) {
                        $values[$index[(string) $label]] = self::seriesValue($v, $name);
                    }
                }
            }
            if (self::flag($s['cumulative'] ?? false)) {
                $acc = 0.0;
                foreach ($values as $i => $v) {
                    if ($v === null) continue;
                    $acc += $v;
                    $values[$i] = round($acc, 10);
                }
            }

            $type = strtolower(trim((string) ($s['type'] ?? '')));
            if ($type !== '' && !in_array($type, self::SERIES_TYPES, true)) {
                throw new InvalidArgumentException("séries: tipo inválido em {$name}: {$type} (bar, line, scatter)");
            }
            $axis = strtolower(trim((string) ($s['axis'] ?? 'left')));
            $stack = $s['stack'] ?? null;
            $symbol = (string) ($s['symbol'] ?? '');

            $list[] = [
                'name' => $name,
                'data' => $values,
                'type' => $type !== '' ? $type : null,
                'right' => in_array($axis, ['right', 'secondary', 'direita'], true),
                'stack' => $stack === true || $stack === 'true' || $stack === 1 ? 'total' : (is_string($stack) && trim($stack) !== '' && $stack !== 'false' ? trim($stack) : null),
                'area' => self::flag($s['area'] ?? false),
                'smooth' => self::flag($s['smooth'] ?? false),
                'dashed' => self::flag($s['dashed'] ?? false),
                'labels' => self::flag($s['labels'] ?? false),
                'highlightLast' => self::flag($s['highlightLast'] ?? ($s['highlight-last'] ?? false)),
                'symbol' => in_array($symbol, self::SYMBOLS, true) ? $symbol : null,
                'color' => self::seriesColor($s['color'] ?? null),
            ];
        }
        if ($list === []) {
            throw new InvalidArgumentException('séries: nenhuma série informada');
        }

        return ['labels' => $labels, 'series' => $list];
    }

    /** @return list<array<string,mixed>> */
    public static function normalizeMarkers(array $markers): array
    {
        $out = [];
        foreach ($markers as $m) {
            if (!is_array($m)) continue;
            $label = trim((string) ($m['label'] ?? ''));
            $color = self::seriesColor($m['color'] ?? null) ?? self::MARKER_COLOR;
            $dashed = !array_key_exists('dashed', $m) || self::flag($m['dashed']);
            if (array_key_exists('x', $m) && $m['x'] !== null && $m['x'] !== '') {
                $out[] = ['axis' => 'x', 'at' => is_int($m['x']) ? $m['x'] : (string) $m['x'], 'label' => $label, 'color' => $color, 'dashed' => $dashed, 'right' => false];
            } elseif (array_key_exists('y', $m) && is_numeric($m['y'])) {
                $axis = strtolower(trim((string) ($m['axis'] ?? 'left')));
                $out[] = ['axis' => 'y', 'at' => (float) $m['y'], 'label' => $label, 'color' => $color, 'dashed' => $dashed, 'right' => in_array($axis, ['right', 'secondary', 'direita'], true)];
            }
        }

        return $out;
    }

    public static function seriesColor(mixed $c): ?string
    {
        if (!is_string($c) || trim($c) === '') return null;
        $c = trim($c);
        if (isset(self::SERIES_TONES[$c])) return self::SERIES_TONES[$c];
        if (preg_match('/^chart-([1-8])$/', $c, $m)) return self::CHART_TOKENS[(int) $m[1] - 1];

        return preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla|oklch)\([0-9.,%\s\/a-z-]+\)|var\(--[a-z0-9-]+(,\s*#[0-9a-f]{3,8})?\))$/i', $c) ? $c : null;
    }

    private static function seriesValue(mixed $v, string $name): ?float
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) {
            throw new InvalidArgumentException("séries: valor não numérico em {$name}");
        }

        return (float) $v;
    }

    private static function flag(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return false;

        return !in_array(strtolower(trim((string) $v)), ['', '0', 'false', 'no', 'off', 'nao', 'não'], true);
    }

    // ── Opções ECharts ─────────────────────────────────────────────────────

    /** Gráfico de eixos a partir das séries do PHP (bar, line, mixed). */
    private function buildManualSeriesOptions(): array
    {
        $ms = $this->manualSeries;
        $labels = $ms['labels'];
        $horizontal = ($this->barDirection === 'horizontal');
        $defaultType = $this->type === 'line' ? 'line' : 'bar';
        $colors = $this->callerColors();
        $hasTransformer = $this->hasDisplayTransformer();
        $n = $this->name;

        $catLabels = [];
        foreach ($labels as $l) {
            $catLabels[] = $this->transformerLegend ? (string) call_user_func($this->transformerLegend, $l, $l, $this->data) : $l;
        }

        $series = [];
        $display = [];
        $hasBar = false;
        $hasRight = false;
        $count = count($ms['series']);
        foreach ($ms['series'] as $i => $s) {
            $type = $s['type'] ?? $defaultType;
            $color = $s['color'] ?? (self::seriesColor($colors[$i] ?? null) ?? ($colors[$i] ?? self::paletteColor($i)));
            $e = ['name' => $s['name'], 'type' => $type, 'data' => $s['data']];
            if ($type === 'bar') {
                $hasBar = true;
                $e['itemStyle'] = ['color' => $color, 'borderRadius' => $horizontal ? [0, 3, 3, 0] : [3, 3, 0, 0]];
                $e['barMaxWidth'] = $this->barMaxWidth === null ? 36 : ($this->barMaxWidth ?: null);
                $stack = $s['stack'] ?? ($this->barStack ? 'total' : null);
                if ($stack !== null) $e['stack'] = $stack;
            } elseif ($type === 'line') {
                $e['itemStyle'] = ['color' => $color];
                $e['lineStyle'] = ['color' => $color, 'width' => $s['highlightLast'] ? 3 : 2.5];
                if ($s['dashed']) $e['lineStyle']['type'] = 'dashed';
                $e['symbol'] = $s['symbol'] ?? 'circle';
                $e['symbolSize'] = 6;
                $e['showSymbol'] = count($labels) <= 24 && $e['symbol'] !== 'none';
                $e['smooth'] = $s['smooth'];
                $e['connectNulls'] = false;
                $e['z'] = 3;
                if ($s['area']) $e['areaStyle'] = ['color' => $color, 'opacity' => 0.12];
                if ($s['stack'] !== null) $e['stack'] = $s['stack'];
            } else {
                $e['symbol'] = $s['symbol'] ?? 'diamond';
                $e['symbolSize'] = 12;
                $e['itemStyle'] = ['color' => $color, 'borderColor' => 'var(--mad-surface, #ffffff)', 'borderWidth' => 1.5];
                $e['z'] = 4;
            }
            if ($s['right']) {
                $e[$horizontal ? 'xAxisIndex' : 'yAxisIndex'] = 1;
                $hasRight = true;
            }
            if ($s['labels']) {
                $e['label'] = ['show' => true, 'position' => $type === 'bar' && $horizontal ? 'right' : 'top', 'formatter' => $this->jsValueLabelFormatter($hasTransformer)];
            }
            if ($s['highlightLast']) {
                for ($k = count($s['data']) - 1; $k >= 0 && $s['data'][$k] === null; $k--);
                if ($k >= 0) {
                    $e['markPoint'] = [
                        'symbol' => 'circle', 'symbolSize' => 13, 'silent' => true,
                        'label' => ['show' => false],
                        'itemStyle' => ['color' => $color, 'borderColor' => 'var(--mad-surface, #ffffff)', 'borderWidth' => 2],
                        'data' => [['coord' => $horizontal ? [$s['data'][$k], $k] : [$k, $s['data'][$k]]]],
                    ];
                }
            }
            if ($hasTransformer) {
                $display[$s['name']] = array_map(fn ($v) => $v === null ? null : $this->resolveDisplayValue($v, [$labels[0], $s['name'], $v]), $s['data']);
            }
            $series[] = $e;
        }
        $this->ecDisplayValues = $hasTransformer ? $display : null;

        $catAxis = ['type' => 'category', 'data' => $catLabels, 'boundaryGap' => $hasBar, 'axisLabel' => ['hideOverlap' => true]];
        if ($horizontal && $this->barFirstOnTop) $catAxis['inverse'] = true;
        $valueAxis = $this->buildValueAxis();
        $valueAxes = $hasRight ? [$valueAxis, $this->buildValueAxis() + ['splitLine' => ['show' => false]]] : $valueAxis;

        $legend = $count > 1 || $this->legend;
        $option = [
            'tooltip' => [
                'trigger' => 'axis',
                'axisPointer' => ['type' => $hasBar ? 'shadow' : 'line'],
                'confine' => true,
                'formatter' => $this->jsAxisTooltip($hasTransformer),
            ],
            'legend' => ['show' => $legend, 'type' => 'scroll', 'top' => 0, 'data' => array_column($ms['series'], 'name')],
            'grid' => ['containLabel' => true, 'left' => 8, 'right' => $hasRight ? 8 : 16, 'top' => $legend ? 40 : 16, 'bottom' => 8],
            'xAxis' => $horizontal ? $valueAxes : $catAxis,
            'yAxis' => $horizontal ? $catAxis : $valueAxes,
            'series' => $series,
        ];
        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title, 'left' => 'center'];
            $option['grid']['top'] += 28;
            $option['legend']['top'] = 28;
        }

        return $option;
    }

    /** Linhas de marcação ("Hoje", "Contratado") — série própria, fora da legenda. */
    private function applyMarkers(array $option): array
    {
        if ($this->markers === [] || !in_array($this->type, ['bar', 'line', 'mixed'], true)) {
            return $option;
        }
        $horizontal = ($this->barDirection === 'horizontal');
        $catKey = $horizontal ? 'yAxis' : 'xAxis';
        $valKey = $horizontal ? 'xAxis' : 'yAxis';
        $cat = $option[$catKey] ?? [];
        $cats = array_is_list($cat) ? ($cat[0]['data'] ?? []) : ($cat['data'] ?? []);

        $groups = ['left' => [], 'right' => []];
        $peak = ['left' => null, 'right' => null];
        foreach ($this->markers as $m) {
            if ($m['axis'] === 'x') {
                $at = is_int($m['at']) ? ($cats[$m['at']] ?? null) : $m['at'];
                if ($at === null || !in_array((string) $at, array_map('strval', $cats), true)) continue;
                $point = [$catKey => (string) $at];
                $pos = $horizontal ? 'insideEndTop' : 'end';
            } else {
                $point = [$valKey => $m['at']];
                $pos = $horizontal ? 'end' : 'insideEndTop';
                $side = $m['right'] ? 'right' : 'left';
                $peak[$side] = max($peak[$side] ?? (float) $m['at'], (float) $m['at']);
            }
            $point['label'] = ['show' => $m['label'] !== '', 'formatter' => str_replace(['{', '}'], ['(', ')'], $m['label']), 'position' => $pos, 'color' => $m['color'], 'fontWeight' => 600];
            $point['lineStyle'] = ['color' => $m['color'], 'type' => $m['dashed'] ? 'dashed' : 'solid', 'width' => 1.5];
            $groups[$m['right'] ? 'right' : 'left'][] = $point;
        }

        $option = $this->stretchValueAxis($option, $valKey, $horizontal ? 'xAxisIndex' : 'yAxisIndex', $peak);

        $names = array_values(array_filter(array_map(static fn ($s) => (string) ($s['name'] ?? ''), $option['series'] ?? []), static fn ($s) => $s !== ''));
        $added = false;
        foreach ($groups as $side => $data) {
            if ($data === []) continue;
            $s = [
                'name' => '', 'type' => 'line', 'data' => [], 'silent' => true, 'z' => 5,
                'tooltip' => ['show' => false],
                'markLine' => ['symbol' => ['none', 'none'], 'silent' => true, 'animation' => false, 'data' => $data],
            ];
            if ($side === 'right') {
                $s[$horizontal ? 'xAxisIndex' : 'yAxisIndex'] = 1;
            }
            $option['series'][] = $s;
            $added = true;
        }
        if ($added) {
            $option['legend'] = ($option['legend'] ?? []) + ['show' => false];
            $option['legend']['data'] = $names;
            if (!isset($option['grid'])) {
                $option['grid'] = ['containLabel' => true, 'left' => '3%', 'right' => '4%', 'bottom' => '3%'];
            }
            $option['grid']['top'] = max((int) ($option['grid']['top'] ?? 0), !empty($option['legend']['show']) ? 44 : 28);
        }

        return $option;
    }

    /**
     * O ECharts não estica o eixo até uma markLine: um marcador `y` acima do
     * maior valor das séries ("Contratado 11.976 h" com dados em 4.150) ficava
     * fora do gráfico. Quando o marcador passa do topo dos dados do eixo dele,
     * o eixo ganha um máximo redondo acima do marcador; senão nada muda.
     *
     * @param array{left: ?float, right: ?float} $peak
     */
    private function stretchValueAxis(array $option, string $valKey, string $indexKey, array $peak): array
    {
        $axes = $option[$valKey] ?? null;
        if (!is_array($axes)) {
            return $option;
        }
        $list = array_is_list($axes);
        foreach (['left' => 0, 'right' => 1] as $side => $idx) {
            $mark = $peak[$side];
            if ($mark === null || $mark <= 0) continue;
            if ($list ? !isset($axes[$idx]) : $idx !== 0) continue;
            $axis = $list ? $axes[$idx] : $axes;
            if (!is_array($axis) || array_key_exists('max', $axis)) continue;

            // Topo dos dados deste eixo (séries empilhadas somam por categoria).
            $top = 0.0;
            $stacks = [];
            foreach ($option['series'] ?? [] as $s) {
                if ((int) ($s[$indexKey] ?? 0) !== $idx) continue;
                foreach (array_values((array) ($s['data'] ?? [])) as $i => $d) {
                    $v = is_array($d) ? ($d['value'] ?? (array_is_list($d) ? end($d) : null)) : $d;
                    if (!is_numeric($v)) continue;
                    if (!empty($s['stack'])) {
                        $k = $s['stack'] . "\x1f" . $i;
                        $stacks[$k] = ($stacks[$k] ?? 0.0) + max(0.0, (float) $v);
                        $top = max($top, $stacks[$k]);
                    } else {
                        $top = max($top, (float) $v);
                    }
                }
            }
            if ($mark <= $top) continue;

            $axis['max'] = self::niceCeil($mark * 1.06);
            if ($list) {
                $axes[$idx] = $axis;
            } else {
                $axes = $axis;
            }
        }
        $option[$valKey] = $axes;

        return $option;
    }

    /** Próximo número "redondo" (1; 1,2; 1,5; 2; 2,5; 3; 4; 5; 6; 8 × 10ⁿ) acima de $v. */
    private static function niceCeil(float $v): float
    {
        if ($v <= 0) {
            return 0.0;
        }
        $p = 10 ** floor(log10($v));
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $f) {
            if ($f * $p >= $v - 1e-9) {
                return round($f * $p, 6);
            }
        }

        return 10 * $p;
    }

    /** Donut de progresso, meta no anel e texto no centro. */
    private function applyDonutExtras(array $option): array
    {
        $e = $this->donutExtras;
        if ($e === null || !in_array($this->type, ['donut', 'pie'], true) || !isset($option['series'][0])) {
            return $option;
        }
        $main = &$option['series'][0];
        $total = 0.0;
        foreach ($main['data'] as $d) {
            $total += (float) (is_array($d) ? ($d['value'] ?? 0) : $d);
        }
        $progress = $e['value'] !== null;
        $inner = $progress ? 64 : 40;
        $outer = $progress ? 84 : 70;
        $legendBelow = !empty($option['legend']['show']) && ($this->positionLegend ?? 'bottom') !== 'right';
        $center = ['50%', $legendBelow ? '46%' : '50%'];
        $main['radius'] = [$inner . '%', $outer . '%'];
        $main['center'] = $center;

        if ($progress) {
            $colors = $this->callerColors();
            $fill = self::seriesColor($colors[0] ?? null) ?? ($colors[0] ?? self::SERIES_PALETTE[0]);
            $main['data'][0]['itemStyle'] = ['color' => $fill];
            if (isset($main['data'][1])) {
                $main['data'][1]['itemStyle'] = ['color' => self::SERIES_TONES['muted']];
                $main['data'][1]['emphasis'] = ['disabled' => true];
                $main['data'][1]['tooltip'] = ['show' => false];
            }
            $main['label'] = ['show' => false];
            $main['labelLine'] = ['show' => false];
            $main['emphasis'] = ['scale' => false];
            $main['avoidLabelOverlap'] = false;
            $option['legend']['show'] = false;
            $option['legend']['data'] = [];
        }

        $first = (float) (is_array($main['data'][0] ?? null) ? ($main['data'][0]['value'] ?? 0) : ($main['data'][0] ?? 0));
        $pctOf = static fn (float $v, float $of): string => $of > 0 ? self::pctText($v / $of * 100) : '0%';

        $goalValue = null;
        $goalText = '';
        if ($e['goal'] !== null && $total > 0) {
            $goalValue = $e['goalPct'] ? $total * $e['goal'] / 100 : $e['goal'];
            $goalValue = max(0.0, min($total, $goalValue));
            $goalShown = $e['goalPct'] || ($progress && $e['max'] == 100.0) ? self::pctText($e['goalPct'] ? $e['goal'] : $goalValue) : $this->formatPlain($goalValue);
            $goalText = $e['goalLabel'] !== '' ? $e['goalLabel'] : mad_t('mad.chart.goal', ['value' => $goalShown]);
        }

        $centerText = $e['center'] ?? ($progress || $goalValue !== null ? $pctOf($first, $total) : '');
        $centerSub = $e['centerSub'] ?? $goalText;
        if ($centerText !== '' || $centerSub !== '') {
            $clean = static fn (string $t): string => str_replace(['{', '}', '|'], ['(', ')', '/'], $t);
            $fmt = ($centerText !== '' ? '{v|' . $clean($centerText) . '}' : '') . ($centerSub !== '' ? ($centerText !== '' ? "\n" : '') . '{s|' . $clean($centerSub) . '}' : '');
            $option['series'][] = [
                'type' => 'pie', 'silent' => true, 'z' => 1, 'radius' => [0, ($inner - 2) . '%'], 'center' => $center,
                'tooltip' => ['show' => false], 'emphasis' => ['disabled' => true], 'labelLine' => ['show' => false],
                'data' => [[
                    'value' => 1, 'name' => '', 'itemStyle' => ['color' => 'transparent'],
                    'label' => [
                        'show' => true, 'position' => 'center', 'formatter' => $fmt,
                        'rich' => [
                            'v' => ['fontSize' => 26, 'fontWeight' => 700, 'lineHeight' => 32, 'color' => 'var(--mad-text, #18181b)'],
                            's' => ['fontSize' => 12, 'lineHeight' => 16, 'color' => 'var(--mad-text-muted, #52525b)'],
                        ],
                    ],
                ]],
            ];
        }

        if ($goalValue !== null) {
            // O ponteiro do medidor, curto e afastado do centro, vira o traço da meta no anel.
            $from = (int) round($inner / $outer * 100) - 2;
            $option['series'][] = [
                'type' => 'gauge', 'silent' => true, 'z' => 6, 'center' => $center, 'radius' => $outer . '%',
                'startAngle' => 90, 'endAngle' => -270, 'clockwise' => true, 'min' => 0, 'max' => $total,
                'axisLine' => ['show' => false], 'axisTick' => ['show' => false], 'splitLine' => ['show' => false],
                'axisLabel' => ['show' => false], 'progress' => ['show' => false], 'anchor' => ['show' => false],
                'title' => ['show' => false], 'detail' => ['show' => false],
                'pointer' => ['show' => true, 'icon' => 'rect', 'width' => 4, 'length' => (104 - $from) . '%', 'offsetCenter' => [0, '-' . $from . '%'],
                    'itemStyle' => ['color' => 'var(--mad-text, #18181b)', 'borderColor' => 'var(--mad-surface, #ffffff)', 'borderWidth' => 1]],
                'data' => [['value' => $goalValue, 'name' => '']],
            ];
        }
        unset($main);

        if (!$progress && isset($option['legend']) && !isset($option['legend']['data'])) {
            $option['legend']['data'] = array_values(array_map(static fn ($d) => (string) ($d['name'] ?? ''), $option['series'][0]['data']));
        }

        return $option;
    }

    /** Descrição em texto para leitor de tela (option.aria). */
    private function applyAria(array $option): array
    {
        $title = trim((string) ($this->title ?? ''));
        $parts = [];
        if ($this->manualSeries !== null) {
            $ms = $this->manualSeries;
            $names = array_column($ms['series'], 'name');
            $parts[] = mad_t('mad.chart.aria_series', [
                'count' => count($names), 'names' => implode(', ', $names),
                'first' => $ms['labels'][0], 'last' => $ms['labels'][count($ms['labels']) - 1],
            ]);
            $last = [];
            foreach ($ms['series'] as $s) {
                for ($k = count($s['data']) - 1; $k >= 0 && $s['data'][$k] === null; $k--);
                if ($k >= 0) $last[] = $s['name'] . ' ' . $this->formatPlain($s['data'][$k]) . ' (' . $ms['labels'][$k] . ')';
            }
            if ($last !== []) $parts[] = mad_t('mad.chart.aria_last', ['values' => implode('; ', $last)]);
        } elseif ($this->donutExtras !== null && in_array($this->type, ['donut', 'pie'], true)) {
            $slices = [];
            foreach ((array) $this->data as $r) {
                if ($this->donutExtras['value'] !== null && count($slices) === 1) break;
                $slices[] = ($r[0] ?? '') . ' ' . $this->formatPlain((float) end($r));
            }
            $parts[] = implode('; ', $slices);
            foreach ($option['series'] as $s) {
                if (($s['type'] ?? '') === 'pie' && isset($s['data'][0]['label']['formatter'])) {
                    $parts[] = preg_replace('/\{[vs]\|([^}]*)\}/', '$1', str_replace("\n", ' · ', $s['data'][0]['label']['formatter']));
                }
            }
        }
        foreach ($this->markers as $m) {
            if ($m['label'] === '') continue;
            $at = is_int($m['at']) ? ($this->manualSeries['labels'][$m['at']] ?? $m['at']) : $m['at'];
            $parts[] = mad_t('mad.chart.aria_marker', ['label' => $m['label'], 'at' => $m['axis'] === 'y' ? $this->formatPlain((float) $at) : (string) $at]);
        }
        $parts = array_values(array_filter($parts, static fn ($p) => trim((string) $p) !== ''));
        if ($parts === []) {
            return $option;
        }
        $option['aria'] = ['enabled' => true, 'label' => ['description' => ($title !== '' ? $title . '. ' : '') . implode('. ', $parts) . '.']];

        return $option;
    }

    // ── Formatação ─────────────────────────────────────────────────────────

    private static function pctText(float $pct): string
    {
        $r = round($pct, 1);

        return (floor($r) == $r ? (string) (int) $r : number_format($r, 1, ',', '')) . '%';
    }

    /** Valor no formato do gráfico (prefixo, casas, separadores, sufixo), em texto puro. */
    private function formatPlain(float $v): string
    {
        $p = $this->displayPrecision();

        return ($this->prefix ?? '') . number_format($v, $p, $this->decimalSeparator ?? ',', $this->thousandSeparator ?? '.') . ($this->sufix ?? '');
    }

    private function jsAxisTooltip(bool $hasTransformer): string
    {
        $p = $this->displayPrecision();
        $dec = $this->decimalSeparator ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix ?? '';
        $suf = $this->sufix ?? '';
        $n = $this->name;
        $val = $hasTransformer
            ? "var a = __dv_{$n}[p.seriesName]; var dv = a ? a[p.dataIndex] : null; var t = (dv !== null && dv !== undefined) ? dv : p.value;"
            : "var t = '{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}';";

        return "::function(params) { if (!params.length) return ''; var r = params[0].axisValueLabel + '<br/>'; params.forEach(function(p) { if (!p.seriesName || p.value === null || p.value === undefined || p.value === '-') return; {$val} r += p.marker + p.seriesName + ': <b>' + t + '</b><br/>'; }); return r; }::";
    }

    private function jsValueLabelFormatter(bool $hasTransformer): string
    {
        $p = $this->displayPrecision();
        $dec = $this->decimalSeparator ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix ?? '';
        $suf = $this->sufix ?? '';
        $n = $this->name;
        if ($hasTransformer) {
            return "::function(p) { var a = __dv_{$n}[p.seriesName]; var dv = a ? a[p.dataIndex] : null; return (dv !== null && dv !== undefined) ? dv : p.value; }::";
        }

        return "::function(p) { if (p.value === null || p.value === undefined) return ''; return '{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
    }
}
