<?php
namespace Mad\Chart;


/**
 * MadChart — Builder fluente para gráficos ECharts via componente Blade.
 *
 * Motor próprio (Mad\Chart\Engine\*) — dados via Illuminate Query Builder,
 * option ECharts montada em PHP; retorna MadChartConfig pro componente Blade.
 *
 * ┌─ Uso com ORM ────────────────────────────────────────────────────────────┐
 * │                                                                          │
 * │   $chart = MadChart::bar('vendas_mes')                                   │
 * │       ->fromModel('PedidoVenda')                                         │
 * │       ->groupBy(['mes'])                                                 │
 * │       ->sum('valor_total')                                               │
 * │       ->title('Vendas por Mês')                                          │
 * │       ->currency()                                                       │
 * │       ->height(350);                                                     │
 * │                                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Uso com dados manuais ───────────────────────────────────────────────────┐
 * │                                                                           │
 * │   $chart = MadChart::donut('categorias')                                  │
 * │       ->data(['Eletrônicos' => 4500, 'Roupas' => 2100])                   │
 * │       ->title('Distribuição')                                             │
 * │       ->currency();                                                       │
 * │                                                                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Radar e misto (coluna+linha) ────────────────────────────────────────────┐
 * │                                                                           │
 * │   MadChart::radar('r')->fromModel('Venda')                                │
 * │       ->groupBy(['mes', 'canal'])->sum('valor');   // 1 polígono/canal    │
 * │                                                                           │
 * │   // modo A: série 'Meta' do group-by vira linha sobre as colunas         │
 * │   MadChart::mixed('m')->fromModel('Venda')                                │
 * │       ->groupBy(['mes', 'canal'])->sum('valor')                           │
 * │       ->seriesAsLine(['Meta'], secondaryAxis: true);                      │
 * │                                                                           │
 * │   // modo B: 2ª métrica agregada como linha (group-by 1 dim)              │
 * │   MadChart::mixed('m')->fromModel('Venda')                                │
 * │       ->groupBy('mes')->sum('valor')                                      │
 * │       ->lineMetric('avg', 'ticket', 'Ticket médio');                      │
 * │                                                                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * No Blade:  <mad-chart :config="$chart" />
 */
class MadChart
{
    // ── Identificação ──────────────────────────────────────────────────────────
    private string $chartType;
    private string $name;

    // ── Fonte de dados (ORM) ────────────────────────────────────────────────────
    private ?string    $model      = null;
    private             $baseQuery = null;   // Eloquent/Query Builder (builder-native)
    private ?string    $database   = null;
    private array      $joins      = [];
    private array      $groupBy    = [];
    private ?string    $valueField = null;
    private string     $aggregate  = 'sum';

    // ── Dados manuais ───────────────────────────────────────────────────────────
    private ?array $manualData = null;

    // ── Visual ──────────────────────────────────────────────────────────────────
    private string $title          = '';
    private string $subtitle       = '';
    private string $width          = '100%';
    private int    $height         = 300;
    private bool   $showLegend     = false;
    private string $legendPosition = 'bottom';
    private array  $colors         = [];
    private bool   $noPanel        = false;
    private bool   $percentage     = false;

    // ── Formatação numérica ─────────────────────────────────────────────────────
    /** null = formatação numérica não configurada (0 é precisão VÁLIDA — inteiros). */
    private ?int   $precision  = null;
    private string $prefix     = '';
    private string $decimal    = ',';
    private string $thousand   = '.';
    private string $suffix     = '';
    private bool   $doAbbreviate = false;

    // ── Tipo-específico ─────────────────────────────────────────────────────────
    private bool   $stacked         = false;
    private bool   $stackedInternal = false;
    private ?array $nestedWidths    = null;
    private ?int   $nestedCap       = null;
    private bool   $horizontal      = false;
    private bool   $area            = false;
    private bool   $smooth          = true;
    // mixed: modo A (séries do group-by como linha) / modo B (2ª métrica)
    private array   $lineSeries        = [];
    private bool    $lineSecondaryAxis = false;
    private ?string $lineMetricTotal   = null;
    private ?string $lineMetricField   = null;
    private ?string $lineMetricLabel   = null;

    // ── Transformers ────────────────────────────────────────────────────────────
    private $transformerValue     = null;
    private $transformerLegend    = null;
    private $transformerSubLegend = null;

    // ── Opções ECharts extras ───────────────────────────────────────────────────
    private array $extraOptions = [];

    // ── Construtor privado — usar factories ─────────────────────────────────────

    private function __construct(string $type, string $name)
    {
        $this->chartType = $type;
        $this->name      = $name;
    }

    // ── Factories ───────────────────────────────────────────────────────────────

    public static function bar(string $name): self     { return new self('bar',     $name); }
    public static function line(string $name): self    { return new self('line',    $name); }
    public static function pie(string $name): self     { return new self('pie',     $name); }
    public static function donut(string $name): self   { return new self('donut',   $name); }
    public static function rose(string $name): self    { return new self('rose',    $name); }
    public static function funnel(string $name): self  { return new self('funnel',  $name); }
    public static function treemap(string $name): self { return new self('treemap', $name); }
    public static function radar(string $name): self   { return new self('radar',   $name); }
    public static function mixed(string $name): self   { return new self('mixed',   $name); }

    // ── Fonte ORM ────────────────────────────────────────────────────────────────

    public function fromModel(string $class): self
    {
        // Resolve short name (ex: 'IamUserGroup') → FQCN (App\Models\...).
        if ($class !== '' && !class_exists($class)) {
            try {
                $class = \Mad\Form\ModelOptionsLoader::resolveModelClass($class);
            } catch (\Throwable $e) {
                // mantém o original; erro real (se houver) aparece downstream
            }
        }

        $this->model = $class;
        return $this;
    }

    /**
     * Fonte via Eloquent/Query Builder pronto (100% Query Builder).
     * A agregação (group-by/total) roda sobre o builder; toBase() aplica
     * soft-delete. Dispensa model/database (vêm do builder).
     */
    public function fromQuery($query): self
    {
        $this->baseQuery = $query;
        return $this;
    }

    public function database(string $db): self   { $this->database = $db; return $this; }
    public function joins(array $joins): self    { $this->joins = $joins; return $this; }

    /**
     * Campo(s) de agrupamento. setFieldGroup() do BE* aceita apenas array de 1 ou 2 itens.
     */
    public function groupBy(string|array $fields): self
    {
        $this->groupBy = (array) $fields;
        return $this;
    }

    public function sum(string $field): self { $this->valueField = $field; $this->aggregate = 'sum';   return $this; }
    public function count(): self            {                             $this->aggregate = 'count'; return $this; }
    public function avg(string $field): self { $this->valueField = $field; $this->aggregate = 'avg';   return $this; }
    public function max(string $field): self { $this->valueField = $field; $this->aggregate = 'max';   return $this; }
    public function min(string $field): self { $this->valueField = $field; $this->aggregate = 'min';   return $this; }

    // ── Dados manuais ────────────────────────────────────────────────────────────

    /**
     * Fornece dados sem ORM. Formato: ['Label' => value, ...]
     */
    public function data(array $data): self
    {
        $this->manualData = $data;
        return $this;
    }

    // ── Visual ───────────────────────────────────────────────────────────────────

    public function title(string $title, string $subtitle = ''): self
    {
        $this->title    = $title;
        $this->subtitle = $subtitle;
        return $this;
    }

    public function height(int $px): self       { $this->height    = $px;   return $this; }
    public function width(string $w): self      { $this->width     = $w;    return $this; }
    public function noPanel(): self             { $this->noPanel   = true;  return $this; }
    public function percentage(): self          { $this->percentage = true; return $this; }
    public function colors(array|string $colors): self
    {
        if (is_string($colors)) {
            $colors = array_values(array_filter(array_map('trim', explode(',', $colors)), fn($c) => $c !== ''));
        }
        $this->colors = $colors;
        return $this;
    }

    public function legend(bool $show = true, string $position = 'bottom'): self
    {
        $this->showLegend     = $show;
        $this->legendPosition = $position;
        return $this;
    }

    // ── Formatação numérica ──────────────────────────────────────────────────────

    public function currency(
        int    $precision = 2,
        string $prefix    = 'R$ ',
        string $decimal   = ',',
        string $thousand  = '.'
    ): self {
        $this->precision = $precision;
        $this->prefix    = $prefix;
        $this->decimal   = $decimal;
        $this->thousand  = $thousand;
        return $this;
    }

    public function numeric(
        int    $precision = 2,
        string $decimal   = ',',
        string $thousand  = '.'
    ): self {
        $this->precision = $precision;
        $this->decimal   = $decimal;
        $this->thousand  = $thousand;
        return $this;
    }

    public function suffix(string $suffix): self { $this->suffix      = $suffix; return $this; }
    public function abbreviate(): self           { $this->doAbbreviate = true;   return $this; }

    // ── Tipo-específico ──────────────────────────────────────────────────────────

    /** Bar: empilhar séries. */
    public function stacked(): self { $this->stacked = true; return $this; }

    /**
     * Bar: séries sobrepostas ANINHADAS ("stacked internal") — maior série
     * mais larga e atrás, menor mais estreita e na frente, centralizadas no
     * mesmo slot (boneca russa). Ver EChart::applyNestedBars().
     *
     * @param array|null $widths  escada de larguras em % do slot (null = automática)
     * @param int|null   $capBand banda máxima px pro teto de largura (default 110)
     */
    public function stackedInternal(?array $widths = null, ?int $capBand = null): self
    {
        $this->stackedInternal = true;
        $this->nestedWidths    = $widths;
        $this->nestedCap       = $capBand;
        return $this;
    }

    /** Bar: orientação horizontal. */
    public function horizontal(): self { $this->horizontal = true; return $this; }

    /**
     * Mixed modo A: séries do group-by (2ª dimensão) que viram LINHA sobre as
     * colunas. Match por nome (case-insensitive, pós sub-legend-transformer)
     * ou índice int (ordem alfabética das séries). String aceita CSV.
     *
     * @param array|string $series        nomes/índices ('Meta' | ['Meta','Média'] | [1])
     * @param bool         $secondaryAxis linha usa eixo Y secundário (direita)
     */
    public function seriesAsLine(array|string $series, bool $secondaryAxis = false): self
    {
        if (is_string($series)) {
            $series = array_values(array_filter(array_map('trim', explode(',', $series)), fn($s) => $s !== ''));
        }
        $this->lineSeries        = $series;
        $this->lineSecondaryAxis = $secondaryAxis;
        return $this;
    }

    /**
     * Mixed modo B: 2ª métrica agregada como série de LINHA (group-by 1 dim).
     * Ex.: colunas = sum(valor), linha = avg(ticket).
     *
     * @param string      $total         sum|max|min|count|avg
     * @param string|null $field         campo agregado (null + count → count(*))
     * @param string|null $label         nome da série na legenda/tooltip
     * @param bool        $secondaryAxis linha usa eixo Y secundário (direita)
     */
    public function lineMetric(string $total, ?string $field = null, ?string $label = null, bool $secondaryAxis = false): self
    {
        $this->lineMetricTotal   = $total;
        $this->lineMetricField   = $field;
        $this->lineMetricLabel   = $label;
        $this->lineSecondaryAxis = $secondaryAxis || $this->lineSecondaryAxis;
        return $this;
    }

    /** Line: exibir área preenchida. */
    public function area(bool $smooth = true): self
    {
        $this->area   = true;
        $this->smooth = $smooth;
        return $this;
    }

    // ── Transformers ─────────────────────────────────────────────────────────────

    public function transformer(callable $fn): self          { $this->transformerValue      = $fn; return $this; }
    public function legendTransformer(callable $fn): self    { $this->transformerLegend     = $fn; return $this; }
    public function subLegendTransformer(callable $fn): self { $this->transformerSubLegend  = $fn; return $this; }

    // ── Opções ECharts extras ────────────────────────────────────────────────────

    public function options(array $extra): self { $this->extraOptions = $extra; return $this; }

    // ── Build ────────────────────────────────────────────────────────────────────

    /**
     * Lazy: chamado pelo componente Blade em tempo de render.
     * Instancia a classe BE* correta, executa ORM/data e retorna MadChartConfig.
     */
    public function toConfig(): MadChartConfig
    {
        return $this->getEngine()->toConfig();
    }

    /**
     * Builds (lazily caches) and returns the underlying BEChart instance.
     * Allows the caller (e.g. <mad-db-chart> debug panel) to inspect the
     * generated SQL even when toConfig() throws on execution.
     */
    private ?Engine\EChart $engine = null;

    public function getEngine(): Engine\EChart
    {
        if ($this->engine === null) {
            $this->engine = $this->buildEngine();
        }
        return $this->engine;
    }

    private function buildEngine(): Engine\EChart
    {
        $db = $this->database
            ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'main_database');

        $chart = match ($this->chartType) {
            'bar'     => new Engine\BarChart($this->name, $db),
            'line'    => new Engine\LineChart($this->name, $db),
            'pie'     => new Engine\PieChart($this->name, $db),
            'donut'   => new Engine\DonutChart($this->name, $db),
            'rose'    => new Engine\RoseChart($this->name, $db),
            'funnel'  => new Engine\FunnelChart($this->name, $db),
            'treemap' => new Engine\TreemapChart($this->name, $db),
            'radar'   => new Engine\RadarChart($this->name, $db),
            'mixed'   => new Engine\MixedChart($this->name, $db),
            default   => throw new \InvalidArgumentException("Tipo de gráfico inválido: {$this->chartType}"),
        };

        // ── Dados ──────────────────────────────────────────────────────────────

        if ($this->manualData !== null) {
            $rows = [];
            foreach ($this->manualData as $label => $value) {
                $rows[] = [(string) $label, (float) $value];
            }
            $chart->setFieldGroup(['label']);
            $chart->setData($rows);
        } else {
            if ($this->baseQuery !== null) $chart->setBaseQuery($this->baseQuery);
            if ($this->model)      $chart->setModel($this->model);
            if ($this->joins)      $chart->setJoins($this->joins);
            if ($this->groupBy) {
                // pie/donut/rose/funnel/treemap override setFieldGroup(string) and wrap
                // the value in an array themselves — so we must pass a string, not an array.
                // bar/line accept arrays natively.
                $needsString = in_array($this->chartType, ['pie', 'donut', 'rose', 'funnel', 'treemap']);
                $chart->setFieldGroup($needsString ? ($this->groupBy[0] ?? '') : $this->groupBy);
            }
            if ($this->valueField) {
                $chart->setFieldValue($this->valueField);
            } elseif ($this->aggregate === 'count') {
                // BChart prefixes bare names with table, so use the first groupBy field
                // instead of '*' (which would become "table.*" and break SQL)
                $countField = $this->groupBy[0] ?? 'id';
                $chart->setFieldValue($countField);
            }
            $chart->setTotal($this->aggregate);
        }

        // ── Visual ─────────────────────────────────────────────────────────────

        $chart->setTitle($this->title);
        if ($this->subtitle) $chart->setSubtitle($this->subtitle);
        $chart->setSize($this->width, $this->height);
        $chart->showLegend($this->showLegend, $this->legendPosition);
        $chart->hidePanel($this->noPanel);
        $chart->showPercentage($this->percentage);

        // precision !== null (e não truthiness): numeric(0)/currency(...,0) são
        // formatos válidos — o check antigo tratava precisão 0 como "não setado".
        if ($this->precision !== null || $this->prefix || $this->suffix) {
            $chart->setDisplayNumeric(
                $this->precision ?? 2,
                $this->decimal,
                $this->thousand,
                $this->prefix,
                $this->suffix
            );
        }

        if ($this->doAbbreviate)  $chart->enableAbbreviatedValues();
        if ($this->colors)        $chart->setColors($this->colors);
        if ($this->extraOptions)  $chart->setCustomOptions($this->extraOptions);

        // ── Transformers ────────────────────────────────────────────────────────

        if ($this->transformerValue)   $chart->setTransformerValue($this->transformerValue);
        if ($this->transformerLegend)  $chart->setTransformerLegend($this->transformerLegend);
        if ($this->transformerSubLegend && method_exists($chart, 'setTransformerSubLegend')) {
            $chart->setTransformerSubLegend($this->transformerSubLegend);
        }

        // ── Tipo-específico ─────────────────────────────────────────────────────

        if ($this->chartType === 'bar') {
            if ($this->horizontal) $chart->setLayout('horizontal');
            if ($this->stackedInternal) {
                $chart->setStackInternal($this->nestedWidths, $this->nestedCap);
            } elseif ($this->stacked) {
                $chart->setStack(true);
            }
        }

        if ($this->chartType === 'mixed') {
            // horizontal/stackedInternal não se aplicam (mixed é sempre
            // vertical) — ignorados por construção.
            if ($this->lineSeries && $this->lineMetricTotal !== null) {
                throw new \InvalidArgumentException(
                    'mixed: use line-series (séries do group-by) OU line-total/line-field (2ª métrica), não os dois'
                );
            }
            if ($this->lineSeries) {
                $chart->setSeriesLine($this->lineSeries, $this->lineSecondaryAxis);
            }
            if ($this->lineMetricTotal !== null) {
                $chart->setLineMetric($this->lineMetricTotal, $this->lineMetricField, $this->lineMetricLabel, $this->lineSecondaryAxis);
            }
            if ($this->stacked) $chart->setStack(true);
        }

        if ($this->chartType === 'line' && $this->area) {
            $chart->showArea($this->smooth);
        }

        return $chart;
    }

}