<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;



/**
 * ECharts Funnel chart widget.
 *
 * Ideal for sales pipelines and process stages.
 * Slices are sorted descending by value; same group+value API as pie/donut.
 *
 * @version    1.0
 * @package    widget
 * @subpackage builder
 * @author     Matheus Agnes Dias
 */
class FunnelChart extends EChart
{
    public function __construct(String $name, ?String $database = null, ?String $model = null, String $fieldGroup = '', ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {
        parent::__construct($name, $database, $model, [], $fieldValue, $joins, $totalChart);
        $this->setFieldGroup($fieldGroup);
        $this->setType('funnel');
    }

    public function setFieldGroup($fieldGroup)
    {
        parent::setFieldGroup([$fieldGroup]);
    }
}
