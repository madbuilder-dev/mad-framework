<?php
namespace Mad\Calendar;
use Mad\Component\MadComponent;
use Mad\View\MadBlade;

use Mad\Database\QuerySource;
use Mad\Form\ModelOptionsLoader;

use DateTime;
use DateInterval;
use DatePeriod;

/**
 * MadGantt — Builder fluente para gráfico Gantt.
 *
 * Gera configuração JSON consumida pelo componente Alpine `madGantt()`.
 * Compatível com mad-ui, mad-livewire e BladeOne.
 *
 * Uso:
 *   $gantt = MadGantt::make('2026-03-01')
 *       ->viewMode(MadGantt::MODE_DAYS)
 *       ->zoom('md')
 *       ->interval('15 days')
 *       ->title('Sprint 12')
 *       ->addRow('dev', 'Desenvolvimento')
 *       ->addRow('qa',  'QA')
 *       ->addEvent('1', 'dev', 'Feature X', '2026-03-01', '2026-03-05', '#3b82f6', 60)
 *       ->addEvent('2', 'qa',  'Testes',    '2026-03-06', '2026-03-08', '#10b981')
 *       ->onEventClick('onEventClick')
 *       ->render();
 *
 *   // Dentro de MadComponent::view():
 *   return ['doc.gantt', ['gantt' => $gantt]];
 */
class MadGantt
{
    // ── View modes ───────────────────────────────────────────────────────
    const MODE_DAYS            = 'days';
    const MODE_MONTHS          = 'months';
    const MODE_DAYS_WITH_HOUR  = 'days_with_hour';
    const MODE_MONTHS_WITH_DAY = 'months_with_day';

    // ── Propriedades ─────────────────────────────────────────────────────
    protected string $viewMode    = self::MODE_DAYS;
    protected string $zoom        = 'md';
    protected string $startDate   = '';
    protected string $interval    = '15 days';
    protected string $title       = '';
    protected string $chartId     = ''; // chave de persistência client (vazio = derivada)
    protected string $locale      = 'pt-br';
    protected string $progressScale = 'auto'; // auto | fraction (0..1) | percent (0-100)
    protected int    $minutesStep = 1440;
    protected bool   $striped     = false;
    protected bool   $stripedRows = false;
    protected bool   $fullHoursOn = false;
    protected bool   $compactEventsOn = false;
    protected bool   $showViewModeBtn = false;
    protected bool   $showZoomBtn     = false;
    protected array  $rows          = [];
    protected array  $events        = [];
    protected array  $tasks         = [];   // novo modelo hierarquico (Fase 1)
    protected array  $columns       = [];   // colunas configuraveis da sidebar
    protected array  $dependencies  = [];   // Fase 2
    protected array  $resources     = [];   // Fase 3
    protected array  $assignments   = [];   // Fase 3
    protected array  $baselines     = [];   // Fase 4
    protected array  $workingDays   = [1, 2, 3, 4, 5]; // seg-sex
    protected array  $holidays      = [];
    protected array  $workingHours  = [0, 24]; // [start_hour, end_hour]
    protected bool   $autoSchedule  = false;
    protected bool   $criticalPath  = false;
    protected bool   $showWorkload  = false;
    protected string $workloadMode  = 'hours'; // hours | tasks | toggle
    protected bool   $enableInlineEdit = false;
    protected bool   $enableMultiSelect = false;
    protected array  $headerActions = [];

    // ── v2 visual (phase grouping + look-and-feel) ────────────────────────
    protected ?string $phaseField = null;          // ex: 'phase' — ativa agrupamento
    protected array   $phases     = [];            // [['id'=>'design','name'=>'Design','code'=>'DSG','hue'=>290], ...]
    protected array   $people     = [];            // ['ms' => ['name'=>'...', 'initials'=>'MS', 'color'=>'#...']]
    protected string  $arrowStyle = 'elbow';       // elbow | curved | straight
    protected string  $density    = 'comfortable'; // compact | comfortable | spacious
    protected bool    $showMinimap = true;
    protected bool    $showSearch  = true;
    protected bool    $showWeekendsBg = true;
    protected bool    $showGridLines  = true;
    protected bool    $showAvatars    = true;
    protected int     $taskColWidth   = 300;

    // ── Ações mad-wire ───────────────────────────────────────────────────
    protected string $eventClickMethod  = '';
    protected string $dayClickMethod    = '';
    protected string $eventUpdateMethod = '';
    protected string $reloadMethod      = '';
    protected string $taskClickMethod        = '';
    protected string $taskUpdateMethod       = '';
    protected string $dependencyCreateMethod = '';
    protected string $dependencyDeleteMethod = '';

    // ── Popover ──────────────────────────────────────────────────────────
    protected string $popTitle   = '';
    protected string $popContent = '';

    // ── Factory ──────────────────────────────────────────────────────────

    public static function make(?string $startDate = null): static
    {
        $instance = new static();
        $instance->startDate = $startDate ?: date('Y-m-d');
        return $instance;
    }

    // ── Auto factory (query Eloquent declarativa) ────────────────────────

    /**
     * Cria Gantt completo a partir de array de config — auto-query no banco.
     *
     * Util para uso programatico quando nao se quer escrever uma subclass
     * de MadGanttComponent — basta passar config em array. A tag Blade
     * `<mad-gantt>` (compilada pelo MadGanttCompiler) eh a forma preferida;
     * este metodo permanece como atalho PHP.
     *
     * Config chaves suportadas:
     *  - model               (obrigatorio)
     *  - database            (default: business)
     *  - id_field, name_field, start_field, end_field, parent_field,
     *    progress_field, color_field, owner_field, type_field
     *  - order_by, filters (array)
     *  - milestone_field (bool -> task vira milestone), progress_scale
     *    ('auto'|'fraction'|'percent')
     *  - start_date, interval, view_mode, zoom, title
     *  - critical_path, auto_schedule, show_workload, workload_mode
     *  - striped_rows, working_days, holidays
     *  - columns (array)
     *  - dependency_source (string)
     *  - resource_model, resource_id_field, resource_name_field,
     *    resource_role_field, resource_capacity_field
     *  - assignment_source (string)
     *  - on_task_click, on_task_update, on_dependency_create (method names)
     */
    public static function auto(array $cfg): static
    {
        $database = $cfg['database'] ?? ($cfg['db'] ?? 'business');
        $startDate = $cfg['start_date'] ?? date('Y-m-d', strtotime('-3 days'));

        $g = static::make($startDate)
            ->viewMode($cfg['view_mode'] ?? 'days')
            ->zoom($cfg['zoom'] ?? 'md')
            ->interval($cfg['interval'] ?? '30 days')
            ->title($cfg['title'] ?? '')
            ->enableViewModeButton()
            ->enableZoomButton();

        if (!empty($cfg['working_days']))  $g->workingDays($cfg['working_days']);
        if (!empty($cfg['holidays']))      $g->holidays($cfg['holidays']);
        if (!empty($cfg['striped_rows']))  $g->stripedRows();
        if (!empty($cfg['critical_path'])) $g->showCriticalPath();
        if (!empty($cfg['auto_schedule'])) $g->enableAutoSchedule();
        if (!empty($cfg['show_workload'])) {
            $g->showWorkloadBand()->workloadMode($cfg['workload_mode'] ?? 'hours');
        }
        if (!empty($cfg['inline_edit']))     $g->enableInlineEdit();
        if (!empty($cfg['multi_select']))    $g->enableMultiSelect();

        // v2 visual options
        if (!empty($cfg['phase_field']))    $g->phaseField($cfg['phase_field']);
        if (!empty($cfg['phases']))         $g->phases($cfg['phases']);
        if (!empty($cfg['people']))         $g->people($cfg['people']);
        if (!empty($cfg['arrow_style']))    $g->arrowStyle($cfg['arrow_style']);
        if (!empty($cfg['density']))        $g->density($cfg['density']);
        if (isset($cfg['show_minimap']))    $g->showMinimap((bool) $cfg['show_minimap']);
        if (isset($cfg['show_search']))     $g->showSearch((bool) $cfg['show_search']);
        if (isset($cfg['show_weekends']))   $g->showWeekends((bool) $cfg['show_weekends']);
        if (isset($cfg['show_grid']))       $g->showGridLines((bool) $cfg['show_grid']);
        if (isset($cfg['show_avatars']))    $g->showAvatars((bool) $cfg['show_avatars']);
        if (!empty($cfg['task_col_width'])) $g->taskColWidth((int) $cfg['task_col_width']);

        // Callbacks
        if (!empty($cfg['on_task_click']))         $g->onTaskClick($cfg['on_task_click']);
        if (!empty($cfg['on_task_update']))        $g->onTaskUpdate($cfg['on_task_update']);
        if (!empty($cfg['on_dependency_create']))  $g->onDependencyCreate($cfg['on_dependency_create']);
        if (!empty($cfg['on_reload']))             $g->onReload($cfg['on_reload']);

        // Colunas
        $columns = $cfg['columns'] ?? [];
        if (empty($columns)) {
            $columns = self::defaultColumnsFor($cfg);
        }
        $g->columns($columns);

        $model = $cfg['model'] ?? '';
        if (!$model) return $g;

        $idF      = $cfg['id_field']       ?? 'id';
        $nameF    = $cfg['name_field']     ?? 'name';
        $startF   = $cfg['start_field']    ?? 'start';
        $endF     = $cfg['end_field']      ?? 'end';
        $parentF  = $cfg['parent_field']   ?? '';
        $progF    = $cfg['progress_field'] ?? '';
        $colorF   = $cfg['color_field']    ?? '';
        $ownerF   = $cfg['owner_field']    ?? '';
        $typeF    = $cfg['type_field']     ?? '';
        $msF      = $cfg['milestone_field'] ?? '';

        if (!empty($cfg['progress_scale'])) $g->progressScale((string) $cfg['progress_scale']);

        {
            // F5: builder-native — filtros aplicados via :filters (array DSL).
            $tq = self::_modelQuery($model, $database);
            if (!empty($cfg['filters'])) {
                QuerySource::applyArrayFilters($tq, $cfg['filters']);
            }
            $order = $cfg['order_by'] ?? $startF;

            $taskIds = [];
            $records = QuerySource::recordsFromQuery($tq, $order ?: null) ?? [];
            foreach ($records as $r) {
                $id = (string) $r->{$idF};
                $taskIds[$id] = true;
                $props = ['name' => (string) ($r->{$nameF} ?? '')];
                if ($startF) $props['start'] = (string) ($r->{$startF} ?? '');
                if ($endF)   $props['end']   = (string) ($r->{$endF} ?? '');
                if ($parentF) {
                    $pid = $r->{$parentF} ?? null;
                    if (!empty($pid)) $props['parentId'] = (string) $pid;
                }
                if ($progF) $props['progress'] = (float) ($r->{$progF} ?? 0);
                if ($colorF) {
                    $cor = $r->{$colorF} ?? null;
                    if (!empty($cor)) $props['color'] = (string) $cor;
                }
                if ($ownerF) {
                    $own = $r->{$ownerF} ?? null;
                    if (!empty($own)) $props['owner'] = (string) $own;
                }
                if ($typeF) {
                    $type = $r->{$typeF} ?? null;
                    if (!empty($type)) $props['type'] = (string) $type;
                }
                if ($msF) {
                    $mv = $r->{$msF} ?? null;
                    $props['milestone'] = !empty($mv) && (string) $mv !== '0';
                    if ($props['milestone']) $props['type'] = 'milestone';
                }
                $g->addTask($id, $props);
            }

            // Dependencias
            $depSrc = $cfg['dependency_source'] ?? '';
            if ($depSrc && str_starts_with($depSrc, 'fk:')) {
                $fkField = substr($depSrc, 3);
                foreach ($records as $r) {
                    $predId = $r->{$fkField} ?? null;
                    if (empty($predId)) continue;
                    $predIdStr = (string) $predId;
                    $sucIdStr  = (string) $r->{$idF};
                    if (!isset($taskIds[$predIdStr]) || !isset($taskIds[$sucIdStr])) continue;
                    $g->addDependency($predIdStr, $sucIdStr, 'FS');
                }
            } elseif ($depSrc && str_starts_with($depSrc, 'table:')) {
                $spec = self::parseSpec(substr($depSrc, 6));
                $mdl = $spec['_model'] ?? '';
                if ($mdl) {
                    $fromField = $spec['from'] ?? 'from_id';
                    $toField   = $spec['to']   ?? 'to_id';
                    $typeField = $spec['type'] ?? null;
                    $lagField  = $spec['lag']  ?? null;
                    $deps = QuerySource::recordsFromQuery(self::_modelQuery($mdl, $database)) ?? [];
                    foreach ($deps as $d) {
                        $from = (string) ($d->{$fromField} ?? '');
                        $to   = (string) ($d->{$toField} ?? '');
                        if (!$from || !$to) continue;
                        $type = $typeField ? (string) ($d->{$typeField} ?? 'FS') : 'FS';
                        $lag  = $lagField  ? (int)    ($d->{$lagField}  ?? 0)    : 0;
                        $g->addDependency($from, $to, $type, $lag);
                    }
                }
            }

            // Recursos
            $resModel = $cfg['resource_model'] ?? '';
            if ($resModel) {
                $rIdF   = $cfg['resource_id_field']       ?? 'id';
                $rNameF = $cfg['resource_name_field']     ?? 'name';
                $rRoleF = $cfg['resource_role_field']     ?? 'role';
                $rCapF  = $cfg['resource_capacity_field'] ?? 'capacity';
                $resources = QuerySource::recordsFromQuery(self::_modelQuery($resModel, $database)) ?? [];
                foreach ($resources as $r) {
                    $g->addResource((string) $r->{$rIdF}, [
                        'name'     => (string) ($r->{$rNameF} ?? ''),
                        'role'     => (string) ($r->{$rRoleF} ?? ''),
                        'capacity' => (int)    ($r->{$rCapF}  ?? 8),
                    ]);
                }
            }

            // Assignments
            $asgSrc = $cfg['assignment_source'] ?? '';
            if ($asgSrc) {
                $spec = self::parseSpec($asgSrc);
                $mdl  = $spec['_model'] ?? '';
                if ($mdl) {
                    $tF = $spec['task']     ?? 'task_id';
                    $rF = $spec['resource'] ?? 'resource_id';
                    $hF = $spec['hours']    ?? null;
                    $items = QuerySource::recordsFromQuery(self::_modelQuery($mdl, $database)) ?? [];
                    foreach ($items as $a) {
                        $tid = (string) ($a->{$tF} ?? '');
                        $rid = (string) ($a->{$rF} ?? '');
                        if (!$tid || !$rid) continue;
                        $hours = $hF ? (int) ($a->{$hF} ?? 0) : null;
                        $g->assignResource($tid, $rid, $hours);
                    }
                }
            }

        }

        return $g;
    }

    /**
     * Resolve nome de model (short name 'Pedido' ou FQCN) para FQCN Eloquent
     * (App\Models\*). Captura \Throwable — class-load pode lançar Error, não
     * Exception — e devolve o nome original como fallback; o erro real estoura
     * adiante, dentro do try/rollback do auto().
     */
    private static function resolveModelFqcn(string $model): string
    {
        try {
            return ModelOptionsLoader::resolveModelClass($model);
        } catch (\Throwable $e) {
            return $model;
        }
    }

    /** Builder do model na conexão explícita (F4f) — sem expr-on-`::`. */
    private static function _modelQuery(string $model, $database)
    {
        $cls = self::resolveModelFqcn($model);
        return $database ? $cls::on($database) : $cls::query();
    }

    /** Gera colunas default conforme campos disponiveis no config. */
    protected static function defaultColumnsFor(array $cfg): array
    {
        $cols = [['field' => 'name', 'label' => 'Tarefa', 'width' => 240, 'tree' => true]];
        if (!empty($cfg['start_field'])) {
            $cols[] = ['field' => 'start', 'label' => 'Início', 'width' => 100, 'format' => 'date'];
        }
        if (!empty($cfg['end_field'])) {
            $cols[] = ['field' => 'end', 'label' => 'Fim', 'width' => 100, 'format' => 'date'];
        }
        $cols[] = ['field' => 'duration', 'label' => 'Dias', 'width' => 70, 'format' => 'days', 'align' => 'center'];
        if (!empty($cfg['owner_field'])) {
            $cols[] = ['field' => 'owner', 'label' => 'Resp.', 'width' => 100];
        }
        if (!empty($cfg['progress_field'])) {
            $cols[] = ['field' => 'progress', 'label' => 'Prog.', 'width' => 80, 'format' => 'percent', 'align' => 'right'];
        }
        return $cols;
    }

    /** Parsea spec tipo 'ModelName,k1=v1,k2=v2'. */
    protected static function parseSpec(string $spec): array
    {
        $parts = array_map('trim', explode(',', $spec));
        $out = ['_model' => array_shift($parts)];
        foreach ($parts as $p) {
            if (strpos($p, '=') === false) continue;
            [$k, $v] = explode('=', $p, 2);
            $out[trim($k)] = trim($v);
        }
        return $out;
    }

    // ── Configuração ─────────────────────────────────────────────────────

    /**
     * Define o modo de visualização.
     * Valores: days, months, days_with_hour, months_with_day
     *
     * @deprecated Sem efeito no client v2 — a timeline é controlada por zoom().
     *             Mantido por compatibilidade de API.
     */
    public function viewMode(string $mode): static
    {
        $this->viewMode = $mode;
        return $this;
    }

    /**
     * Define o nível de zoom (granularidade da timeline).
     * Valores v2: hour | day | week | month | year.
     * Aliases legados (mapeados pelo client): xs→day, sm→week, md→month, lg→year.
     */
    public function zoom(string $size): static
    {
        $this->zoom = $size;
        return $this;
    }

    /**
     * Data de início (formato Y-m-d).
     *
     * @deprecated Sem efeito visual no client v2 — o range é derivado das
     *             datas das tasks (computeRange). Mantido por compat.
     */
    public function startDate(string $date): static
    {
        $this->startDate = $date;
        return $this;
    }

    /**
     * Intervalo de tempo.
     * Exemplos: '15 days', '1 month', '2 month', '30 days'
     *
     * @deprecated Sem efeito visual no client v2 (range vem das tasks).
     *             Mantido por compat.
     */
    public function interval(string $interval): static
    {
        $this->interval = $interval;
        return $this;
    }

    /**
     * Título exibido no cabeçalho.
     */
    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Id estável do chart — chave de persistência de preferências no client
     * (zoom/expansão/filtros sobrevivem a reload). Sem id, o client deriva
     * rota+posição na página.
     */
    public function id(string $id): static
    {
        $this->chartId = $id;
        return $this;
    }

    /**
     * Locale para formatação de datas. Tabelas client: pt-br e en; qualquer
     * outro valor cai no fallback (pt* → pt-br, resto → en).
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Escala do progress das tasks: 'auto' (heurística: >1 lê como 0-100),
     * 'fraction' (0..1) ou 'percent' (0-100). Bancos que guardam 0-100 devem
     * declarar 'percent' — o valor 1 é ambíguo no 'auto' (vira 100%).
     */
    public function progressScale(string $scale): static
    {
        $this->progressScale = in_array($scale, ['auto', 'fraction', 'percent'], true) ? $scale : 'auto';
        return $this;
    }

    /**
     * Granularidade do snap ao arrastar (em minutos).
     * 1440 = 1 dia, 60 = 1 hora, 30 = 30 min
     */
    /** @deprecated Sem efeito no client v2 — snap é por dia (ou hora no zoom hour). */
    public function minutesStep(int $minutes): static
    {
        $this->minutesStep = $minutes;
        return $this;
    }

    /**
     * Alterna cores nas colunas (meses ou dias).
     */
    /** @deprecated Sem efeito no client v2. */
    public function stripedMonths(bool $v = true): static
    {
        $this->striped = $v;
        return $this;
    }

    /**
     * Alterna cores nas linhas.
     */
    public function stripedRows(bool $v = true): static
    {
        $this->stripedRows = $v;
        return $this;
    }

    /**
     * Exibe todas as 24 horas (MODE_DAYS_WITH_HOUR).
     */
    /** @deprecated Sem efeito no client v2. */
    public function fullHours(bool $v = true): static
    {
        $this->fullHoursOn = $v;
        return $this;
    }

    /**
     * Remove espaço vertical entre eventos na mesma row.
     */
    /** @deprecated Sem efeito no client v2. */
    public function compactEvents(bool $v = true): static
    {
        $this->compactEventsOn = $v;
        return $this;
    }

    /**
     * Exibe botão de troca de view mode no cabeçalho.
     */
    /** @deprecated Sem efeito no client v2 (controle de zoom é fixo no shell). */
    public function enableViewModeButton(bool $v = true): static
    {
        $this->showViewModeBtn = $v;
        return $this;
    }

    /**
     * Exibe botão de zoom no cabeçalho.
     */
    /** @deprecated Sem efeito no client v2 (controle de zoom é fixo no shell). */
    public function enableZoomButton(bool $v = true): static
    {
        $this->showZoomBtn = $v;
        return $this;
    }

    // ── v2: agrupamento por fase + visual ────────────────────────────────

    /**
     * Ativa o agrupamento por fase. Informe o nome do campo da task
     * que carrega o id da fase (ex: 'phase', 'categoria_id').
     */
    public function phaseField(string $field): static
    {
        $this->phaseField = $field ?: null;
        return $this;
    }

    /**
     * Define as fases manualmente. Sem essa chamada, as fases sao
     * inferidas dos valores distintos do phaseField das tasks.
     *
     * Formato: [['id'=>'discovery','name'=>'Discovery','code'=>'DSC','hue'=>250], ...]
     */
    public function phases(array $phases): static
    {
        $this->phases = $phases;
        return $this;
    }

    /**
     * Adiciona uma fase (alternativa ao phases() em lote).
     */
    public function addPhase(string $id, string $name, ?string $code = null, ?int $hue = null): static
    {
        $this->phases[] = [
            'id'   => $id,
            'name' => $name,
            'code' => $code ?? strtoupper(substr($id, 0, 3)),
            'hue'  => $hue ?? 250,
        ];
        return $this;
    }

    /**
     * Define o dicionario de pessoas para avatares/tooltip.
     *
     * Formato: ['ms' => ['name'=>'Marina Souza','initials'=>'MS','color'=>'#8b5cf6'], ...]
     */
    public function people(array $people): static
    {
        $this->people = $people;
        return $this;
    }

    /**
     * Estilo das setas de dependencia: elbow | curved | straight
     */
    public function arrowStyle(string $style): static
    {
        $this->arrowStyle = in_array($style, ['elbow', 'curved', 'straight'], true) ? $style : 'elbow';
        return $this;
    }

    /**
     * Densidade visual: compact (30px) | comfortable (38px) | spacious (48px)
     */
    public function density(string $mode): static
    {
        $this->density = in_array($mode, ['compact', 'comfortable', 'spacious'], true) ? $mode : 'comfortable';
        return $this;
    }

    public function showMinimap(bool $v = true): static    { $this->showMinimap   = $v; return $this; }
    public function showSearch(bool $v = true):  static    { $this->showSearch    = $v; return $this; }
    public function showWeekends(bool $v = true): static   { $this->showWeekendsBg = $v; return $this; }
    public function showGridLines(bool $v = true): static  { $this->showGridLines  = $v; return $this; }
    public function showAvatars(bool $v = true): static    { $this->showAvatars    = $v; return $this; }
    public function taskColWidth(int $px): static          { $this->taskColWidth   = max(160, $px); return $this; }

    // ── Rows ─────────────────────────────────────────────────────────────

    /**
     * Adiciona uma linha (swimlane).
     */
    public function addRow(string|int $id, string $label): static
    {
        $this->rows[] = ['id' => (string) $id, 'label' => $label];
        return $this;
    }

    /**
     * Define todas as linhas de uma vez.
     * Formato: [['id' => '...', 'label' => '...'], ...]
     */
    public function rows(array $rows): static
    {
        $this->rows = $rows;
        return $this;
    }

    // ── Eventos ──────────────────────────────────────────────────────────

    /**
     * Adiciona um evento ao gantt.
     *
     * @param string      $id      Identificador do evento
     * @param string      $rowId   ID da row (swimlane)
     * @param string      $title   Título exibido na barra
     * @param string      $start   Data/hora início (Y-m-d ou Y-m-d H:i)
     * @param string      $end     Data/hora fim
     * @param string|null $color   Cor de fundo (#hex)
     * @param float|null  $percent Percentual de conclusão (0-100)
     * @param array       $extra   Dados adicionais (passados ao JS)
     */
    public function addEvent(
        string  $id,
        string  $rowId,
        string  $title,
        string  $start,
        string  $end,
        ?string $color   = null,
        ?float  $percent = null,
        array   $extra   = []
    ): static {
        $evt = array_merge($extra, [
            'id'      => $id,
            'rowId'   => $rowId,
            'title'   => $title,
            'start'   => $start,
            'end'     => $end,
        ]);
        if ($color !== null)   $evt['color']   = $color;
        if ($percent !== null) $evt['percent'] = $percent;

        $this->events[] = $evt;
        return $this;
    }

    /**
     * Define todos os eventos de uma vez.
     */
    public function events(array $events): static
    {
        $this->events = $events;
        return $this;
    }

    // ── Header Actions ───────────────────────────────────────────────────

    /**
     * Adiciona botão customizado no cabeçalho.
     *
     * @param string $label  Texto do botão
     * @param string $icon   Nome do ícone Lucide (ex: 'download')
     * @param string $method Nome do método MadWire a chamar
     */
    public function addHeaderAction(string $label, string $icon, string $method): static
    {
        $this->headerActions[] = [
            'label'  => $label,
            'icon'   => $icon,
            'method' => $method,
        ];
        return $this;
    }

    // ── Ações ────────────────────────────────────────────────────────────

    /**
     * Método chamado ao clicar em um evento.
     * Recebe: (string $id, string $title, string $rowId)
     */
    public function onEventClick(string $method): static
    {
        $this->eventClickMethod = $method;
        return $this;
    }

    /**
     * Método chamado ao clicar em uma célula vazia.
     * Recebe: (string $date, string $rowId)
     */
    public function onDayClick(string $method): static
    {
        $this->dayClickMethod = $method;
        return $this;
    }

    /**
     * Método chamado ao arrastar/redimensionar evento.
     * Recebe: (string $id, string $start, string $end, string $rowId)
     */
    public function onEventUpdate(string $method): static
    {
        $this->eventUpdateMethod = $method;
        return $this;
    }

    /**
     * Método chamado ao navegar (prev/next/today).
     * Recebe: (string $startDate, string $endDate)
     *
     * @deprecated O client v2 navega por scroll — nunca dispara reload.
     *             Mantido por compat.
     */
    public function onReload(string $method): static
    {
        $this->reloadMethod = $method;
        return $this;
    }

    /**
     * Template de popover para eventos.
     * Placeholders: {title}, {start}, {end}, {percent}, {rowId}
     */
    public function popover(string $title, string $content): static
    {
        $this->popTitle   = $title;
        $this->popContent = $content;
        return $this;
    }

    // ── Tasks hierarquicas (Fase 1) ──────────────────────────────────────

    /**
     * Adiciona uma tarefa ao Gantt.
     *
     * @param string $id    Identificador unico da task
     * @param array  $props Propriedades (chaves opcionais):
     *   - name      string  Nome exibido na sidebar/barra (obrigatorio)
     *   - parentId  string  ID da task pai (null = task raiz)
     *   - type      string  'task' (default) | 'summary' | 'milestone'
     *   - start     string  Data inicio Y-m-d ou Y-m-d H:i
     *   - end       string  Data fim Y-m-d ou Y-m-d H:i
     *   - date      string  Apenas para milestone — atalho start=end=date
     *   - progress  float   Fracao 0..1 OU 0-100 — resolvida por progressScale()
     *                       ('auto' default: >1 le como 0-100; declare 'percent'
     *                       ou 'fraction' pra remover a ambiguidade do valor 1)
     *   - color     string  #hex
     *   - owner     string  Responsavel (exibido em coluna)
     *   - duration  int     Duracao em dias (opcional, derivada de start/end)
     *   - expanded  bool    Se filhos comecam expandidos (default true)
     *   - resourceIds array IDs dos recursos atribuidos (Fase 3)
     *   - baseline  array   ['start' => '...', 'end' => '...'] (Fase 4)
     *   - extras    array   Dados extras passados ao client
     */
    public function addTask(string $id, array $props = []): static
    {
        // Milestone com 'date' vira start=end=date
        if (($props['type'] ?? '') === 'milestone' && !empty($props['date'])) {
            $props['start'] = $props['date'];
            $props['end']   = $props['date'];
        }
        // type=milestone implica o flag `milestone` — o client decide o
        // diamante por ele (o normalize do JS também cobre, cinto+suspensório).
        if (($props['type'] ?? '') === 'milestone' && !array_key_exists('milestone', $props)) {
            $props['milestone'] = true;
        }

        $this->tasks[] = array_merge([
            'id'          => $id,
            'name'        => '',
            'parentId'    => null,
            'type'        => 'task',
            'start'       => null,
            'end'         => null,
            'progress'    => 0,
            'color'       => null,
            'owner'       => null,
            'duration'    => null,
            'expanded'    => true,
            'resourceIds' => [],
        ], $props);

        return $this;
    }

    /**
     * Define todas as tasks de uma vez.
     */
    public function tasks(array $tasks): static
    {
        $this->tasks = $tasks;
        return $this;
    }

    /**
     * Fonte UNICA de verdade do mapeamento Record (Eloquent) -> shape JS de
     * uma task.
     *
     * Usado tanto no render (MadGanttComponent::mapTask) quanto nas ops
     * parciais (MadResponse::ganttUpsertRecord / ganttPatchRecord), pra que
     * "como um registro vira barra" exista em UM lugar so.
     *
     * $map e um mapa role => coluna. Roles reconhecidos (todos opcionais
     * exceto 'name'):
     *   id, name, start, end, progress, parentId, owner, color, type, code,
     *   milestone e phase. A 'phase' e emitida SOB O NOME DA COLUNA (ex:
     *   'phase_id') porque o agrupamento client casa com cfg.phaseField.
     *
     * Regras:
     *   - id/name/start/end emitem sempre que a coluna existir no map;
     *   - map SEM o role 'id' -> id = chave primaria do record (getKey() no
     *     Eloquent, ->id num objeto simples). O form de Gantt gerado passa so
     *     as colunas fora da convencao, nunca o id; sem ele o op
     *     gantt_upsert_task era descartado no cliente e a barra so mudava no F5;
     *   - progress -> float;
     *   - parentId -> null explicito quando vazio (JS trata como raiz);
     *   - owner/color/type/code/phase -> string, pulam quando vazio;
     *   - milestone truthy e != '0' -> milestone=true + type='milestone';
     *     senao -> milestone=false (permite "desmarcar" via upsert).
     *
     * @param object $record Record/model Eloquent (ou stdClass) de origem
     * @param array  $map    role => coluna; vazio usa defaultTaskMap()
     */
    public static function recordToTask(object $record, array $map = []): array
    {
        $map = $map ?: self::defaultTaskMap();

        // role => coluna (cada entrada pode ser string OU spec ['col'=>..]).
        $col = static fn(string $role): ?string => isset($map[$role])
            ? (is_array($map[$role]) ? ($map[$role]['col'] ?? null) : $map[$role])
            : null;
        $get = static fn(?string $c) => $c ? ($record->{$c} ?? null) : null;

        $task = [];

        if ($col('id')) {
            $task['id'] = (string) $get($col('id'));
        } else {
            $pk = $record instanceof \Illuminate\Database\Eloquent\Model ? $record->getKey() : ($record->id ?? null);
            if ($pk !== null && $pk !== '') $task['id'] = (string) $pk;
        }
        if ($col('name'))  $task['name']  = (string) ($get($col('name')) ?? '');
        if ($col('start')) $task['start'] = (string) ($get($col('start')) ?? '');
        if ($col('end'))   $task['end']   = (string) ($get($col('end')) ?? '');

        if ($col('progress')) {
            // barra usa unidade do banco (fração 0..1); 'scale' é só p/ o form.
            $task['progress'] = (float) ($get($col('progress')) ?? 0);
        }

        if ($col('parentId')) {
            $pid = $get($col('parentId'));
            $task['parentId'] = !empty($pid) ? (string) $pid : null;
        }

        foreach (['owner', 'color', 'code'] as $role) {
            if ($col($role)) {
                $v = $get($col($role));
                if ($v !== null && $v !== '') $task[$role] = (string) $v;
            }
        }

        // Fase: emitida sob o nome da coluna (cfg.phaseField casa com isso).
        if ($col('phase')) {
            $pv = $get($col('phase'));
            if ($pv !== null && $pv !== '') $task[$col('phase')] = (string) $pv;
        }

        // type: role explicito > milestone > default 'task'. Sempre emitido pra
        // que um upsert-insert de barra nova ja chegue com o tipo certo.
        $type = 'task';
        if ($col('type')) {
            $tv = $get($col('type'));
            if ($tv !== null && $tv !== '') $type = (string) $tv;
        }
        if ($col('milestone')) {
            $mv   = $get($col('milestone'));
            $isMs = !empty($mv) && (string) $mv !== '0';
            $task['milestone'] = $isMs;
            if ($isMs) $type = 'milestone';
        }
        $task['type'] = $type;

        return $task;
    }

    /** Mapa role => coluna default (convencao de nomes do GanttTask). */
    protected static function defaultTaskMap(): array
    {
        return [
            'id'        => 'id',
            'name'      => 'name',
            'start'     => 'start',
            'end'       => 'end',
            'progress'  => 'progress',
            'parentId'  => 'parent_id',
            'owner'     => 'owner_id',
            'code'      => 'code',
            'milestone' => 'milestone',
            'phase'     => 'phase_id',
        ];
    }

    /**
     * Labels do client que só vão quando o idioma do app os tem.
     *
     * O `lang/` é do APP: um app atualizado só no vendor (canal de update do
     * framework) não tem as chaves novas, e o `__()` devolveria a própria chave
     * — a dica mostraria "gantt.critical_badge". Sem tradução o label fica de
     * fora e o mad-gantt.js usa o default dele.
     *
     * @param  array<string, string> $map label do client => chave de tradução
     * @return array<string, string>
     */
    protected static function optionalLabels(array $map): array
    {
        $out = [];
        foreach ($map as $label => $key) {
            $text = __($key);
            if (is_string($text) && $text !== '' && $text !== $key) {
                $out[$label] = $text;
            }
        }

        return $out;
    }

    // ── Colunas da sidebar (Fase 1) ──────────────────────────────────────

    /**
     * Define colunas exibidas na sidebar TreeGrid.
     *
     * @param array $cols Cada coluna:
     *   - field   string  Campo da task (ex: 'name', 'start', 'owner', 'duration')
     *   - label   string  Rotulo do header
     *   - width   int     Largura em px (default 120)
     *   - format  string  'date' | 'datetime' | 'days' | 'percent' | null
     *   - tree    bool    Se true, coluna recebe indent + toggle expand/collapse
     *   - align   string  'left' | 'center' | 'right'
     *
     * Exemplo:
     *   $gantt->columns([
     *       ['field' => 'name',     'label' => 'Tarefa',   'width' => 240, 'tree' => true],
     *       ['field' => 'start',    'label' => 'Inicio',   'width' => 100, 'format' => 'date'],
     *       ['field' => 'duration', 'label' => 'Duracao',  'width' => 80,  'format' => 'days'],
     *       ['field' => 'owner',    'label' => 'Resp.',    'width' => 100],
     *   ]);
     */
    public function columns(array $cols): static
    {
        $this->columns = array_map(fn($c) => array_merge([
            'field'  => '',
            'source' => '',      // coluna do banco (vazio = usa field). Só p/ mapping server-side.
            'label'  => '',
            'width'  => 120,
            'format' => null,
            'tree'   => false,
            'align'  => 'left',
            'hidden' => false,   // mapping-only: não renderiza na sidebar
        ], $c), $cols);
        return $this;
    }

    // ── Dependencias (Fase 2) ────────────────────────────────────────────

    /**
     * Adiciona dependencia entre tasks.
     *
     * @param string $from   ID da task predecessora
     * @param string $to     ID da task sucessora
     * @param string $type   'FS' (Finish-to-Start, default) | 'SS' | 'FF' | 'SF'
     * @param int    $lag    Atraso em dias (opcional)
     */
    public function addDependency(string $from, string $to, string $type = 'FS', int $lag = 0): static
    {
        $this->dependencies[] = [
            'from' => $from,
            'to'   => $to,
            'type' => strtoupper($type),
            'lag'  => $lag,
        ];
        return $this;
    }

    /** Habilita auto-scheduling: mover predecessor empurra sucessores. */
    /** @deprecated Auto-schedule ainda não implementado no client v2 — config é ignorada. */
    public function enableAutoSchedule(bool $v = true): static
    {
        $this->autoSchedule = $v;
        return $this;
    }

    /** Destaca caminho critico (CPM). */
    public function showCriticalPath(bool $v = true): static
    {
        $this->criticalPath = $v;
        return $this;
    }

    /** Callback ao clicar em task. */
    public function onTaskClick(string $method): static
    {
        $this->taskClickMethod = $method;
        return $this;
    }

    /** Callback ao mover/editar task. Recebe (id, start, end, progress). */
    public function onTaskUpdate(string $method): static
    {
        $this->taskUpdateMethod = $method;
        return $this;
    }

    /** Callback ao criar dependencia via drag. */
    public function onDependencyCreate(string $method): static
    {
        $this->dependencyCreateMethod = $method;
        return $this;
    }

    /** Callback ao deletar dependencia. */
    /** @deprecated O client v2 não tem gesto de deleção de dependência — nunca dispara. */
    public function onDependencyDelete(string $method): static
    {
        $this->dependencyDeleteMethod = $method;
        return $this;
    }

    // ── Recursos (Fase 3) ────────────────────────────────────────────────

    /**
     * Adiciona recurso (pessoa/equipe).
     *
     * @param string $id    Identificador
     * @param array  $props ['name', 'role', 'capacity' (horas/dia), 'color', 'avatar']
     */
    public function addResource(string $id, array $props = []): static
    {
        $this->resources[] = array_merge([
            'id'       => $id,
            'name'     => '',
            'role'     => '',
            'capacity' => 8,
            'color'    => null,
            'avatar'   => null,
        ], $props);
        return $this;
    }

    /**
     * Atribui recurso a uma task.
     *
     * @param string   $taskId      ID da task
     * @param string   $resourceId  ID do recurso
     * @param int|null $hours       Horas alocadas (null = usar capacity do recurso)
     */
    public function assignResource(string $taskId, string $resourceId, ?int $hours = null): static
    {
        $this->assignments[] = [
            'taskId'     => $taskId,
            'resourceId' => $resourceId,
            'hours'      => $hours,
        ];
        return $this;
    }

    /** Exibe banda de workload abaixo do Gantt. */
    public function showWorkloadBand(bool $v = true): static
    {
        $this->showWorkload = $v;
        return $this;
    }

    /** Modo da banda: 'hours' | 'tasks' | 'toggle' (oferece radio button). */
    public function workloadMode(string $mode): static
    {
        $this->workloadMode = $mode;
        return $this;
    }

    // ── Working Calendar (Fase 4) ────────────────────────────────────────

    /**
     * Define dias uteis da semana.
     * @param array $days Array de int [0=Dom, 1=Seg, ..., 6=Sab]
     */
    public function workingDays(array $days): static
    {
        $this->workingDays = $days;
        return $this;
    }

    /**
     * Define feriados (datas nao-uteis).
     * @param array $dates Array de strings Y-m-d
     */
    public function holidays(array $dates): static
    {
        $this->holidays = $dates;
        return $this;
    }

    /**
     * Horario de trabalho.
     * @param int $startHour Hora inicio (0-23)
     * @param int $endHour   Hora fim (0-24)
     */
    public function workingHours(int $startHour, int $endHour): static
    {
        $this->workingHours = [$startHour, $endHour];
        return $this;
    }

    /** Habilita edicao inline (duplo-click) das celulas da sidebar. */
    public function enableInlineEdit(bool $v = true): static
    {
        $this->enableInlineEdit = $v;
        return $this;
    }

    /** Habilita multi-select (Ctrl+click) de tasks. */
    public function enableMultiSelect(bool $v = true): static
    {
        $this->enableMultiSelect = $v;
        return $this;
    }

    /**
     * Adiciona linha base (planejado vs realizado) para uma task.
     */
    public function addBaseline(string $taskId, string $start, string $end): static
    {
        $this->baselines[] = [
            'taskId' => $taskId,
            'start'  => $start,
            'end'    => $end,
        ];
        return $this;
    }

    // ── Output ───────────────────────────────────────────────────────────

    /**
     * Retorna [view, data] para uso em MadComponent::view().
     */
    public function view(): array
    {
        $startDate = $this->startDate ?: date('Y-m-d');

        // Ajustar startDate para início do mês se mode months
        if ($this->viewMode === self::MODE_MONTHS || $this->viewMode === self::MODE_MONTHS_WITH_DAY) {
            $start = new DateTime($startDate);
            $start->modify('first day of this month');
            $startDate = $start->format('Y-m-d');
        }

        // Calcular endDate
        $endDate = date('Y-m-d', strtotime("{$startDate} + {$this->interval} - 1 day"));

        if ($this->viewMode === self::MODE_MONTHS || $this->viewMode === self::MODE_MONTHS_WITH_DAY) {
            $end = new DateTime($endDate);
            $end->modify('last day of this month');
            $endDate = $end->format('Y-m-d');
        }

        // Gerar array de datas
        $dates = $this->generateDates($startDate, $endDate);

        // Hours para MODE_DAYS_WITH_HOUR
        $hours = $this->fullHoursOn
            ? ['00','01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23']
            : ['00','06','12','18'];

        // Detectar modo: se ha tasks ou columns, usa tree mode; senao legacy swimlanes
        $treeMode = !empty($this->tasks) || !empty($this->columns);

        // ── Legacy adapter ──────────────────────────────────────────────
        // Quando o builder so tem rows + events (uso classico
        // ->addRow()->addEvent()), traduz para o modelo novo:
        //   - cada row vira uma "fase" colorida
        //   - cada evento vira uma "task" com phase = rowId
        //   - phaseField = '__row' (auto, escapa de colisao com campo real)
        //   - title -> name, percent -> progress (0..1)
        $effectiveTasks      = $this->tasks;
        $effectivePhases     = $this->phases;
        $effectivePhaseField = $this->phaseField;

        if (empty($effectiveTasks) && !empty($this->events)) {
            $effectivePhaseField = $effectivePhaseField ?: '__row';

            if (empty($effectivePhases) && !empty($this->rows)) {
                // hue auto-spread por indice (mesmo set do utils/colors.js)
                $autoHues = [250, 290, 35, 195, 12, 155, 320, 70, 220, 0, 100, 270];
                $idx = 0;
                foreach ($this->rows as $r) {
                    $rid = (string) ($r['id'] ?? '');
                    if ($rid === '') continue;
                    $effectivePhases[] = [
                        'id'   => $rid,
                        'name' => (string) ($r['label'] ?? $rid),
                        'code' => strtoupper(substr($rid, 0, 3)),
                        'hue'  => $autoHues[$idx++ % count($autoHues)],
                    ];
                }
            }

            foreach ($this->events as $evt) {
                $task = [
                    'id'    => (string) ($evt['id'] ?? ''),
                    'name'  => (string) ($evt['title'] ?? $evt['name'] ?? ''),
                    'start' => (string) ($evt['start'] ?? ''),
                    'end'   => (string) ($evt['end']   ?? ''),
                    '__row' => (string) ($evt['rowId'] ?? ''),
                ];
                if (isset($evt['color']))   $task['color']   = (string) $evt['color'];
                if (isset($evt['percent'])) {
                    $p = (float) $evt['percent'];
                    $task['progress'] = $p > 1 ? $p / 100 : $p;
                }
                if (isset($evt['progress'])) $task['progress'] = (float) $evt['progress'];
                if (isset($evt['milestone'])) $task['milestone'] = (bool) $evt['milestone'];
                if (isset($evt['owner']))    $task['owner']     = (string) $evt['owner'];
                $effectiveTasks[] = $task;
            }
            // Em modo legado, sempre tree mode (tasks chegam a JS)
            $treeMode = true;
        }

        $config = [
            'viewMode'          => $this->viewMode,
            'zoom'              => $this->zoom,
            'startDate'         => $startDate,
            'endDate'           => $endDate,
            'interval'          => $this->interval,
            'dates'             => $dates,
            'hours'             => $hours,
            'title'             => $this->title,
            'id'                => $this->chartId,
            'locale'            => $this->locale,
            'progressScale'     => $this->progressScale,
            // Strings do client (stats/tooltip/bucket sem-fase) — i18n via lang/
            'labels'            => [
                'tasks'       => __('gantt.tasks'),
                'milestones'  => __('gantt.milestones'),
                'critical'    => __('gantt.critical_path'),
                'nodes'       => __('gantt.nodes'),
                'avgProgress' => __('gantt.avg_progress'),
                'today'       => __('gantt.today'),
                'noPhase'     => __('gantt.no_phase'),
                'owners'      => __('gantt.owners'),
                'dependsOn'   => __('gantt.depends_on'),
                'start'       => __('gantt.start'),
                'end'         => __('gantt.end'),
                'duration'    => __('gantt.duration'),
                'progress'    => __('gantt.progress'),
                'day'         => __('gantt.day'),
                'days'        => __('gantt.days'),
            ] + self::optionalLabels([
                // Selos da dica da barra — eram "CRITICAL"/"MILESTONE" fixos.
                'criticalTag'  => 'gantt.critical_badge',
                'milestoneTag' => 'gantt.milestone_badge',
            ]),
            'minutesStep'       => $this->minutesStep,
            'striped'           => $this->striped,
            'stripedRows'       => $this->stripedRows,
            'fullHours'         => $this->fullHoursOn,
            'compactEvents'     => $this->compactEventsOn,
            'showViewModeButton'=> $this->showViewModeBtn,
            'showZoomButton'    => $this->showZoomBtn,
            'treeMode'          => $treeMode,
            'rows'              => $this->rows,
            'events'            => $this->events,
            'tasks'             => $effectiveTasks,
            'columns'           => $this->columns,
            'dependencies'      => $this->dependencies,
            'resources'         => $this->resources,
            'assignments'       => $this->assignments,
            'baselines'         => $this->baselines,
            'workingDays'       => $this->workingDays,
            'holidays'          => $this->holidays,
            'workingHours'      => $this->workingHours,
            'autoSchedule'      => $this->autoSchedule,
            'criticalPath'      => $this->criticalPath,
            'showWorkload'      => $this->showWorkload,
            'workloadMode'      => $this->workloadMode,
            'enableInlineEdit'  => $this->enableInlineEdit,
            'enableMultiSelect' => $this->enableMultiSelect,
            'headerActions'     => $this->headerActions,
            'eventClickMethod'  => $this->eventClickMethod,
            'dayClickMethod'    => $this->dayClickMethod,
            'eventUpdateMethod' => $this->eventUpdateMethod,
            'reloadMethod'      => $this->reloadMethod,
            'taskClickMethod'        => $this->taskClickMethod,
            'taskUpdateMethod'       => $this->taskUpdateMethod,
            'dependencyCreateMethod' => $this->dependencyCreateMethod,
            'dependencyDeleteMethod' => $this->dependencyDeleteMethod,
            'popTitle'          => $this->popTitle,
            'popContent'        => $this->popContent,

            // v2 visual additions (safe to be present even when the
            // consumer is the legacy mad-gantt.js — Alpine will ignore
            // unknown keys).
            'phaseField'        => $effectivePhaseField,
            'phases'            => $effectivePhases,
            'people'            => $this->people,
            'arrowStyle'        => $this->arrowStyle,
            'density'           => $this->density,
            'showMinimap'       => $this->showMinimap,
            'showSearch'        => $this->showSearch,
            'showWeekends'      => $this->showWeekendsBg,
            'showGrid'          => $this->showGridLines,
            'showAvatars'       => $this->showAvatars,
            'taskColWidth'      => $this->taskColWidth,
        ];

        return ['components.gantt-chart', ['config' => $config]];
    }

    /**
     * Renderiza o HTML do gantt.
     */
    public function render(): string
    {
        [$view, $data] = $this->view();
        return MadBlade::render($view, $data);
    }

    // ── Helpers privados ─────────────────────────────────────────────────

    private function generateDates(string $start, string $end): array
    {
        $begin    = new DateTime($start);
        $endDt    = new DateTime($end);
        $endDt->modify('+1 day');

        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($begin, $interval, $endDt);

        $dates = [];
        foreach ($period as $date) {
            $dates[] = $date->format('Y-m-d');
        }
        return $dates;
    }
}
