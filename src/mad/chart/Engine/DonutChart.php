<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts donut chart widget.
 *
 * Drop-in ECharts replacement for BDonutChart.
 * Renders as a pie chart with inner radius (40%–70%).
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class DonutChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, String $fieldGroup = '', ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('donut');
    }

    public function setFieldGroup($fieldGroup)
    {
        parent::setFieldGroup([$fieldGroup]);
    }
}
