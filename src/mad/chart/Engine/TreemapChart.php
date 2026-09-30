<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts Treemap chart widget.
 *
 * Displays hierarchical data as nested rectangles proportional to value.
 * Great for product/category distribution at a glance.
 * Same group+value API as pie/donut.
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class TreemapChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, String $fieldGroup = '', ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('treemap');
    }

    public function setFieldGroup($fieldGroup)
    {
        parent::setFieldGroup([$fieldGroup]);
    }
}
