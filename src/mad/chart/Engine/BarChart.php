<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts bar chart widget.
 *
 * Drop-in ECharts replacement for BBarChart.
 * Supports single and grouped (multi-series) bars, horizontal layout, and stacking.
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class BarChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, array $fieldGroup = [], ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('bar');
        $this->grid = true;
    }

    /** Set bar direction: 'horizontal' or 'vertical' (default). */
    public function setLayout($direction)
    {
        $this->barDirection = $direction;
    }

    /** Enable stacked bars. */
    public function setStack($stack = true)
    {
        $this->barStack = $stack;
    }

    /**
     * Bar ANINHADO ("stacked internal"): séries sobrepostas e CENTRALIZADAS
     * no mesmo slot — a série de maior valor fica mais larga e ATRÁS, a menor
     * mais estreita e na FRENTE (boneca russa; subconjunto aparece DENTRO do
     * total). Ver EChart::applyNestedBars().
     *
     * @param array|null $widths  escada de larguras em % do slot (ordem
     *                            irrelevante — aplicada do maior valor pro
     *                            menor); null = escada automática (72%, ~85%
     *                            do nível anterior por camada)
     * @param int|null   $capBand banda máxima px pro teto de largura
     *                            (barMaxWidth = % de capBand; default 110)
     */
    public function setStackInternal(?array $widths = null, ?int $capBand = null)
    {
        $this->barNested = ['widths' => $widths, 'cap' => $capBand];
    }

    /** Transformer for sub-legend labels (multi-series). */
    public function setTransformerSubLegend(callable $transformer)
    {
        $this->transformerSubLegend = $transformer;
    }

    /** Label shown on the value axis. */
    public function setLabelValue($labelValue)
    {
        $this->labelValue = $labelValue;
    }

    /** Show or hide the grid. */
    public function showGrid($showGrid = true)
    {
        $this->grid = $showGrid;
    }
}
