<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts Nightingale/Rose chart widget.
 *
 * Pie variant where each slice radius is proportional to its value,
 * making it easier to compare magnitudes visually.
 * Drop-in replacement with the same API as BEPieChart.
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class RoseChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, String $fieldGroup = '', ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('rose');
    }

    public function setFieldGroup($fieldGroup)
    {
        parent::setFieldGroup([$fieldGroup]);
    }
}
