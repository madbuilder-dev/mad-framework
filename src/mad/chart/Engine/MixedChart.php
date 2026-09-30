<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts mixed (column + line) chart widget.
 *
 * Renders as a bar chart with selected series drawn as lines on top.
 * Two modes (mutually exclusive by construction):
 *   A) setSeriesLine([...])  — 2-dim group-by; named/indexed series become
 *      lines (classic: stacked revenue columns + goal line).
 *   B) setLineMetric(...)    — 1-dim group-by; a SECOND aggregation becomes
 *      the line series (classic: count as columns + avg as line).
 *
 * horizontal/stacked-internal do not apply (mixed is always vertical bars).
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class MixedChart extends BarChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, array $fieldGroup = [], ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, $fieldGroup, $fieldValue, $joins, $totalChart);
        $this->setType('mixed');
    }

    /**
     * Modo A: séries do group-by (2ª dimensão) que renderizam como LINHA
     * sobre as colunas. Ver EChart::applyMixedSeries().
     *
     * @param array $series nomes (match case-insensitive contra o nome final
     *                      da série, pós sub-legend-transformer) ou índices
     *                      int (posição na ordem alfabética/ksort das séries)
     * @param bool  $secondaryAxis linha usa eixo Y secundário (direita)
     */
    public function setSeriesLine(array $series, bool $secondaryAxis = false)
    {
        $this->mixedLine = ['series' => $series, 'secondary' => $secondaryAxis];
    }

    /**
     * Modo B: 2ª métrica agregada como série de LINHA (group-by 1 dimensão).
     * A agregação roda no MESMO select do loadData(). Ver BaseChart::loadData().
     *
     * @param string      $total         sum|max|min|count|avg
     * @param string|null $field         campo agregado (null + count → count(*))
     * @param string|null $label         nome da série na legenda/tooltip
     * @param bool        $secondaryAxis linha usa eixo Y secundário (direita)
     */
    public function setLineMetric(string $total, ?string $field, ?string $label = null, bool $secondaryAxis = false)
    {
        $this->lineMetric = ['total' => $total, 'field' => $field, 'label' => $label, 'secondary' => $secondaryAxis];
    }
}
