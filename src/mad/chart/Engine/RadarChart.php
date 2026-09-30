<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts radar chart widget.
 *
 * Categories become radar indicators (spokes); each series becomes one
 * polygon. Supports single (1 group dimension) and multi-series (2 dims).
 * Negative values are clamped to 0 (ECharts radar has no negative axis).
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class RadarChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, array $fieldGroup = [], ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('radar');
    }

    /** Transformer for sub-legend labels (multi-series). */
    public function setTransformerSubLegend(callable $transformer)
    {
        $this->transformerSubLegend = $transformer;
    }
}
