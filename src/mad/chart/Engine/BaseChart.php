<?php
namespace Mad\Chart\Engine;

use Exception;
use stdClass;

/**
 * Abstract class BChart
 *
 * This class represents a base chart widget that can be used to generate 
 * different types of charts using data from a database.
 *
 * @version    4.1
 * @package    widget
 * @subpackage builder
 * @author     Lucas Tomasi
 * @author     Matheus Agnes Dias
 */
abstract class BaseChart
{
    private $options;
    private $formatsTooltip;

    protected $type;
    protected $name;
    protected $database;
    protected $model;
    protected $fieldGroup;
    protected $fieldValue;
    protected $fieldColor;
    protected $joins;
    protected $totalChart;
    protected $baseQuery = null;
    protected $html;
    protected $title;
    protected $subtitle;
    protected $legend;
    protected $percentage;
    protected $height;
    protected $width;
    protected $displayFunction;
    protected $tooltipFunction;
    protected $barDirection;
    protected $colors;
    protected $labelValue;
    protected $rotateLegend;
    protected $legendHeight;
    protected $grid;
    protected $showPanel;
    protected $area;
    protected $customClass;
    protected $transformerValue;
    protected $transformerLabelValue;
    protected $transformerLegend;
    protected $transformerSubLegend;
    protected $customOptions;
    protected $data;
    protected $loaded;
    protected $lastSql;
    protected $lastSqlBinds;
    protected $lastSqlError;
    protected $zoom;
    protected $areaRounded;
    protected $orderByValue;
    protected $legendsLimitShow;
    protected $legendsPosition;
    protected $abbreviatedValues;
    protected $barStack;
    /** Bar aninhado ("stacked internal"): null | ['widths' => ?array, 'cap' => ?int]. */
    protected $barNested;
    /** Mixed (coluna+linha) modo A: null | ['series' => array, 'secondary' => bool]. */
    protected $mixedLine;
    /** Mixed modo B (2ª métrica): null | ['total' => string, 'field' => ?string, 'label' => ?string, 'secondary' => bool]. */
    protected $lineMetric;
    /** Valores da 2ª métrica, alinhados por índice de row de $data. */
    protected $lineMetricValues = [];
    protected $showMethods;
    protected $culling;

    // Formatação numérica (setDisplayNumeric). Declaradas aqui porque o setter
    // vive nesta classe e os leitores (EChart e subclasses) usam `?? default` —
    // sem declaração cada set virava propriedade dinâmica e o PHP 8.2 emitia
    // "Creation of dynamic property …::$prefix is deprecated" a cada chart.
    // O default null preserva o `??` de todos os call-sites.
    protected $precision;
    protected $decimalSeparator;
    protected $thousandSeparator;
    protected $prefix;
    protected $sufix;

    /** Posição da legenda ('bottom' | 'right') — setada em showLegend(), lida no EChart. */
    protected $positionLegend;

    /**
     * BChart constructor.
     *
     * Initializes the chart with the necessary configurations and default settings.
     *
     * @param string      $name        The name of the chart.
     * @param string|null $database    The database name used to fetch data.
     * @param string|null $model       The model class name associated with the chart.
     * @param array       $fieldGroup  The fields used for grouping data in the chart.
     * @param string|null $fieldValue  The field used for calculating totals.
     * @param array       $joins       An array of joins to be used in the query.
     * @param string      $totalChart  The aggregation type (sum, max, min, count, avg).
     *
     * @throws Exception If an invalid parameter is provided.
     */
    public function __construct(String $name, ?String $database = null, ?String $model = null, array $fieldGroup = [], ?String $fieldValue = null, array $joins = [], $totalChart = 'sum')
    {

        $this->name = $name;
        $this->showMethods = [];
        $this->setDatabase($database);
        $this->setModel($model);
        $this->setFieldGroup($fieldGroup);
        $this->setFieldValue($fieldValue);
        $this->setTotal($totalChart);
        $this->setJoins($joins);

        $this->showLegend();
        $this->showPercentage(FALSE);
        $this->hidePanel(FALSE);
        $this->setSize('100%', 300);

        $this->setDisplayNumeric();
        $this->culling = true;

        $this->positionLegend = 'bottom';
        $this->abbreviatedValues = false;
        $this->grid = false;
        $this->area = false;
        $this->areaRounded = false;
        $this->loaded = false;
        $this->zoom = true;
        $this->orderByValue = false;
        $this->colors = [
            [
                'stroke' => '#2563EB', // Azul royal
                'fill' => '#DBEAFE'    // Azul claro
            ],
            [
                'stroke' => '#16A34A', // Verde corporativo
                'fill' => '#DCFCE7'    // Verde claro
            ],
            [
                'stroke' => '#EA580C', // Laranja corporativo
                'fill' => '#FFEDD5'    // Laranja claro
            ],
            [
                'stroke' => '#9333EA', // Roxo empresarial
                'fill' => '#F3E8FF'    // Roxo claro
            ],
            [
                'stroke' => '#0891B2', // Azul petróleo
                'fill' => '#CFFAFE'    // Azul turquesa claro
            ],
            [
                'stroke' => '#CC0000', // Vermelho corporativo
                'fill' => '#FEE2E2'    // Vermelho claro
            ],
            [
                'stroke' => '#0369A1', // Azul marinho
                'fill' => '#E0F2FE'    // Azul céu claro
            ],
            [
                'stroke' => '#B45309', // Marrom amber
                'fill' => '#FEF3C7'    // Amarelo claro
            ],
            [
                'stroke' => '#059669', // Verde esmeralda
                'fill' => '#D1FAE5'    // Verde esmeralda claro
            ],
            [
                'stroke' => '#4F46E5', // Indigo vibrante
                'fill' => '#E0E7FF'    // Indigo claro
            ],
            [
                'stroke' => '#BE185D', // Magenta escuro
                'fill' => '#FCE7F3'    // Rosa claro
            ],
            [
                'stroke' => '#0284C7', // Azul cerúleo
                'fill' => '#BAE6FD'    // Azul cerúleo claro
            ],
            [
                'stroke' => '#7C2D12', // Terracota
                'fill' => '#FFEDD5'    // Terracota claro
            ],
            [
                'stroke' => '#475569', // Slate moderno
                'fill' => '#F1F5F9'    // Slate claro
            ],
            [
                'stroke' => '#86198F', // Fúcsia profundo
                'fill' => '#FAE8FF'    // Fúcsia claro
            ],
            [
                'stroke' => '#155E75', // Teal profundo
                'fill' => '#ECFEFF'    // Teal claro
            ],
            [
                'stroke' => '#1E40AF', // Azul cobalto
                'fill' => '#DBEAFE'    // Azul cobalto claro
            ],
            [
                'stroke' => '#65A30D', // Verde oliva
                'fill' => '#ECFCCB'    // Verde oliva claro
            ],
            [
                'stroke' => '#DC2626', // Vermelho vibrante
                'fill' => '#FECACA'    // Vermelho vibrante claro
            ],
            [
                'stroke' => '#7C3AED', // Violeta corporativo
                'fill' => '#EDE9FE'    // Violeta claro
            ],
            [
                'stroke' => '#0F766E', // Verde água profundo
                'fill' => '#CCFBF1'    // Verde água claro
            ],
            [
                'stroke' => '#C2410C', // Laranja queimado
                'fill' => '#FED7AA'    // Laranja queimado claro
            ],
            [
                'stroke' => '#1E3A8A', // Azul safira
                'fill' => '#DBEAFE'    // Azul safira claro
            ],
            [
                'stroke' => '#374151', // Cinza carbono
                'fill' => '#F9FAFB'    // Cinza carbono claro
            ],
            [
                'stroke' => '#991B1B', // Vermelho bordô
                'fill' => '#FEE2E2'    // Vermelho bordô claro
            ],
            [
                'stroke' => '#581C87', // Roxo imperial
                'fill' => '#F3E8FF'    // Roxo imperial claro
            ],
            [
                'stroke' => '#166534', // Verde floresta
                'fill' => '#DCFCE7'    // Verde floresta claro
            ],
            [
                'stroke' => '#B91C1C', // Carmesim
                'fill' => '#FECACA'    // Carmesim claro
            ],
            [
                'stroke' => '#1F2937', // Grafite
                'fill' => '#F3F4F6'    // Grafite claro
            ],
            [
                'stroke' => '#7E22CE', // Púrpura real
                'fill' => '#F3E8FF'    // Púrpura claro
            ],
            [
                'stroke' => '#0D9488', // Turquesa profundo
                'fill' => '#CCFBF1'    // Turquesa claro
            ],
            [
                'stroke' => '#DC4A03', // Laranja cobre
                'fill' => '#FFF7ED'    // Laranja cobre claro
            ],
            [
                'stroke' => '#1D4ED8', // Azul elétrico
                'fill' => '#E0E7FF'    // Azul elétrico claro
            ],
            [
                'stroke' => '#92400E', // Caramelo
                'fill' => '#FEF3C7'    // Caramelo claro
            ],
            [
                'stroke' => '#059212', // Verde grama
                'fill' => '#D1FAE5'    // Verde grama claro
            ],
            [
                'stroke' => '#A21CAF', // Magenta intenso
                'fill' => '#FAE8FF'    // Magenta claro
            ],
            [
                'stroke' => '#0F4C75', // Azul aço
                'fill' => '#EFF6FF'    // Azul aço claro
            ],
            [
                'stroke' => '#92400E', // Bronze
                'fill' => '#FEF3C7'    // Bronze claro
            ],
            [
                'stroke' => '#14532D', // Verde militar
                'fill' => '#F0FDF4'    // Verde militar claro
            ],
            [
                'stroke' => '#7C2D12', // Mogno
                'fill' => '#FFEDD5'    // Mogno claro
            ],
            [
                'stroke' => '#4338CA', // Azul rei
                'fill' => '#E0E7FF'    // Azul rei claro
            ],
            [
                'stroke' => '#0C4A6E', // Azul petroleo escuro
                'fill' => '#E0F2FE'    // Azul petroleo claro
            ],
            [
                'stroke' => '#B45309', // Âmbar profundo
                'fill' => '#FFFBEB'    // Âmbar claro
            ],
            [
                'stroke' => '#701A75', // Vinho
                'fill' => '#FAE8FF'    // Vinho claro
            ],
            [
                'stroke' => '#0F7A0F', // Verde pinho
                'fill' => '#F0FDF4'    // Verde pinho claro
            ],
            [
                'stroke' => '#DC1C13', // Escarlate
                'fill' => '#FEE2E2'    // Escarlate claro
            ],
            [
                'stroke' => '#374151', // Aço escovado
                'fill' => '#F9FAFB'    // Aço claro
            ],
            [
                'stroke' => '#5B21B6', // Ametista
                'fill' => '#F3E8FF'    // Ametista claro
            ],
            [
                'stroke' => '#047857', // Jade
                'fill' => '#D1FAE5'    // Jade claro
            ],
            [
                'stroke' => '#EA5A00', // Tangerina
                'fill' => '#FFEDD5'    // Tangerina claro
            ],
            [
                'stroke' => '#1E293B', // Chumbo
                'fill' => '#F1F5F9'    // Chumbo claro
            ],
            [
                'stroke' => '#BE123C', // Cereja
                'fill' => '#FFE4E6'    // Cereja claro
            ],
            [
                'stroke' => '#6366F1', // Íris
                'fill' => '#E0E7FF'    // Íris claro
            ]
        ];

    }

    /**
     * Generates and processes multiple charts by handling database transactions.
     *
     * @param BChart[] ...$charts A variable-length list of chart instances.
     *
     * @throws Exception If an invalid parameter is provided.
     */
    public static function generate(...$charts)
    {
        $chartsDBs = [];
        
        foreach($charts as $chart)
        {
            if (! $chart->canDisplay())
            {
                continue;
            }

            if (! $chart instanceof BTableChart && ! $chart instanceof BIndicator && ! ($chart instanceof BChart || $chart instanceof BMadTable))
            {
                throw new Exception("Invalid parameter (" . 'charts' . ") in " . __METHOD__);
            }

            $db = $chart->getDatabase();

            if ( empty($db) )
            {
                continue;
            }

            if ( empty($chartsDBs[$db]) )
            {
                $chartsDBs[$db] = [];
            }

            $chartsDBs[$db][] = $chart;
        }


        foreach($chartsDBs as $db => $charts)
        {
            foreach($charts as $chart)
            {
                $chart->create();
            }
        }
    }
    
    public function enableAbbreviatedValues()
    {
        $this->abbreviatedValues = true;

        $this->setTransformerLabelValue(function($value)
        {
            if(!$value)
            {
                $value = 0;
            }

            if(is_numeric($value))
            {
                if ($value >= 1_000_000_000) {
                    $value = round($value / 1_000_000_000,0) . 'B';
                } elseif ($value >= 1_000_000) {
                    $value = round($value / 1_000_000,0) . 'M';
                } elseif ($value >= 1_000) {
                    $value = round($value/ 1_000,0) . 'K';
                }
                return $value;
            }
            else
            {
                return $value;
            }
        });
    }

    /**
     * Formats a function name for usage in JavaScript.
     *
     * @param string $nameFunction The function name.
     *
     * @return string The formatted function reference.
     */
    public static function formatFunction($nameFunction)
    {
        return "::{$nameFunction}::";
    }

    /**
     * Checks if the chart is allowed to be displayed.
     *
     * @return bool True if the chart can be displayed, false otherwise.
     */
    public function canDisplay()
    {
        if ($this->showMethods)
        {
            return in_array($_REQUEST['method']??'', $this->showMethods);
        }

        return true;
    }

    public function setCulling($culling)
    {
        $this->culling = $culling;
    }

    public function enableCulling()
    {
        $this->culling = true;
    }

    public function disableCulling()
    {
        $this->culling = false;
    }

    /**
     * Defines which methods are allowed to display the chart.
     *
     * @param array $methods The list of allowed methods.
     */
    public function setShowMethods($methods = [])
    {
        $this->showMethods = $methods;
    }

    /**
     * Retrieves the tooltip formatting function.
     *
     * @return array The tooltip function format.
     */
    private function getTootipFunction()
    {
        if (! $this->tooltipFunction )
        {
            return ["format" => ["value" => "::defaultFormatFunction::"]];
        }

        return ["contents" => "::{$this->tooltipFunction}::"];
    }

    /**
     * Retrieves the display function for formatting values.
     *
     * @return string The function name used for formatting values.
     */
    private function getDisplayFunction()
    {
        if (! $this->displayFunction )
        {
            $this->displayFunction = 'defaultFormatFunction';
        }

        return $this->displayFunction;
    }

    /**
     * Sets the type of the chart.
     *
     * @param string $type The type of chart (pie, line, bar, donut, rose, funnel, treemap, radar, mixed).
     *
     * @throws Exception If an invalid type is provided.
     */
    protected function setType($type)
    {
        if (! in_array($type, ['pie', 'line', 'bar', 'donut', 'rose', 'funnel', 'treemap', 'radar', 'mixed']))
        {
            throw new Exception("Invalid parameter (" . $type . ") in " . __METHOD__);
        }

        $this->type = $type;
    }

    /**
     * Enables ordering of the chart data based on values.
     *
     * @param string $order The order direction ('asc' or 'desc').
     */
    public function enableOrderByValue($order = 'desc')
    {
        $this->orderByValue = $order;
    }

    /**
     * Retrieves the name of the chart.
     *
     * @return string The chart name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets the name of the chart.
     *
     * @param string $name The chart name.
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Sets the display limit for legends.
     *
     * @param int $x The maximum number of legends on the x-axis.
     * @param int $y The maximum number of legends on the y-axis.
     */
    public function setLegendsLimitShow($x, $y)
    {
        $this->legendsLimitShow = [$x, $y];
    }

    /**
     * Sets a transformation function for values.
     *
     * @param callable $transformer The callable function to transform values.
     */
    public function setTransformerValue(callable $transformer)
    {
        $this->transformerValue = $transformer;
    }

    /**
     * Define transformer values
     *
     * @param $transformer callable
     */
    public function setTransformerLabelValue(callable $transformer)
    {
        $this->transformerLabelValue = $transformer;
    }

    /**
     * Sets a transformation function for legends.
     *
     * @param callable $transformer The callable function to transform legends.
     */
    public function setTransformerLegend(callable $transformer)
    {
        $this->transformerLegend = $transformer;
    }

    /**
     * Sets a custom CSS class for the chart.
     *
     * @param string $class The CSS class name.
     */
    public function setCustomClass($class)
    {
        $this->customClass = $class;
    }

    /**
     * Retrieves the database name used by the chart.
     *
     * @return string|null The database name or null if not set.
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * Sets the database name for fetching data.
     *
     * @param string $database The database name.
     */
    public function setDatabase($database)
    {
        $this->database = $database;
    }

    /**
     * Sets the model class for the chart.
     *
     * @param string $model The model class name.
     */
    public function setModel($model)
    {
        $this->model = $model;
    }

    /**
     * Sets the fields used for grouping data in the chart.
     *
     * @param array $fieldGroup The array of field names.
     *
     * @throws Exception If the parameter is invalid.
     */
    public function setFieldGroup($fieldGroup)
    {
        if (! is_array($fieldGroup) || count($fieldGroup) > 2)
        {
            throw new Exception("Invalid parameter (" . 'fieldGroup' . ") in " . __METHOD__);
        }
        $this->fieldGroup = $fieldGroup;
    }

    /**
     * Sets the field used for calculating totals.
     *
     * @param string $fieldValue The field name.
     */
    public function setFieldValue($fieldValue)
    {
        $this->fieldValue = $fieldValue;
    }

    /**
     * Sets the field used for defining chart colors.
     *
     * @param string $fieldColor The field name.
     */
    public function setFieldColor($fieldColor)
    {
        $this->fieldColor = $fieldColor;
    }

    /**
     * Sets database joins for the query.
     *
     * @param array $joins The array of join conditions.
     */
    public function setJoins($joins)
    {
        $this->joins = $joins;
    }

    /**
     * Sets the total calculation method.
     *
     * @param string $totalChart The aggregation type (sum, max, min, count, avg).
     *
     * @throws Exception If an invalid type is provided.
     */
    public function setTotal($totalChart)
    {
        if (! in_array($totalChart, ['sum', 'max', 'min', 'count', 'avg']))
        {
            throw new Exception("Invalid parameter (" . $totalChart . ") in " . __METHOD__);
        }

        $this->totalChart = $totalChart;
    }

    /**
     * Define a fonte via Eloquent/Query Builder pronto (novo padrão, builder-native).
     * Quando setado, loadData() agrega sobre ele em vez do model puro.
     */
    public function setBaseQuery($query): void
    {
        $this->baseQuery = $query;
    }

    /**
     * Sets the title of the chart.
     *
     * @param string $title The chart title.
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * Get subtitle panel
     * @param $subtitle String subtitle
     */
    public function setSubtitle($subtitle)
    {
        $this->subtitle = $subtitle;
    }

    /**
     * Enables or disables percentage display.
     *
     * @param bool $percentage Whether to show percentages.
     */
    public function showPercentage($percentage = true)
    {
        $this->percentage = $percentage;
    }

    /**
     * Hides or shows the chart panel.
     *
     * @param bool $hide Whether to hide the panel.
     */
    public function hidePanel($hide = true)
    {
        $this->showPanel = ! $hide;
    }

    /**
     * Enables or disables the legend display.
     *
     * @param bool $legend Whether to show the legend.
     */
    public function showLegend($legend = true, $positionLegend = 'bottom' | 'right')
    {
        $this->legend = $legend;
        $this->positionLegend = $positionLegend;
    }

    /**
     * Rotates the legend and sets its height.
     *
     * @param int $rotate The rotation angle.
     * @param int $height The height of the legend.
     */
    public function setRotateLegend($rotate, $height = 100)
    {
        $this->rotateLegend = $rotate;
        $this->legendHeight = $height;
    }

    /**
     * Retrieves the chart size.
     *
     * @return null
     */
    public function getSize()
    {
        return null;
    }

    /**
     * Sets the width and height of the chart.
     *
     * @param string|int $width  The width of the chart.
     * @param string|int $height The height of the chart.
     */
    public function setSize($width, $height)
    {
        $height = (strstr($height, '%') !== FALSE) ? $height : "{$height}px";
        $width  = (strstr($width, '%') !== FALSE) ? $width : "{$width}px";

        $this->width = $width;
        $this->height = $height;
    }

    /**
     * Sets the tooltip formatting function.
     *
     * @see https://c3js.org/reference.html#tooltip-contents
     *
     * @param string $function The JavaScript function name.
     */
    public function setTootipFunction($function)
    {
        $this->tooltipFunction = $function;
    }

    /**
     * Sets the display function for formatting values.
     *
     * @see https://c3js.org/reference.html#data-labels-format
     *
     * @param string $function The JavaScript function name.
     */
    public function setDisplayFunction($function)
    {
        $this->displayFunction = $function;
    }

    /**
     * Sets the numeric display format.
     *
     * @param int    $precision         Number of decimal places.
     * @param string $decimalSeparator  The decimal separator.
     * @param string $thousandSeparator The thousands separator.
     * @param string $prefix            A prefix for the values.
     * @param string $sufix             A suffix for the values.
     */
    public function setDisplayNumeric($precision = 2,  $decimalSeparator = ',',  $thousandSeparator = '.',  $prefix = '',  $sufix = '')
    {
        $this->precision = $precision;
        $this->decimalSeparator = $decimalSeparator;
        $this->thousandSeparator = $thousandSeparator;
        $this->prefix = $prefix;
        $this->sufix = $sufix;
    }

    

    private function loadData()
    {
        // Agregação 100% Illuminate Query Builder: WHERE vem da base query
        // (builder/:query/:filters) OU dos joins do model — builder-native.
        $required = ['fieldGroup' => $this->fieldGroup, 'fieldValue' => $this->fieldValue];
        if ($this->baseQuery === null) {
            $required = ['database' => $this->database, 'model' => $this->model] + $required;
        }
        foreach ($required as $p => $v) {
            if (empty($v)) {
                throw new \Exception("The parameter (" . $p . ") of " . __CLASS__ . " is required");
            }
        }

        if ($this->baseQuery !== null) {
            // Fonte = builder pronto. toBaseQuery aplica global scopes (soft-delete)
            // e clona; columns=null pq a agregação substitui o select do builder.
            $q = \Mad\Database\QuerySource::toBaseQuery($this->baseQuery);
            // Aliases do SELECT original TÊM que ser lidos ANTES do descarte —
            // é a única pista de que `categoria_nome` não é coluna da tabela
            // base, e sim apelido de `categorias.nome` vindo de um JOIN.
            $selectAliases = self::selectAliasMap($q);
            $q->columns = null;
            $entity = \Mad\Database\QuerySource::entity($this->baseQuery)
                ?? (is_string($q->from) ? $q->from : '');
        } else {
            // model= sem :query: pelo Eloquent, com os global scopes do model
            // (unidade/tenant do Multi-unidade, soft delete). A tabela crua
            // (DB::table) somava registros de TODAS as unidades num SaaS — e os
            // excluídos. A conexão continua sendo a do `database` do gráfico.
            $q = \Mad\Database\QuerySource::modelBaseQuery($this->model, $this->database);
            $q->columns = null;
            $entity   = is_string($q->from) ? $q->from : (new $this->model)->getTable();
            $selectAliases = [];   // sem query base não há SELECT prévio
        }

        // Resolve alias-do-SELECT ANTES de qualificar com a tabela base.
        //
        // Por que: a agregação joga fora o SELECT da query base (columns = null)
        // e reconstrói o seu. Um group-by que apontava pra um ALIAS criado por
        // JOIN — `->select('categorias.nome as categoria_nome')->groupBy(
        // 'categoria_nome')` — perdia a definição do alias e ainda ganhava o
        // prefixo da tabela base, virando `pedidos.categoria_nome`: coluna que
        // não existe em lugar nenhum (`SQLSTATE ... no such column`). Trocando o
        // alias pela expressão de origem, o SELECT/GROUP BY volta a apontar pra
        // `categorias.nome` — a coluna real, já acessível pelo JOIN da query.
        //
        // O prefixo continua valendo pra coluna simples da tabela base (é o que
        // desambigua `nome` quando há JOIN), e o mapa só carrega aliases cuja
        // expressão passa no guard de injeção — expressão exótica não é
        // substituída, mantendo o comportamento anterior.
        $qualify = static function (string $col) use ($entity, $selectAliases): string {
            $col = $selectAliases[strtolower(trim($col))] ?? $col;

            return ($entity !== '' && strpos($col, '.') === false && strpos($col, '(') === false
                    && strpos($col, ':') === false) ? "{$entity}.{$col}" : $col;
        };

        // Joins reais (no legado viravam WHERE NOESC em FROM a, b). Aplicados em
        // AMBOS os caminhos: um chart com :query base TAMBÉM precisa juntar a
        // tabela do FK usado no group-by — ex.: group-by="sale_status.name" com
        // :joins="['sale_status' => ['sale.sale_status_id','sale_status.id']]".
        // Antes o loop só rodava quando baseQuery===null, então um group-by num
        // FK + :query gerava "no such column sale_status.name" (FROM sem o JOIN).
        foreach (($this->joins ?? []) as $table => $join) {
            if (count($join) > 2) { [$k, $op, $v] = $join; }
            else { [$k, $v] = $join; $op = '='; }
            $q->join($table, $qualify($k), $op, $qualify($v));
        }

        // Anti SQL-injection: field-color/group/value são interpolados em
        // selectRaw/groupByRaw/orderByRaw (identificadores não bindáveis). Valida
        // contra a gramática de expressão (coluna | função segura) — fail-closed.
        $groups = [];
        if ($this->fieldColor) {
            \Mad\Database\OrderGuard::validateExpression($this->fieldColor, 'chart field-color');
            $q->selectRaw("{$this->fieldColor} as color");
            $q->groupByRaw($this->fieldColor);
            $groups[] = $this->fieldColor;
        }

        foreach ($this->fieldGroup as $key => $fieldGroup) {
            \Mad\Database\OrderGuard::validateExpression($fieldGroup, 'chart field-group');
            $expr  = $qualify($fieldGroup);
            $alias = (strpos($fieldGroup, '.') === false && strpos($fieldGroup, '(') === false)
                ? $fieldGroup : "fieldGroup{$key}";
            $q->selectRaw("{$expr} as \"{$alias}\"");
            $q->groupByRaw($expr);
            $groups[] = $expr;
        }

        \Mad\Database\OrderGuard::validateExpression($this->fieldValue, 'chart field-value');
        $valueExpr = $qualify($this->fieldValue);
        $q->selectRaw("{$this->totalChart}({$valueExpr}) as total");

        // Mixed modo B: 2ª métrica agregada (vira série de LINHA). Só faz
        // sentido com 1 dimensão de group-by — com 2 dims a série de linha
        // seria ambígua (uma por combinação categoria×série).
        if ($this->lineMetric) {
            if (count($this->fieldGroup) > 1) {
                throw new Exception('line-metric exige group-by de 1 dimensão; para 2 dimensões use line-series');
            }
            $lt = $this->lineMetric['total'] ?? 'count';
            if (! in_array($lt, ['sum', 'max', 'min', 'count', 'avg'])) {
                throw new Exception("Invalid parameter ({$lt}) in " . __METHOD__ . ' (line-metric total)');
            }
            $lf = $this->lineMetric['field'] ?? null;
            if ($lf !== null && $lf !== '') {
                \Mad\Database\OrderGuard::validateExpression($lf, 'chart line-field');
                $lineExpr = $qualify($lf);
            } elseif ($lt === 'count') {
                $lineExpr = '*';
            } else {
                throw new Exception("The parameter (line-field) of " . __CLASS__ . " is required for line-metric {$lt}");
            }
            $q->selectRaw("{$lt}({$lineExpr}) as line_total");
        }

        if ($this->orderByValue) {
            // só direção — clampa p/ asc|desc (nunca interpola valor cru no ORDER BY).
            $dir = strtolower(trim((string) $this->orderByValue)) === 'asc' ? 'asc' : 'desc';
            $q->orderByRaw("{$this->totalChart}({$valueExpr}) {$dir}");
        }
        $orderGroups = $groups;
        if ($this->fieldColor) {
            array_shift($orderGroups);
        }
        foreach ($orderGroups as $g) {
            $q->orderByRaw($g);
        }

        $this->lastSql      = $q->toSql();
        $this->lastSqlBinds = $q->getBindings();

        try {
            // Cache opcional por conexão+SQL+bindings (mad.chart.cache_ttl;
            // 0 = off, executa direto — ver Mad\Database\QueryCache).
            $connName = $q->getConnection()->getName();
            $rows = \Mad\Database\QueryCache::remember(
                (string) $connName,
                $this->lastSql,
                $this->lastSqlBinds,
                fn () => $q->get(),
            );
        } catch (\Throwable $e) {
            $this->lastSqlError = $e->getMessage();
            throw $e;
        }

        $items = array_map(static fn ($r) => array_values((array) $r), $rows->all());

        // Mixed modo B: extrai a coluna line_total (SEMPRE a última do select)
        // ANTES da lógica de fieldColor — $items volta ao shape clássico
        // [label, total] e nenhum consumer downstream muda.
        $this->lineMetricValues = [];
        if ($items && $this->lineMetric) {
            foreach ($items as $i => $item) {
                $v = array_pop($item);
                $this->lineMetricValues[$i] = is_numeric($v) ? (float) $v : 0.0;
                $items[$i] = $item;
            }
        }

        if ($items && $this->fieldColor) {
            if (count($this->fieldGroup) > 1) {
                $this->colors = array_column($items, 0, 2);
            } else {
                $this->colors = array_column($items, 0, 1);
            }
            $items = array_map(static function ($item) {
                unset($item[0]);
                return array_values($item);
            }, $items);
        }

        if ($items && count($this->fieldGroup) == 1 && empty($this->legendsLimitShow)) {
            $this->legendsLimitShow = [round(count($items) * .75, 0), 5];
        }

        $this->data = $items;
    }

    /**
     * Mapa `alias (lowercase) => expressão de origem` a partir do SELECT da
     * query base — a fonte de verdade sobre quais nomes NÃO são colunas da
     * tabela base.
     *
     * Só entram aliases cuja expressão passa no OrderGuard: o resultado é
     * interpolado em selectRaw/groupByRaw (identificador não é bindável), então
     * o mapa é allowlist, não passe-livre. Expressão fora da gramática
     * simplesmente não entra — o campo segue o caminho antigo (prefixo da
     * tabela base), sem mudança de comportamento.
     *
     * @param \Illuminate\Database\Query\Builder $q
     * @return array<string,string>
     */
    private static function selectAliasMap($q): array
    {
        $map = [];
        foreach (($q->columns ?? []) as $column) {
            if ($column instanceof \Illuminate\Contracts\Database\Query\Expression) {
                try {
                    $column = $column->getValue($q->getGrammar());
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if (! is_string($column)) {
                continue;
            }

            // selectRaw('a as x, b as y') chega como UMA string — separa nas
            // vírgulas de topo (fora de parênteses e de literais).
            foreach (self::splitTopLevelCommas($column) as $piece) {
                // `.*` guloso pega o ÚLTIMO " as " — o alias é sempre o token
                // final, e a expressão pode conter `as` interno (cast/subquery).
                if (! preg_match('/^(.*)\s+as\s+([^\s]+)\s*$/is', trim($piece), $m)) {
                    continue;
                }
                $expr  = trim($m[1]);
                $alias = trim($m[2], "`\"'[] \t\r\n");
                if ($alias === '' || $expr === '' || ! \Mad\Database\OrderGuard::isSafeExpression($expr)) {
                    continue;
                }
                $map[strtolower($alias)] = $expr;
            }
        }

        return $map;
    }

    /**
     * Separa uma lista de colunas nas vírgulas de nível 0 (ignora vírgulas
     * dentro de parênteses e de literais quoted) — `count(a, b) as x, y as z`
     * são DUAS colunas, não três.
     *
     * @return array<int,string>
     */
    private static function splitTopLevelCommas(string $s): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote && ($i === 0 || $s[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf  .= $ch;
                continue;
            }
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') { $depth = max(0, $depth - 1); }

            if ($ch === ',' && $depth === 0) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * Returns the last SQL executed by loadData() (with literal placeholders).
     */
    public function getLastSql(): ?string
    {
        return $this->lastSql;
    }

    /**
     * Returns the bind values applied to the prepared statement.
     */
    public function getLastSqlBinds(): array
    {
        return is_array($this->lastSqlBinds) ? $this->lastSqlBinds : [];
    }

    /**
     * Returns SQL with bind values inlined (debug only — never use to execute).
     */
    public function getLastSqlInlined(): ?string
    {
        if (!$this->lastSql) return null;
        $sql   = $this->lastSql;
        $binds = $this->getLastSqlBinds();
        if (!$binds) return $sql;

        // ordered placeholders (?) and named (:par_N)
        foreach ($binds as $key => $val) {
            $quoted = is_null($val) ? 'NULL'
                : (is_numeric($val) ? (string) $val
                : "'" . str_replace("'", "''", (string) $val) . "'");

            if (is_int($key)) {
                $sql = preg_replace('/\?/', $quoted, $sql, 1);
            } else {
                $name = ltrim((string) $key, ':');
                $sql  = preg_replace('/:' . preg_quote($name, '/') . '\b/', $quoted, $sql);
            }
        }
        return $sql;
    }

    /**
     * Returns last error captured during query execution.
     */
    public function getLastSqlError(): ?string
    {
        return $this->lastSqlError;
    }

    /**
     * Formats the tooltip content for the chart.
     *
     * This method defines how tooltips should display values, applying any defined transformations.
     * It generates JavaScript callback functions for handling tooltip formatting dynamically.
     */
    private function formatTooltip()
    {
        $simpleCharts = in_array($this->type, ['pie', 'donut']);

        if (! $this->transformerValue)
        {
            if ($simpleCharts)
            {
                $this->options['tooltip'] = $this->getTootipFunction();
            }
        }

        $formats = [];
        $formatsLabels = [];
        $formatsTooltip = new stdClass;

        foreach ( $this->options['data']['columns'] as $column)
        {
            $newColumns = [];
            $newColumnsLabels = [];

            foreach($column as $key => $value)
            {
                if($key == 0)
                {
                    continue;
                }

                $newColumns[] = $this->transformerValue ? call_user_func($this->transformerValue, $value, $column, $this->options['data']['columns']) : $value;
                
                if($this->transformerLabelValue)
                {
                    $newColumnsLabels[] = call_user_func($this->transformerLabelValue, $value, $column, $this->options['data']['columns']);
                }
                else
                {
                    $newColumnsLabels[] = $this->transformerValue ? call_user_func($this->transformerValue, $value, $column, $this->options['data']['columns']) : $value;
                }
            }

            $formatsTooltip->{$column[0]} = $newColumns;

            $values = json_encode($newColumns);
            $valuesLabels = json_encode($newColumnsLabels);

            $key = $simpleCharts ? '0' : 'i';

            $formats[$column[0]] = "::(v, id, i, j, k) => { const values = eval('{$values}'); return values[{$key}]; }::";
            $formatsLabels[$column[0]] = "::(v, id, i, j, k) => { const values = eval('{$valuesLabels}'); return values[{$key}]; }::";
        }

        $this->formatsTooltip = json_encode($formatsTooltip);

        $this->options['data']['labels'] = ['format' => $formatsLabels];

        $return = "tooltipFormats[i][x]";

        if ($simpleCharts)
        {
            $return = "tooltipFormats[i][0]";

            if ( ! $this->percentage)
            {
                $this->options[$this->type]['label']['format'] =  "::(v, id, i, j, k) => {$return}::";;
            }
        }

        $this->options['tooltip']['format'] = ['value' => "::(v,r,i,x,z) => {$return}::"];
    }

    

    

    

    

    /**
     * Sets the colors for the chart.
     *
     * @param array $colors An array of color strings.
     */
    public function setColors(array $colors)
    {
        $this->colors = array_map(fn ($color) => ['stroke' => $color, 'fill' => $color], $colors);
    }

    /**
     * Disables zooming on the chart.
     */
    public function disableZoom()
    {
        $this->zoom = FALSE;
    }

    /**
     * Merges user-defined custom options with the default chart settings.
     *
     * @param array $options An associative array containing custom chart options.
     */
    public function setCustomOptions(array $options)
    {
        $this->customOptions = $options;
    }

    /**
     * Loads and prepares the chart data before display.
     */
    public function create()
    {
        $this->loaded = true;
        $this->loadData();
    }

    

    /**
     * Injeta dados manualmente, sem ORM.
     * Cada linha: [label, value] para série única; [category, sub-series, value] para multi-série.
     */
    public function setData(array $rows): void
    {
        $this->data   = $rows;
        $this->loaded = true;
    }
}
