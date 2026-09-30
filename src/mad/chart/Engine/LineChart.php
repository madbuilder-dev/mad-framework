<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts line chart widget.
 *
 * Drop-in ECharts replacement for BLineChart.
 * Supports single and multi-series lines, area fill, and smooth curves.
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class LineChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, array $fieldGroup = [], ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('line');
    }

    /** Transformer for sub-legend labels (multi-series). */
    public function setTransformerSubLegend(callable $transformer)
    {
        $this->transformerSubLegend = $transformer;
    }

    /** Show or hide the grid. */
    public function showGrid($showGrid = true)
    {
        $this->grid = $showGrid;
    }

    /** Label shown on the value axis. */
    public function setLabelValue($labelValue)
    {
        $this->labelValue = $labelValue;
    }

    /**
     * Enable area fill under the line.
     *
     * @param bool $areaRounded Use smooth/spline curve.
     */
    public function showArea($areaRounded = true)
    {
        $this->area        = true;
        $this->areaRounded = $areaRounded;
    }
}
