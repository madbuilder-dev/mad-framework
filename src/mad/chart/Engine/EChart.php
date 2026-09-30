<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * Abstract class BEChart
 *
 * ECharts-powered chart widget. Extends BChart reusing all data loading,
 * transformer, color, and configuration infrastructure. Overrides show()
 * to render via ECharts (v6) instead of C3.js.
 *
 * Supported types: bar, line, pie, donut, rose, funnel, treemap, radar, mixed
 * Future replacement for BChart/C3-based charts.
 *
 * @version    1.2
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
abstract class EChart extends BaseChart
{
    /**
     * Display values pre-computed from transformerValue/transformerLabelValue.
     * Passed to the template as a separate JS variable (__dv_{name}) so they
     * are never double-JSON-encoded alongside the ECharts option object.
     *
     * Formats:
     *   - pie/donut : assoc array  ['label' => 'R$ 1,00', ...]
     *   - bar/line single : indexed array ['R$ 1,00', 'R$ 2,00', ...]
     *   - bar/line multi  : nested assoc  ['Serie' => ['R$ 1,00', ...], ...]
     *   - radar : assoc  ['__labels' => [ind1, ...]] (+ ['Serie' => [dv...], ...]
     *             quando há transformer) — labels dos indicators viajam por
     *             aqui para o formatter de tooltip (p.value é só o vetor).
     */
    private $ecDisplayValues = null;

    // ─────────────────────────────────────────────────────────────────────────
    // JS formatter helpers (single-line — newlines would become \n in JSON
    // and cause SyntaxError when injected as raw JS via the :: trick)
    // ─────────────────────────────────────────────────────────────────────────

    private function jsNumberFormatter(): string
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';

        return "::function(v) { if (v === null || v === undefined) return ''; return '{$pre}' + number_format(v, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
    }

    private function jsAbbreviateFormatter(): string
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';

        // Must be a single line — json_encode would escape real newlines to \n,
        // which is invalid when the string is later used as raw JS source.
        return "::function(v) { if (v === null || v === undefined) return ''; var a = Math.abs(v); if (a >= 1e9) return '{$pre}' + (v/1e9).toFixed(0) + 'B' + '{$suf}'; if (a >= 1e6) return '{$pre}' + (v/1e6).toFixed(0) + 'M' + '{$suf}'; if (a >= 1e3) return '{$pre}' + (v/1e3).toFixed(0) + 'K' + '{$suf}'; return '{$pre}' + number_format(v, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
    }

    private function buildValueAxis(): array
    {
        $formatter = $this->abbreviatedValues
            ? $this->jsAbbreviateFormatter()
            : $this->jsNumberFormatter();

        return ['type' => 'value', 'axisLabel' => ['formatter' => $formatter]];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Color helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function strokeColors(): array
    {
        if (empty($this->colors)) return [];
        return isset($this->colors[0]['stroke']) ? array_column($this->colors, 'stroke') : (array) $this->colors;
    }

    private function fillColors(): array
    {
        if (empty($this->colors)) return [];
        return isset($this->colors[0]['fill']) ? array_column($this->colors, 'fill') : (array) $this->colors;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Display value resolution
    // Uses transformerLabelValue (priority) or transformerValue — result is
    // ONLY for display (tooltip/label). series.data always gets (float) raw.
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveDisplayValue(float $rawValue, array $item): ?string
    {
        if ($this->transformerLabelValue) {
            return (string) call_user_func($this->transformerLabelValue, $rawValue, $item, $this->data);
        }
        if ($this->transformerValue) {
            return (string) call_user_func($this->transformerValue, $rawValue, $item, $this->data);
        }
        return null;
    }

    private function hasDisplayTransformer(): bool
    {
        return ($this->transformerLabelValue !== null) || ($this->transformerValue !== null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Option builders
    // All display values are stored in $this->ecDisplayValues and injected
    // into the template as a separate JS variable (__dv_{name}) — never
    // embedded inside the ECharts option JSON (avoids double-encoding of ").
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Shared data builder for pie-like chart types (pie, donut, rose, funnel, treemap).
     * Populates $this->ecDisplayValues as a side-effect.
     * Returns [$seriesData, $hasTransformer].
     */
    private function buildPieLikeData(): array
    {
        $seriesData    = [];
        $displayValues = [];
        $strokes       = $this->strokeColors();
        $hasTransformer = $this->hasDisplayTransformer();

        foreach ($this->data as $i => $item) {
            $label    = $item[0];
            $rawValue = (float) $item[1];

            if ($this->transformerLegend) {
                $label = call_user_func($this->transformerLegend, $label, $item, $this->data);
            }
            $labelStr = (string) $label;

            if ($hasTransformer) {
                $displayValues[$labelStr] = $this->resolveDisplayValue($rawValue, $item);
            }

            $dataItem = ['name' => $labelStr, 'value' => $rawValue];

            if ($this->fieldColor && is_array($this->colors) && isset($this->colors[$item[0]])) {
                $dataItem['itemStyle'] = ['color' => $this->colors[$item[0]]];
            } elseif (!empty($strokes[$i])) {
                $dataItem['itemStyle'] = ['color' => $strokes[$i]];
            }

            $seriesData[] = $dataItem;
        }

        $this->ecDisplayValues = $hasTransformer ? $displayValues : null;

        return [$seriesData, $hasTransformer];
    }

    private function buildPieLikeFormatters(bool $hasTransformer): array
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';
        $n   = $this->name;

        if ($this->percentage) {
            $labelFmt   = "::function(p) { return p.name + ': ' + p.percent.toFixed(1) + '%'; }::";
            $tooltipFmt = "::function(p) { return p.name + '<br/>' + p.percent.toFixed(1) + '%'; }::";
        } elseif ($hasTransformer) {
            $labelFmt   = "::function(p) { var dv = __dv_{$n}[p.name]; return p.name + ': ' + (dv !== undefined ? dv : p.value); }::";
            $tooltipFmt = "::function(p) { var dv = __dv_{$n}[p.name]; return p.name + '<br/>' + (dv !== undefined ? dv : p.value) + ' (' + p.percent.toFixed(1) + '%)'; }::";
        } else {
            $labelFmt   = "::function(p) { if (!p.value && p.value !== 0) return p.name; return p.name + ': {$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
            $tooltipFmt = "::function(p) { return p.name + '<br/>{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf} (' + p.percent.toFixed(1) + '%)'; }::";
        }

        return [$labelFmt, $tooltipFmt];
    }

    private function buildPieOptions(): array
    {
        [$seriesData, $hasTransformer] = $this->buildPieLikeData();
        [$labelFmt, $tooltipFmt]       = $this->buildPieLikeFormatters($hasTransformer);

        $radius = ($this->type === 'donut') ? ['40%', '70%'] : '60%';

        $legendPos = ($this->positionLegend === 'right')
            ? ['right' => 0, 'top' => 'middle', 'orient' => 'vertical']
            : ['bottom' => 0, 'orient' => 'horizontal'];

        $series = [
            'type'     => 'pie',
            'radius'   => $radius,
            'data'     => $seriesData,
            'label'    => ['show' => !$this->percentage, 'formatter' => $labelFmt],
            'emphasis' => ['itemStyle' => ['shadowBlur' => 10, 'shadowOffsetX' => 0, 'shadowColor' => 'rgba(0,0,0,0.5)']],
        ];

        if ($this->type === 'donut') {
            $series['label']['position'] = 'outside';
        }

        $option = [
            'tooltip' => ['trigger' => 'item', 'formatter' => $tooltipFmt],
            'legend'  => array_merge(['show' => $this->legend], $legendPos),
            'series'  => [$series],
        ];

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title, 'left' => 'center'];
        }

        return $option;
    }

    private function buildBarOptions(): array
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';

        $countGroups    = count($this->fieldGroup);
        $strokes        = $this->strokeColors();
        $fills          = $this->fillColors();
        $horizontal     = ($this->barDirection === 'horizontal');
        $hasTransformer = $this->hasDisplayTransformer();
        $n              = $this->name;

        if ($countGroups > 1) {
            // ── Multi-series ─────────────────────────────────────────────────
            $categories   = array_values(array_unique(array_column($this->data, 0)));
            $catFormatted = [];
            foreach ($categories as $c) {
                $catFormatted[] = $this->transformerLegend
                    ? (string) call_user_func($this->transformerLegend, $c, $c, $this->data)
                    : (string) $c;
            }

            $seriesMap        = [];
            $displayValuesMap = [];

            foreach ($this->data as $item) {
                $catKey     = $item[0];
                $seriesName = (string) $item[$countGroups - 1];
                $rawValue   = (float) $item[$countGroups];

                if ($this->transformerSubLegend) {
                    $seriesName = (string) call_user_func($this->transformerSubLegend, $seriesName, $item, $this->data);
                }

                if (!isset($seriesMap[$seriesName])) {
                    $seriesMap[$seriesName]        = array_fill(0, count($categories), 0);
                    $displayValuesMap[$seriesName] = array_fill(0, count($categories), null);
                }

                $pos = array_search($catKey, $categories);
                if ($pos !== false) {
                    $seriesMap[$seriesName][$pos] = $rawValue;
                    if ($hasTransformer) {
                        $displayValuesMap[$seriesName][$pos] = $this->resolveDisplayValue($rawValue, $item);
                    }
                }
            }

            $this->ecDisplayValues = $hasTransformer ? $displayValuesMap : null;

            // Ordem DETERMINÍSTICA (alfabética) das séries: a ordem de inserção
            // vinha dos dados (arbitrária) e inviabilizava :colors posicionais.
            ksort($seriesMap, SORT_NATURAL | SORT_FLAG_CASE);

            $series = [];
            $i = 0;
            foreach ($seriesMap as $name => $values) {
                $s = ['name' => $name, 'type' => 'bar', 'data' => array_values($values), 'label' => ['show' => false]];
                if ($this->barStack) { $s['stack'] = 'total'; }
                $color = $fills[$i] ?? ($strokes[$i] ?? null);
                if ($color) {
                    $s['itemStyle'] = ['color' => $color, 'borderColor' => $strokes[$i] ?? $color, 'borderWidth' => 1];
                }
                $series[] = $s;
                $i++;
            }

            $catAxis   = ['type' => 'category', 'data' => $catFormatted];
            $valueAxis = $this->buildValueAxis();
            $xAxis     = $horizontal ? $valueAxis : $catAxis;
            $yAxis     = $horizontal ? $catAxis   : $valueAxis;

            if ($hasTransformer) {
                $tooltipFmt = "::function(params) { var r = params[0].axisValue + '<br/>'; params.forEach(function(p) { if (p.value === null || p.value === undefined) return; var dvArr = __dv_{$n}[p.seriesName]; var dv = dvArr ? dvArr[p.dataIndex] : null; r += p.marker + p.seriesName + ': ' + (dv !== null ? dv : p.value) + '<br/>'; }); return r; }::";
            } else {
                $tooltipFmt = "::function(params) { var r = params[0].axisValue + '<br/>'; params.forEach(function(p) { if (p.value === null || p.value === undefined) return; r += p.marker + p.seriesName + ': {$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}<br/>'; }); return r; }::";
            }

            $option = [
                'tooltip' => ['trigger' => 'axis', 'formatter' => $tooltipFmt],
                'legend'  => ['show' => $this->legend],
                'xAxis'   => $xAxis,
                'yAxis'   => $yAxis,
                'series'  => $series,
            ];
        } else {
            // ── Single-series ─────────────────────────────────────────────────
            $categories    = [];
            $seriesData    = [];
            $displayValues = [];

            foreach ($this->data as $i => $item) {
                $label    = $item[0];
                $rawValue = (float) $item[1];

                if ($this->transformerLegend) {
                    $label = call_user_func($this->transformerLegend, $label, $item, $this->data);
                }
                $categories[]    = (string) $label;
                $displayValues[] = $hasTransformer ? $this->resolveDisplayValue($rawValue, $item) : null;

                if ($this->fieldColor && is_array($this->colors) && isset($this->colors[$item[0]])) {
                    $seriesData[] = ['value' => $rawValue, 'itemStyle' => ['color' => $this->colors[$item[0]]]];
                } else {
                    // Uma cor só = cor da SÉRIE inteira (igual ao line); N
                    // cores = posicional por dado (ranking multicolorido).
                    $fill   = $fills[$i]   ?? (count($fills) === 1 ? $fills[0] : null);
                    $stroke = $strokes[$i] ?? (count($strokes) === 1 ? $strokes[0] : null);
                    $di     = ['value' => $rawValue];
                    if ($fill || $stroke) {
                        $di['itemStyle'] = ['color' => $fill ?? $stroke, 'borderColor' => $stroke ?? $fill, 'borderWidth' => 1];
                    }
                    $seriesData[] = $di;
                }
            }

            $this->ecDisplayValues = $hasTransformer ? array_values($displayValues) : null;

            if ($hasTransformer) {
                $labelFmt   = "::function(p) { var dv = __dv_{$n}[p.dataIndex]; return dv !== null && dv !== undefined ? dv : p.value; }::";
                $tooltipFmt = "::function(params) { var p = params[0]; var dv = __dv_{$n}[p.dataIndex]; return p.axisValue + '<br/>' + (dv !== null && dv !== undefined ? dv : p.value); }::";
            } else {
                $labelFmt   = "::function(p) { return '{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
                $tooltipFmt = "::function(params) { var p = params[0]; return p.axisValue + '<br/>{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
            }

            $catAxis   = ['type' => 'category', 'data' => $categories];
            $valueAxis = $this->buildValueAxis();
            $xAxis     = $horizontal ? $valueAxis : $catAxis;
            $yAxis     = $horizontal ? $catAxis   : $valueAxis;

            $series = [[
                'type'  => 'bar',
                'data'  => $seriesData,
                'label' => ['show' => true, 'position' => $horizontal ? 'right' : 'top', 'formatter' => $labelFmt],
            ]];

            $option = [
                'tooltip' => ['trigger' => 'axis', 'formatter' => $tooltipFmt],
                'legend'  => ['show' => false],
                'xAxis'   => $xAxis,
                'yAxis'   => $yAxis,
                'series'  => $series,
            ];
        }

        if ($this->grid) {
            $option['grid'] = ['containLabel' => true, 'left' => '3%', 'right' => '4%', 'bottom' => '3%'];
        }

        if ($this->rotateLegend) {
            $axisKey = $horizontal ? 'yAxis' : 'xAxis';
            $option[$axisKey]['axisLabel'] = ['rotate' => $this->rotateLegend, 'interval' => 0];
        }

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title];
        }

        return $option;
    }

    private function buildLineOptions(): array
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';

        $countGroups    = count($this->fieldGroup);
        $strokes        = $this->strokeColors();
        $hasTransformer = $this->hasDisplayTransformer();
        $n              = $this->name;

        if ($countGroups > 1) {
            // ── Multi-series ─────────────────────────────────────────────────
            $categories   = array_values(array_unique(array_column($this->data, 0)));
            $catFormatted = [];
            foreach ($categories as $c) {
                $catFormatted[] = $this->transformerLegend
                    ? (string) call_user_func($this->transformerLegend, $c, $c, $this->data)
                    : (string) $c;
            }

            $seriesMap        = [];
            $displayValuesMap = [];

            foreach ($this->data as $item) {
                $catKey     = $item[0];
                $seriesName = (string) $item[$countGroups - 1];
                $rawValue   = (float) $item[$countGroups];

                if ($this->transformerSubLegend) {
                    $seriesName = (string) call_user_func($this->transformerSubLegend, $seriesName, $item, $this->data);
                }

                if (!isset($seriesMap[$seriesName])) {
                    $seriesMap[$seriesName]        = array_fill(0, count($categories), 0);
                    $displayValuesMap[$seriesName] = array_fill(0, count($categories), null);
                }

                $pos = array_search($catKey, $categories);
                if ($pos !== false) {
                    $seriesMap[$seriesName][$pos] = $rawValue;
                    if ($hasTransformer) {
                        $displayValuesMap[$seriesName][$pos] = $this->resolveDisplayValue($rawValue, $item);
                    }
                }
            }

            $this->ecDisplayValues = $hasTransformer ? $displayValuesMap : null;

            // Ordem DETERMINÍSTICA (alfabética) das séries — mesma razão do bar.
            ksort($seriesMap, SORT_NATURAL | SORT_FLAG_CASE);

            $series = [];
            $i = 0;
            foreach ($seriesMap as $name => $values) {
                $s = ['name' => $name, 'type' => 'line', 'data' => array_values($values), 'smooth' => (bool)($this->areaRounded ?? false)];
                if ($this->area) { $s['areaStyle'] = ['opacity' => 0.2]; }
                if (!empty($strokes[$i])) {
                    $s['itemStyle'] = ['color' => $strokes[$i]];
                    $s['lineStyle'] = ['color' => $strokes[$i], 'width' => 2];
                }
                $series[] = $s;
                $i++;
            }

            if ($hasTransformer) {
                $tooltipFmt = "::function(params) { var r = params[0].axisValue + '<br/>'; params.forEach(function(p) { if (p.value === null || p.value === undefined) return; var dvArr = __dv_{$n}[p.seriesName]; var dv = dvArr ? dvArr[p.dataIndex] : null; r += p.marker + p.seriesName + ': ' + (dv !== null ? dv : p.value) + '<br/>'; }); return r; }::";
                $option = ['tooltip' => ['trigger' => 'axis', 'formatter' => $tooltipFmt], 'legend' => ['show' => $this->legend], 'xAxis' => ['type' => 'category', 'data' => $catFormatted], 'yAxis' => $this->buildValueAxis(), 'series' => $series];
            } else {
                $option = ['tooltip' => ['trigger' => 'axis'], 'legend' => ['show' => $this->legend], 'xAxis' => ['type' => 'category', 'data' => $catFormatted], 'yAxis' => $this->buildValueAxis(), 'series' => $series];
            }
        } else {
            // ── Single-series ─────────────────────────────────────────────────
            $categories    = [];
            $values        = [];
            $displayValues = [];

            foreach ($this->data as $i => $item) {
                $label    = $item[0];
                $rawValue = (float) $item[1];

                if ($this->transformerLegend) {
                    $label = call_user_func($this->transformerLegend, $label, $item, $this->data);
                }
                $categories[]    = (string) $label;
                $values[]        = $rawValue;
                $displayValues[] = $hasTransformer ? $this->resolveDisplayValue($rawValue, $item) : null;
            }

            $this->ecDisplayValues = $hasTransformer ? array_values($displayValues) : null;

            $color       = $strokes[0] ?? null;
            $seriesEntry = ['type' => 'line', 'data' => $values, 'smooth' => (bool)($this->areaRounded ?? false)];
            if ($color) {
                $seriesEntry['itemStyle'] = ['color' => $color];
                $seriesEntry['lineStyle'] = ['color' => $color, 'width' => 2];
            }
            if ($this->area) { $seriesEntry['areaStyle'] = ['opacity' => 0.2]; }

            if ($hasTransformer) {
                $tooltipFmt = "::function(params) { var p = params[0]; var dv = __dv_{$n}[p.dataIndex]; return p.axisValue + '<br/>' + (dv !== null && dv !== undefined ? dv : p.value); }::";
            } else {
                $tooltipFmt = "::function(params) { var p = params[0]; return p.axisValue + '<br/>{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
            }

            $option = [
                'tooltip' => ['trigger' => 'axis', 'formatter' => $tooltipFmt],
                'legend'  => ['show' => false],
                'xAxis'   => ['type' => 'category', 'data' => $categories],
                'yAxis'   => $this->buildValueAxis(),
                'series'  => [$seriesEntry],
            ];
        }

        if ($this->grid) {
            $option['grid'] = ['containLabel' => true, 'left' => '3%', 'right' => '4%', 'bottom' => '3%'];
        }

        if ($this->rotateLegend) {
            $option['xAxis']['axisLabel'] = ['rotate' => $this->rotateLegend, 'interval' => 0];
        }

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title];
        }

        return $option;
    }

    private function buildRoseOptions(): array
    {
        [$seriesData, $hasTransformer] = $this->buildPieLikeData();
        [$labelFmt, $tooltipFmt]       = $this->buildPieLikeFormatters($hasTransformer);

        $legendPos = ($this->positionLegend === 'right')
            ? ['right' => 0, 'top' => 'middle', 'orient' => 'vertical']
            : ['bottom' => 0, 'orient' => 'horizontal'];

        $option = [
            'tooltip' => ['trigger' => 'item', 'formatter' => $tooltipFmt],
            'legend'  => array_merge(['show' => $this->legend], $legendPos),
            'series'  => [[
                'type'      => 'pie',
                'roseType'  => 'area',
                'radius'    => ['15%', '65%'],
                'data'      => $seriesData,
                'label'     => ['show' => true, 'formatter' => $labelFmt],
                'emphasis'  => ['itemStyle' => ['shadowBlur' => 10, 'shadowOffsetX' => 0, 'shadowColor' => 'rgba(0,0,0,0.5)']],
            ]],
        ];

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title, 'left' => 'center'];
        }

        return $option;
    }

    /**
     * Linear RGB interpolation between blue-700 (#1D4ED8) and blue-300 (#93C5FD).
     * Darkest = highest value (top of funnel), lightest = lowest value (bottom).
     */
    private function monochromaticFunnelColors(int $count): array
    {
        if ($count <= 0) return [];
        if ($count === 1) return ['#2563EB'];

        $start = [29, 78, 216];   // blue-700 — top of funnel (largest slice)
        $end   = [147, 197, 253]; // blue-300 — bottom of funnel (smallest slice)

        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $t = $i / ($count - 1);
            $r = (int) round($start[0] + ($end[0] - $start[0]) * $t);
            $g = (int) round($start[1] + ($end[1] - $start[1]) * $t);
            $b = (int) round($start[2] + ($end[2] - $start[2]) * $t);
            $colors[] = sprintf('#%02X%02X%02X', $r, $g, $b);
        }
        return $colors;
    }

    private function buildFunnelOptions(): array
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';
        $n   = $this->name;

        [$seriesData, $hasTransformer] = $this->buildPieLikeData();

        // Sequential monochromatic gradient by value rank (darkest = highest).
        // Overrides the categorical palette inherited from buildPieLikeData()
        // unless the user explicitly set per-value colors via fieldColor.
        if (!$this->fieldColor && !empty($seriesData)) {
            $ranking = [];
            foreach ($seriesData as $i => $item) {
                $ranking[] = ['idx' => $i, 'val' => (float) ($item['value'] ?? 0)];
            }
            usort($ranking, fn($a, $b) => $b['val'] <=> $a['val']);
            $gradient = $this->monochromaticFunnelColors(count($seriesData));
            foreach ($ranking as $rank => $entry) {
                $seriesData[$entry['idx']]['itemStyle'] = ['color' => $gradient[$rank]];
            }
        }

        if ($hasTransformer) {
            $labelFmt   = "::function(p) { var dv = __dv_{$n}[p.name]; return p.name + ': ' + (dv !== undefined ? dv : p.value); }::";
            $tooltipFmt = "::function(p) { var dv = __dv_{$n}[p.name]; return p.name + '<br/>' + (dv !== undefined ? dv : p.value); }::";
        } else {
            $labelFmt   = "::function(p) { return p.name + ': {$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
            $tooltipFmt = "::function(p) { return p.name + '<br/>{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
        }

        $option = [
            'tooltip' => ['trigger' => 'item', 'formatter' => $tooltipFmt],
            'legend'  => ['show' => $this->legend, 'bottom' => 0],
            'series'  => [[
                'type'       => 'funnel',
                'sort'       => 'descending',
                'gap'        => 2,
                'width'      => '60%',
                'left'       => '20%',
                'top'        => 10,
                'bottom'     => $this->legend ? 50 : 20,
                'minSize'    => '30%',
                'maxSize'    => '100%',
                'label'      => [
                    'show'            => true,
                    'position'        => 'inside',
                    'formatter'       => $labelFmt,
                    'color'           => '#ffffff',
                    'fontSize'        => 12,
                    'fontWeight'      => 500,
                    'textBorderColor' => 'rgba(0,0,0,0.45)',
                    'textBorderWidth' => 2,
                ],
                'labelLine'  => ['show' => false],
                'itemStyle'  => ['borderColor' => '#fff', 'borderWidth' => 2],
                'emphasis'   => ['label' => ['fontSize' => 13]],
                'data'       => $seriesData,
            ]],
        ];

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title, 'left' => 'center'];
        }

        return $option;
    }

    private function buildTreemapOptions(): array
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';
        $n   = $this->name;

        [$seriesData, $hasTransformer] = $this->buildPieLikeData();

        if ($hasTransformer) {
            $tooltipFmt = "::function(p) { var dv = __dv_{$n}[p.name]; return p.name + '<br/>' + (dv !== undefined ? dv : p.value); }::";
            $labelFmt   = "::function(p) { var dv = __dv_{$n}[p.name]; return p.name + (dv !== undefined ? '\n' + dv : ''); }::";
        } else {
            $tooltipFmt = "::function(p) { return p.name + '<br/>{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
            $labelFmt   = "::function(p) { return p.name + '\n{$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}'; }::";
        }

        $option = [
            'tooltip' => ['trigger' => 'item', 'formatter' => $tooltipFmt],
            'series'  => [[
                'type'            => 'treemap',
                'data'            => $seriesData,
                'roam'            => false,
                'nodeClick'       => false,
                'breadcrumb'      => ['show' => false],
                'label'           => ['show' => true, 'formatter' => $labelFmt],
                'upperLabel'      => ['show' => false],
                'itemStyle'       => ['borderWidth' => 2, 'borderColor' => '#fff'],
                'emphasis'        => ['itemStyle' => ['shadowBlur' => 10, 'shadowColor' => 'rgba(0,0,0,0.3)']],
            ]],
        ];

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title, 'left' => 'center'];
        }

        return $option;
    }

    /**
     * Teto "bonito" com folga pros indicators do radar. Max ÚNICO global
     * (todos os eixos na mesma escala — polígonos comparáveis entre si).
     */
    private function radarNiceMax(float $max): float
    {
        if ($max <= 0) {
            return 1.0; // max 0 quebra o layout do radar no ECharts
        }
        $max *= 1.05;
        $mag = pow(10, floor(log10($max)));
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $c) {
            if ($c * $mag >= $max) {
                return $c * $mag;
            }
        }
        return 10 * $mag;
    }

    private function buildRadarOptions(): array
    {
        $p   = $this->precision        ?? 2;
        $dec = $this->decimalSeparator  ?? ',';
        $tho = $this->thousandSeparator ?? '.';
        $pre = $this->prefix            ?? '';
        $suf = $this->sufix             ?? '';

        $countGroups    = count($this->fieldGroup);
        $strokes        = $this->strokeColors();
        $hasTransformer = $this->hasDisplayTransformer();
        $n              = $this->name;

        // Categorias → indicators; cada série → um polígono. Valores negativos
        // são CLAMPADOS em 0 (radar ECharts não tem eixo negativo) — o valor
        // raw ainda aparece no tooltip quando há transformer (displayValues).
        $categories   = array_values(array_unique(array_column($this->data, 0)));
        $catFormatted = [];
        foreach ($categories as $c) {
            $catFormatted[] = $this->transformerLegend
                ? (string) call_user_func($this->transformerLegend, $c, $c, $this->data)
                : (string) $c;
        }

        $seriesMap        = [];
        $displayValuesMap = [];

        if ($countGroups > 1) {
            // ── Multi-series (2 dims): N polígonos ───────────────────────────
            foreach ($this->data as $item) {
                $catKey     = $item[0];
                $seriesName = (string) $item[$countGroups - 1];
                $rawValue   = (float) $item[$countGroups];

                if ($this->transformerSubLegend) {
                    $seriesName = (string) call_user_func($this->transformerSubLegend, $seriesName, $item, $this->data);
                }

                if (!isset($seriesMap[$seriesName])) {
                    $seriesMap[$seriesName]        = array_fill(0, count($categories), 0.0);
                    $displayValuesMap[$seriesName] = array_fill(0, count($categories), null);
                }

                $pos = array_search($catKey, $categories);
                if ($pos !== false) {
                    $seriesMap[$seriesName][$pos] = max(0.0, $rawValue);
                    if ($hasTransformer) {
                        $displayValuesMap[$seriesName][$pos] = $this->resolveDisplayValue($rawValue, $item);
                    }
                }
            }

            // Ordem DETERMINÍSTICA (alfabética) — mesma razão do bar multi.
            ksort($seriesMap, SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            // ── Single-series: 1 polígono ────────────────────────────────────
            $seriesName = (string) ($this->title ?: $this->name);
            $seriesMap[$seriesName]        = [];
            $displayValuesMap[$seriesName] = [];
            foreach ($this->data as $item) {
                $rawValue = (float) $item[1];
                $seriesMap[$seriesName][]        = max(0.0, $rawValue);
                $displayValuesMap[$seriesName][] = $hasTransformer ? $this->resolveDisplayValue($rawValue, $item) : null;
            }
        }

        $globalMax = 0.0;
        foreach ($seriesMap as $values) {
            foreach ($values as $v) {
                $globalMax = max($globalMax, (float) $v);
            }
        }
        $niceMax = $this->radarNiceMax($globalMax);

        $indicators = [];
        foreach ($catFormatted as $label) {
            $indicators[] = ['name' => $label, 'max' => $niceMax];
        }

        $seriesData = [];
        $i = 0;
        foreach ($seriesMap as $name => $values) {
            $entry = [
                'value'     => array_values($values),
                'name'      => $name,
                'areaStyle' => ['opacity' => 0.15],
            ];
            if (!empty($strokes[$i])) {
                $entry['itemStyle'] = ['color' => $strokes[$i]];
                $entry['lineStyle'] = ['color' => $strokes[$i], 'width' => 2];
            }
            $seriesData[] = $entry;
            $i++;
        }

        // Labels dos indicators viajam pelo canal __dv (p.value do radar é só
        // o vetor de números — o formatter precisa dos nomes das dimensões).
        $this->ecDisplayValues = ['__labels' => $catFormatted]
            + ($hasTransformer ? $displayValuesMap : []);

        if ($hasTransformer) {
            $tooltipFmt = "::function(p) { var L = (__dv_{$n} && __dv_{$n}.__labels) || []; var D = __dv_{$n}[p.name]; var v = p.value || []; var r = p.name + '<br/>'; for (var i = 0; i < v.length; i++) { var dv = (D && D[i] !== null && D[i] !== undefined) ? D[i] : v[i]; r += (L[i] !== undefined ? L[i] : i) + ': ' + dv + '<br/>'; } return r; }::";
        } else {
            $tooltipFmt = "::function(p) { var L = (__dv_{$n} && __dv_{$n}.__labels) || []; var v = p.value || []; var r = p.name + '<br/>'; for (var i = 0; i < v.length; i++) { r += (L[i] !== undefined ? L[i] : i) + ': {$pre}' + number_format(v[i], '{$p}', '{$dec}', '{$tho}') + '{$suf}<br/>'; } return r; }::";
        }

        $legendPos = ($this->positionLegend === 'right')
            ? ['right' => 0, 'top' => 'middle', 'orient' => 'vertical']
            : ['bottom' => 0, 'orient' => 'horizontal'];

        $option = [
            'tooltip' => ['trigger' => 'item', 'formatter' => $tooltipFmt],
            'legend'  => array_merge(
                ['show' => ($countGroups > 1) ? (bool) $this->legend : false],
                $legendPos
            ),
            'radar'   => ['indicator' => $indicators, 'radius' => '65%'],
            'series'  => [[
                'type'       => 'radar',
                'symbol'     => 'circle',
                'symbolSize' => 4,
                'data'       => $seriesData,
            ]],
        ];

        if ($this->title && !$this->showPanel) {
            $option['title'] = ['text' => $this->title, 'left' => 'center'];
        }

        return $option;
    }

    /**
     * Misto (coluna+linha): transforma a option de bar. Modo A converte séries
     * do group-by marcadas ($mixedLine) em type line; modo B apenda a série da
     * 2ª métrica ($lineMetric, valores em $lineMetricValues). Roda ANTES do
     * merge de customOptions — o caller ainda sobrescreve o que quiser.
     */
    private function applyMixedSeries(array $option): array
    {
        $strokes       = $this->strokeColors();
        $secondaryUsed = false;

        // ── Modo A: séries nomeadas/indexadas viram linha ────────────────────
        if (is_array($this->mixedLine)) {
            $targets = [];
            foreach (($this->mixedLine['series'] ?? []) as $t) {
                $targets[] = is_int($t) ? $t : mb_strtolower(trim((string) $t));
            }
            $secondary = (bool) ($this->mixedLine['secondary'] ?? false);

            foreach (($option['series'] ?? []) as $i => $s) {
                $sName = mb_strtolower(trim((string) ($s['name'] ?? '')));
                if (!in_array($i, $targets, true) && ($sName === '' || !in_array($sName, $targets, true))) {
                    continue;
                }
                $s['type'] = 'line';
                unset($s['stack']); // linha nunca empilha com as colunas
                $s['smooth']     = true;
                $s['z']          = 3; // na FRENTE das barras
                $s['symbol']     = 'circle';
                $s['symbolSize'] = 6;
                if (!empty($strokes[$i])) {
                    // stroke sólido — o fill claro do bar multi é ilegível como linha
                    $s['itemStyle'] = ['color' => $strokes[$i]];
                    $s['lineStyle'] = ['color' => $strokes[$i], 'width' => 2];
                }
                if ($secondary) {
                    $s['yAxisIndex'] = 1;
                    $secondaryUsed   = true;
                }
                $option['series'][$i] = $s;
            }
        }

        // ── Modo B: 2ª métrica agregada apendada como linha ──────────────────
        if (is_array($this->lineMetric) && $this->lineMetricValues !== []) {
            $values = [];
            for ($i = 0, $c = count($this->data); $i < $c; $i++) {
                $values[] = $this->lineMetricValues[$i] ?? 0.0;
            }

            $label = (string) ($this->lineMetric['label'] ?? '');
            if ($label === '') {
                $label = $this->lineMetric['total'] . '(' . ($this->lineMetric['field'] ?? '*') . ')';
            }

            $ci     = count($option['series'] ?? []);
            $stroke = $strokes ? ($strokes[$ci % count($strokes)] ?? null) : null;

            $line = [
                'name'       => $label,
                'type'       => 'line',
                'data'       => $values,
                'smooth'     => true,
                'z'          => 3,
                'symbol'     => 'circle',
                'symbolSize' => 6,
            ];
            if ($stroke) {
                $line['itemStyle'] = ['color' => $stroke];
                $line['lineStyle'] = ['color' => $stroke, 'width' => 2];
            }
            if (!empty($this->lineMetric['secondary'])) {
                $line['yAxisIndex'] = 1;
                $secondaryUsed      = true;
            }

            // Bar single-series não tem name — dá um pra legenda/tooltip.
            if (isset($option['series'][0]) && empty($option['series'][0]['name'])) {
                $option['series'][0]['name'] = (string) ($this->title ?: $this->name);
            }
            $option['series'][] = $line;
            // top: 0 explícito — o default do ECharts v6 é bottom, que colide
            // com os labels do eixo X (grid bottom 3%).
            $option['legend'] = ['show' => (bool) $this->legend, 'top' => 0];

            // Tooltip do bar single só lê params[0]; com 2 séries usa o multi.
            $p   = $this->precision        ?? 2;
            $dec = $this->decimalSeparator  ?? ',';
            $tho = $this->thousandSeparator ?? '.';
            $pre = $this->prefix            ?? '';
            $suf = $this->sufix             ?? '';
            $n   = $this->name;
            if ($this->hasDisplayTransformer()) {
                // __dv é array FLAT (single-series) — vale só pra série 0 (bar);
                // a linha (2ª métrica) mostra number_format do valor raw.
                $tooltipFmt = "::function(params) { var r = params[0].axisValue + '<br/>'; params.forEach(function(p) { if (p.value === null || p.value === undefined) return; var val; if (p.seriesIndex === 0) { var dv = __dv_{$n}[p.dataIndex]; val = (dv !== null && dv !== undefined) ? dv : p.value; } else { val = number_format(p.value, '{$p}', '{$dec}', '{$tho}'); } r += p.marker + p.seriesName + ': ' + val + '<br/>'; }); return r; }::";
            } else {
                $tooltipFmt = "::function(params) { var r = params[0].axisValue + '<br/>'; params.forEach(function(p) { if (p.value === null || p.value === undefined) return; r += p.marker + p.seriesName + ': {$pre}' + number_format(p.value, '{$p}', '{$dec}', '{$tho}') + '{$suf}<br/>'; }); return r; }::";
            }
            $option['tooltip'] = ['trigger' => 'axis', 'formatter' => $tooltipFmt];
        }

        // Eixo Y secundário (mixed é sempre vertical — valor no yAxis).
        if ($secondaryUsed && isset($option['yAxis']) && is_array($option['yAxis']) && !array_is_list($option['yAxis'])) {
            $option['yAxis'] = [$option['yAxis'], $this->buildValueAxis()];
        }

        return $option;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public show() — completely overrides BChart::show()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Constrói as opções ECharts e retorna um MadChartConfig para uso no componente Blade.
     * Espelha o fluxo de show(), mas retorna dados em vez de fazer output.
     */
    /**
     * Returns the raw rows loaded from DB (after create()/loadData()).
     *
     * Format depends on number of group dimensions:
     *   Single-dim:  [[label_raw, value], ...]
     *   Multi-dim:   [[cat_raw, series_raw, value], ...]
     *
     * Used by <mad-db-chart> click-to-filter to map params.dataIndex →
     * raw label/series value (params.name carries the transformed label
     * when legend-format or transformerLegend is applied).
     */
    public function getData(): array
    {
        return is_array($this->data) ? $this->data : [];
    }

    /**
     * Number of group-by dimensions (1 = single-series, 2 = multi-series).
     */
    public function getGroupDimensions(): int
    {
        return is_array($this->fieldGroup) ? count($this->fieldGroup) : 1;
    }

    /**
     * Barras ANINHADAS ("stacked internal"): séries sobrepostas e
     * CENTRALIZADAS no mesmo slot do eixo de categoria — maior série mais
     * LARGA e ATRÁS, menor mais estreita e na FRENTE (boneca russa).
     *
     * ECharts não faz isso nativo: barGap negativo NÃO centraliza (o barGap
     * da última série vale pro eixo INTEIRO e '-100%' só alinha as bordas
     * esquerdas). O jeito certo é 1 eixo de categoria ESCONDIDO por série —
     * cada eixo faz layout próprio e centraliza a única barra dele no slot.
     *
     * A escada de larguras (% do slot; default 72% e ~85% do nível anterior
     * por camada) é atribuída por RANKING DO MAIOR VALOR de cada série —
     * largura fixa por posição quebra quando a ordem de grandeza dos dados
     * muda (série menor porém mais larga fica presa ATRÁS de uma maior
     * estreita e só as bordas laterais aparecem). barMaxWidth (= % de
     * capBand px) segura eixo com poucas categorias sem perder as
     * proporções; borderRadius 0 porque canto arredondado abre fresta entre
     * as camadas.
     */
    private function applyNestedBars(array $option): array
    {
        $series  = $option['series'] ?? [];
        $n       = count($series);
        $axisKey = ($this->barDirection === 'horizontal') ? 'yAxis' : 'xAxis';

        // Só multi-serie com eixo de categoria ainda em forma de objeto único.
        if ($n < 2 || !isset($option[$axisKey]) || !is_array($option[$axisKey]) || array_is_list($option[$axisKey])) {
            return $option;
        }

        $cfg    = is_array($this->barNested) ? $this->barNested : [];
        $cap    = (int) ($cfg['cap'] ?? 0) ?: 110;
        $ladder = $cfg['widths'] ?? null;
        if (!is_array($ladder) || $ladder === []) {
            $ladder = [];
            $w      = 72.0;
            for ($i = 0; $i < $n; $i++) {
                $ladder[] = (int) round($w);
                $w *= 0.85;
            }
        }
        $ladder = array_values($ladder);
        rsort($ladder);

        // Ranking por maior valor (desc); desempate pela ordem original.
        $maxes = [];
        foreach ($series as $i => $s) {
            $vals = [];
            foreach (($s['data'] ?? []) as $v) {
                $v = is_array($v) ? ($v['value'] ?? null) : $v;
                if (is_numeric($v)) {
                    $vals[] = (float) $v;
                }
            }
            $maxes[$i] = $vals === [] ? 0.0 : max($vals);
        }
        $order = array_keys($maxes);
        usort($order, fn ($a, $b) => ($maxes[$b] <=> $maxes[$a]) ?: ($a <=> $b));

        // 1 eixo de categoria por série (extras escondidos, layout próprio).
        $baseAxis = $option[$axisKey];
        $axes     = [$baseAxis];
        for ($i = 1; $i < $n; $i++) {
            $axes[] = [
                'show'      => false,
                'axisTick'  => ['show' => false],
                'axisLabel' => ['show' => false],
                'axisLine'  => ['show' => false],
            ] + $baseAxis;
        }
        $option[$axisKey] = $axes;

        $axisIdxKey = ($axisKey === 'yAxis') ? 'yAxisIndex' : 'xAxisIndex';
        foreach ($order as $rank => $i) {
            $w = $ladder[$rank] ?? end($ladder);

            $series[$i][$axisIdxKey]   = $i;
            $series[$i]['barWidth']    = $w . '%';
            $series[$i]['barMaxWidth'] = max(1, (int) round($cap * $w / 100));
            $series[$i]['z']           = 2 + $rank;
            $series[$i]['itemStyle']   = ['borderRadius' => 0] + (array) ($series[$i]['itemStyle'] ?? []);
        }
        $option['series'] = $series;

        return $option;
    }

    public function toConfig(): \Mad\Chart\MadChartConfig
    {
        if (!$this->loaded) {
            $this->create();
        }

        $this->ecDisplayValues = null;

        if (in_array($this->type, ['pie', 'donut'])) {
            $option = $this->buildPieOptions();
        } elseif ($this->type === 'rose') {
            $option = $this->buildRoseOptions();
        } elseif ($this->type === 'funnel') {
            $option = $this->buildFunnelOptions();
        } elseif ($this->type === 'treemap') {
            $option = $this->buildTreemapOptions();
        } elseif ($this->type === 'radar') {
            $option = $this->buildRadarOptions();
        } elseif ($this->type === 'bar' || $this->type === 'mixed') {
            $option = $this->buildBarOptions();
            if ($this->type === 'mixed') {
                $option = $this->applyMixedSeries($option);
            }
        } else {
            $option = $this->buildLineOptions();
        }

        if ($this->customOptions) {
            $option = array_replace_recursive($option, $this->customOptions);
        }

        // Depois do merge de customOptions: options do caller ainda enxergam
        // o xAxis/yAxis como objeto único (o transform troca por LISTA).
        if ($this->barNested && $this->type === 'bar') {
            $option = $this->applyNestedBars($option);
        }

        // JSON_UNESCAPED_SLASHES é obrigatório — ver comentário em show()
        $optJson = json_encode($option, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $optJson = str_replace('"::', '', $optJson);
        $optJson = str_replace('::"', '', $optJson);

        $dvJson = json_encode($this->ecDisplayValues ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new \Mad\Chart\MadChartConfig(
            name:              $this->name,
            optionsJson:       $optJson,
            displayValuesJson: $dvJson,
            title:             (string) ($this->title    ?? ''),
            subtitle:          (string) ($this->subtitle ?? ''),
            width:             (string) ($this->width    ?? '100%'),
            height:            (int)    ($this->height   ?? 300),
            hasData:           !empty($this->data),
            showPanel:         (bool)   ($this->showPanel ?? true),
            sql:               $this->getLastSql(),
            sqlBinds:          $this->getLastSqlBinds(),
            sqlInlined:        $this->getLastSqlInlined(),
            sqlError:          $this->getLastSqlError(),
        );
    }
}
