<?php
namespace Mad\Calendar;

use Illuminate\Support\Facades\DB;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Http\MadResponse;
use Mad\Ui\MadToast;

/**
 * MadGanttComponent — Componente reativo de Gantt com auto-query Eloquent.
 *
 * Subclasse define o model + mapeamento de campos. A classe carrega as tarefas
 * via QuerySource (Eloquent), opcionalmente as dependencias e recursos, e monta
 * o MadGantt builder internamente.
 *
 * ┌─ Como usar ─────────────────────────────────────────────────────────────┐
 * │                                                                         │
 * │  class ProjetoGantt extends MadGanttComponent                           │
 * │  {                                                                      │
 * │      protected static string $wrapper = self::INTERNAL;                 │
 * │      protected string $model         = 'DocProjetoTarefa';              │
 * │      protected string $database      = 'business';                      │
 * │      protected string $nameField     = 'titulo';                       │
 * │      protected string $startField    = 'dt_inicio';                    │
 * │      protected string $endField      = 'dt_fim';                       │
 * │      protected string $parentField   = 'parent_id';                    │
 * │      protected string $progressField = 'percentual';                   │
 * │      protected string $colorField    = 'cor';                          │
 * │      protected string $ownerField    = 'responsavel';                  │
 * │                                                                         │
 * │      // Opcional — customizar a query das tarefas                       │
 * │      protected function buildQuery(): Builder { ... }                  │
 * │                                                                         │
 * │      // Opcional — view custom                                          │
 * │      protected function view(): string|array { return 'meu-gantt'; }   │
 * │  }                                                                      │
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
abstract class MadGanttComponent extends MadComponent
{
    // ── Model + conexao ──────────────────────────────────────────────────

    /** Classe do model Eloquent com as tarefas (short name ou FQCN). */
    protected string $model = '';

    /** Database connection. */
    protected string $database = '';

    /** Campo de ordenacao (default: campo de inicio). */
    protected string $orderBy = '';

    // ── Mapeamento de campos (ajuste conforme schema) ────────────────────

    protected string $idField        = 'id';
    protected string $nameField      = 'name';
    protected string $startField     = 'start';
    protected string $endField       = 'end';
    protected string $parentField    = '';        // vazio = sem hierarquia
    protected string $progressField  = '';
    protected string $colorField     = '';
    protected string $ownerField     = '';
    protected string $typeField      = '';        // task | summary | milestone
    protected string $codeField      = '';        // codigo curto exibido na sidebar
    protected string $milestoneField = '';        // bool — task vira milestone (diamante)
    protected string $resourceField  = '';        // CSV de resourceIds (ou nome de relacao hasMany)

    // ── Dependencias ─────────────────────────────────────────────────────

    /**
     * Como buscar dependencias entre tarefas.
     *
     * Opcoes:
     *  - '' (vazio) — sem dependencias
     *  - 'fk:nome_do_campo' — coluna FK na tabela de tarefas (FS, sem lag) referenciando predecessor
     *  - 'table:NomeModel,from=fk_from,to=fk_to,type=tipo,lag=campo_lag' — tabela separada de dependencias
     */
    protected string $dependencySource = '';

    // ── Recursos ─────────────────────────────────────────────────────────

    /** Classe do model com recursos. Vazio = sem recursos. */
    protected string $resourceModel = '';

    /** Campos do model de recursos. */
    protected string $resourceIdField       = 'id';
    protected string $resourceNameField     = 'name';
    protected string $resourceRoleField     = 'role';
    protected string $resourceCapacityField = 'capacity';

    /**
     * Tabela pivot de assignments (assignment ↔ resource).
     * Formato: 'NomeModelPivot,task=fk_task,resource=fk_resource,hours=campo_horas'
     */
    protected string $assignmentSource = '';

    // ── Configuracao visual ──────────────────────────────────────────────

    protected string $ganttTitle    = '';
    protected string $startDate     = '';
    protected string $interval      = '30 days';
    protected string $viewMode      = 'days';
    protected string $zoom          = 'md';
    /** Escala do progress no banco: auto | fraction (0..1) | percent (0-100). */
    protected string $progressScale = 'auto';
    protected bool   $criticalPath  = false;
    protected bool   $autoSchedule  = false;
    protected bool   $showWorkload  = false;
    protected string $workloadMode  = 'hours';
    protected bool   $stripedRows   = true;

    // v2 visual props — opcionais
    protected ?string $phaseField = null;
    protected string  $arrowStyle = 'elbow';
    protected string  $density    = 'comfortable';

    /**
     * Spec de auto-load das fases. Formato:
     *   'ModelName,order=ordem'        (carrega todas, ordenado por `ordem`)
     *   'ModelName,id=id,name=name,code=code,hue=hue,order=ordem'
     */
    protected string $phaseSource  = '';

    /**
     * Spec de auto-load das pessoas. Formato:
     *   'ModelName'
     *   'ModelName,id=id,name=name,initials=initials,color=color'
     */
    protected string $personSource = '';

    /** Colunas da sidebar. Override em buildColumns() pra customizar. */
    protected array $columns = [];

    /** Working calendar */
    protected array $workingDays  = [1,2,3,4,5];
    protected array $holidays     = [];

    /** HTML gerado do gantt — protected pra NAO virar bindable (evita emitir HTML gigante a cada action). */
    protected string $gantt = '';

    // ── Inline config (compiler <mad-gantt>) ─────────────────────────────

    /**
     * Props mutaveis pelo MadGanttCompiler. Capturadas como snapshot na
     * primeira renderizacao e resetadas a cada novo bloco <mad-gantt> pra
     * permitir multiplos blocos na mesma pagina sem vazamento de estado.
     */
    protected const COMPILER_ATTRS = [
        'model', 'database',
        'idField', 'nameField', 'startField', 'endField', 'parentField',
        'progressField', 'colorField', 'ownerField', 'typeField',
        'codeField', 'milestoneField', 'resourceField',
        'orderBy', 'dependencySource',
        'resourceModel', 'resourceIdField', 'resourceNameField',
        'resourceRoleField', 'resourceCapacityField',
        'assignmentSource',
        'ganttTitle', 'startDate', 'interval', 'viewMode', 'zoom', 'workloadMode',
        'progressScale',
        'criticalPath', 'autoSchedule', 'showWorkload', 'stripedRows',
        'workingDays', 'holidays', 'columns',
        // v2 visual
        'phaseField', 'arrowStyle', 'density',
        'phaseSource', 'personSource',
    ];

    /** Snapshot dos defaults (capturado uma vez por instancia). */
    protected array $_capturedDefaults = [];
    protected bool  $_defaultsCaptured = false;

    /** Config inline vinda do compiler (sub-tags, callbacks via attr, features). */
    protected ?array $_inlineConfig = null;

    /** Host externo quando standalone — callbacks roteam pra ele. */
    protected ?object $_externalHost = null;

    /** @internal — usado pelo MadGanttCompiler quando host nao e MadGanttComponent. */
    public function _setExternalHost(object $host): void
    {
        $this->_externalHost = $host;
    }

    // ── Hooks ────────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        if ($this->startDate === '') {
            $this->startDate = date('Y-m-d', strtotime('-3 days'));
        }
    }

    /**
     * Override pra customizar a query de busca das tarefas.
     * Aplica automaticamente filtros vindos do <mad-gantt> declarativo
     * (attr `:filters`, sub-tag `<mad-gantt-filter>`).
     * A ordenacao acontece no recordsFromQuery.
     */
    protected function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = $this->_modelQuery($this->model);
        $this->_applyInlineQuery($q);
        return $q;
    }

    /**
     * `$model::query()` resolvendo short-name → FQCN (sem expr-on-`::`).
     * `$database` declarado aplica a conexão — antes só valia pra transaction
     * do onTaskUpdate e as LEITURAS ignoravam a prop silenciosamente.
     */
    private function _modelQuery(string $model)
    {
        $cls = $this->_resolveModel($model);
        return $this->database !== '' ? $cls::on($this->database) : $cls::query();
    }

    /**
     * Override pra definir colunas da sidebar.
     * Default: nome, inicio, fim, duracao, responsavel, progresso.
     */
    protected function buildColumns(): array
    {
        if (!empty($this->columns)) {
            return $this->columns;
        }
        $cols = [
            ['field' => 'name', 'label' => 'Tarefa', 'width' => 240, 'tree' => true],
        ];
        if ($this->startField) {
            $cols[] = ['field' => 'start', 'label' => 'Início', 'width' => 100, 'format' => 'date'];
        }
        if ($this->endField) {
            $cols[] = ['field' => 'end', 'label' => 'Fim', 'width' => 100, 'format' => 'date'];
        }
        $cols[] = ['field' => 'duration', 'label' => 'Dias', 'width' => 70, 'format' => 'days', 'align' => 'center'];
        if ($this->ownerField) {
            $cols[] = ['field' => 'owner', 'label' => 'Resp.', 'width' => 100];
        }
        if ($this->progressField) {
            $cols[] = ['field' => 'progress', 'label' => 'Prog.', 'width' => 80, 'format' => 'percent', 'align' => 'right'];
        }
        return $cols;
    }

    /**
     * Mapeia linha do model pra props da task (passadas a addTask()).
     *
     * Delega pro mapper canonico MadGantt::recordToTask() — fonte unica de
     * verdade compartilhada com as ops parciais (ganttUpsertRecord). O 'id'
     * sai do `id-field` (o taskFieldMap o declara), igual ao que buildGantt
     * passa em addTask($id, ...) — sem o role, o mapper cairia na chave
     * primaria e trocaria o id da barra quando o id-field nao e a PK.
     */
    protected function mapTask(object $record): array
    {
        return MadGantt::recordToTask($record, $this->taskFieldMap());
    }

    /**
     * Deriva os *Field props a partir das colunas declaradas — as sub-tags
     * <mad-gantt-column field=".." source=".."> sao a fonte do field-mapping.
     *
     * `field` e a chave canonica (name/start/end/progress/parentId/owner/code/
     * milestone/phase/id/color/type); `source` e a coluna do banco (default =
     * field). Os atributos name-field/start-field/etc, quando presentes no
     * $config, tem PRIORIDADE (override) — por isso so setamos o que nao veio
     * explicito. `duration` e calculada, nao mapeia coluna.
     */
    protected function _deriveFieldMapFromColumns(array $cols, array $config): void
    {
        $roleToProp = [
            'id'        => 'idField',
            'name'      => 'nameField',
            'start'     => 'startField',
            'end'       => 'endField',
            'parentId'  => 'parentField',
            'parent'    => 'parentField',
            'progress'  => 'progressField',
            'owner'     => 'ownerField',
            'code'      => 'codeField',
            'milestone' => 'milestoneField',
            'phase'     => 'phaseField',
            'color'     => 'colorField',
            'type'      => 'typeField',
        ];
        foreach ($cols as $c) {
            $prop = $roleToProp[$c['field']] ?? null;
            if ($prop === null) continue;                    // duration / desconhecido
            $explicit = array_key_exists($prop, $config) && $config[$prop] !== '' && $config[$prop] !== null;
            if (!$explicit) {
                $this->$prop = $c['source'];
            }
        }
    }

    /**
     * Mapa role => coluna derivado das props de field-mapping do componente
     * (que vem dos atributos da tag ou das colunas). Usado pelo mapTask (render);
     * exposto publico pra um host model-driven repassar ao form se precisar —
     * com o `id-field`, o op do form casa com a barra desenhada.
     */
    public function taskFieldMap(): array
    {
        $m = ['id' => $this->idField, 'name' => $this->nameField];
        if ($this->startField)     $m['start']     = $this->startField;
        if ($this->endField)       $m['end']       = $this->endField;
        if ($this->parentField)    $m['parentId']  = $this->parentField;
        if ($this->progressField)  $m['progress']  = $this->progressField;
        if ($this->colorField)     $m['color']     = $this->colorField;
        if ($this->ownerField)     $m['owner']     = $this->ownerField;
        if ($this->typeField)      $m['type']      = $this->typeField;
        if ($this->phaseField)     $m['phase']     = $this->phaseField;
        if ($this->codeField)      $m['code']      = $this->codeField;
        if ($this->milestoneField) $m['milestone'] = $this->milestoneField;
        return $m;
    }

    // ── Builder ──────────────────────────────────────────────────────────

    /**
     * Constroi o MadGantt builder com todas as tarefas, dependencias e recursos.
     */
    protected function buildGantt(): MadGantt
    {
        if (empty($this->model)) {
            throw new \RuntimeException('MadGanttComponent: $model nao definido.');
        }

        $database = $this->database ?: 'business';
        $gantt = MadGantt::make($this->startDate)
            ->viewMode($this->viewMode)
            ->zoom($this->zoom)
            ->interval($this->interval)
            ->title($this->ganttTitle)
            ->enableViewModeButton()
            ->enableZoomButton()
            ->columns($this->buildColumns())
            ->workingDays($this->workingDays)
            ->holidays($this->holidays);

        if ($this->stripedRows)   $gantt->stripedRows();
        if ($this->criticalPath)  $gantt->showCriticalPath();
        if ($this->autoSchedule)  $gantt->enableAutoSchedule();
        if ($this->showWorkload)  $gantt->showWorkloadBand()->workloadMode($this->workloadMode);
        if ($this->progressScale !== 'auto') $gantt->progressScale($this->progressScale);

        // Persistência de preferências client (zoom/expansão) — id estável.
        $sid = $this->ganttStorageId();
        if ($sid !== '') $gantt->id($sid);

        // Callbacks default ANTES do configureGantt/inline — a subclass e o
        // <mad-gantt> declarativo sobrescrevem sem serem clobberados depois
        // (o view() antigo re-setava por cima do que a subclass registrou).
        $gantt->onTaskUpdate('onTaskUpdate');

        // 0. Fases / pessoas auto-load (v2)
        if ($this->phaseSource)  $this->loadPhases($gantt);
        if ($this->personSource) $this->loadPersons($gantt);

        // 1. Tarefas
        $records = QuerySource::recordsFromQuery($this->buildQuery(), $this->orderBy ?: $this->startField);
        $taskIds = [];
        foreach ($records as $r) {
            $id = (string) $r->{$this->idField};
            $taskIds[$id] = true;
            $props = $this->mapTask($r);
            $gantt->addTask($id, $props);
        }

        // 2. Dependencias (reusa os records da query acima no modo fk:)
        if ($this->dependencySource) {
            $this->loadDependencies($gantt, $taskIds, $records);
        }

        // 3. Recursos
        if ($this->resourceModel) {
            $this->loadResources($gantt);
        }

        // 4. Assignments
        if ($this->assignmentSource) {
            $this->loadAssignments($gantt);
        }

        // Hook final — subclass pode adicionar callbacks, header actions, etc
        $this->configureGantt($gantt);

        // Sobreposicao automatica de config vinda do <mad-gantt> declarativo
        // (callbacks via attr, features booleanas, header-actions, baselines,
        // popover). Roda DEPOIS do configureGantt pra permitir override total
        // pela subclass se quiser.
        $this->_applyInlineConfigToBuilder($gantt);

        return $gantt;
    }

    /**
     * Hook chamado apos carregar tarefas/deps/recursos.
     * Override pra customizar callbacks, header actions, etc.
     */
    protected function configureGantt(MadGantt $gantt): void
    {
        // Default: nada — subclass adiciona conforme necessario
    }

    /** Carrega dependencias conforme `$dependencySource`. */
    protected function loadDependencies(MadGantt $gantt, array $taskIds, ?array $records = null): void
    {
        $src = $this->dependencySource;
        if (str_starts_with($src, 'fk:')) {
            $fkField = substr($src, 3);
            // Reusa os records ja carregados pelo buildGantt — re-executar a
            // query duplicava o hit no banco a cada render.
            $records ??= QuerySource::recordsFromQuery($this->buildQuery());
            foreach ($records as $r) {
                $predId = $r->{$fkField} ?? null;
                if (empty($predId)) continue;
                $predIdStr = (string) $predId;
                $sucIdStr  = (string) $r->{$this->idField};
                if (!isset($taskIds[$predIdStr]) || !isset($taskIds[$sucIdStr])) continue;
                $gantt->addDependency($predIdStr, $sucIdStr, 'FS');
            }
        } elseif (str_starts_with($src, 'table:')) {
            $cfg = $this->parseSourceSpec(substr($src, 6));
            $model = $cfg['_model'] ?? '';
            if (!$model) return;
            $fromField = $cfg['from'] ?? 'from_id';
            $toField   = $cfg['to']   ?? 'to_id';
            $typeField = $cfg['type'] ?? null;
            $lagField  = $cfg['lag']  ?? null;
            $deps = QuerySource::recordsFromQuery($this->_modelQuery($model));
            foreach ($deps as $d) {
                $from = (string) ($d->{$fromField} ?? '');
                $to   = (string) ($d->{$toField} ?? '');
                if (!$from || !$to) continue;
                $type = $typeField ? (string) ($d->{$typeField} ?? 'FS') : 'FS';
                $lag  = $lagField  ? (int)    ($d->{$lagField}  ?? 0)    : 0;
                $gantt->addDependency($from, $to, $type, $lag);
            }
        }
    }

    /**
     * Converte sub-tag attrs (camelCase) num source string "Model,k=v,k=v"
     * compativel com phaseSource/personSource/dependencySource.
     *
     * $paramMap = ['attr-kebab' => 'sourceKey'] — define quais attrs sao
     * propagadas e qual o nome da chave no formato source.
     */
    protected static function buildSourceString(array $attrs, array $paramMap): string
    {
        $model = (string) ($attrs['model'] ?? '');
        if ($model === '') return '';
        $parts = [$model];
        foreach ($paramMap as $attrKebab => $sourceKey) {
            $attrCamel = self::sKebabToCamel($attrKebab);
            $val = $attrs[$attrCamel] ?? null;
            if ($val !== null && $val !== '') {
                $parts[] = $sourceKey . '=' . $val;
            }
        }
        return implode(',', $parts);
    }

    protected static function sKebabToCamel(string $k): string
    {
        if (strpos($k, '-') === false) return $k;
        return lcfirst(str_replace('-', '', ucwords($k, '-')));
    }

    /** Carrega fases conforme `$phaseSource = 'ModelName,order=...'`. */
    protected function loadPhases(MadGantt $gantt): void
    {
        $cfg = $this->parseSourceSpec($this->phaseSource);
        $model = $cfg['_model'] ?? '';
        if (!$model) return;
        $idF    = $cfg['id']    ?? 'id';
        $nameF  = $cfg['name']  ?? 'name';
        $codeF  = $cfg['code']  ?? 'code';
        $hueF   = $cfg['hue']   ?? 'hue';
        $orderF = $cfg['order'] ?? 'ordem';

        $rows = QuerySource::recordsFromQuery($this->_modelQuery($model), $orderF ?: null);
        $list = [];
        foreach ($rows as $r) {
            $id = (string) ($r->{$idF} ?? '');
            if ($id === '') continue;
            $list[] = [
                'id'   => $id,
                'name' => (string) ($r->{$nameF} ?? ''),
                'code' => (string) ($r->{$codeF} ?? ''),
                'hue'  => (int)    ($r->{$hueF}  ?? 250),
            ];
        }
        if ($list) $gantt->phases($list);
    }

    /** Carrega pessoas conforme `$personSource = 'ModelName'`. */
    protected function loadPersons(MadGantt $gantt): void
    {
        $cfg = $this->parseSourceSpec($this->personSource);
        $model = $cfg['_model'] ?? '';
        if (!$model) return;
        $idF       = $cfg['id']       ?? 'id';
        $nameF     = $cfg['name']     ?? 'name';
        $initialsF = $cfg['initials'] ?? 'initials';
        $colorF    = $cfg['color']    ?? 'color';

        $rows = QuerySource::recordsFromQuery($this->_modelQuery($model));
        $map = [];
        foreach ($rows as $r) {
            $id = (string) ($r->{$idF} ?? '');
            if ($id === '') continue;
            $map[$id] = [
                'name'     => (string) ($r->{$nameF}     ?? $id),
                'initials' => (string) ($r->{$initialsF} ?? strtoupper(substr($id, 0, 2))),
                'color'    => (string) ($r->{$colorF}    ?? '#3b82f6'),
            ];
        }
        if ($map) $gantt->people($map);
    }

    /** Carrega recursos do `$resourceModel`. */
    protected function loadResources(MadGantt $gantt): void
    {
        $rq = $this->_modelQuery($this->resourceModel);
        $this->_applyRowsToQuery($rq, $this->_inlineConfig['resourceFilter'] ?? []);
        $resources = QuerySource::recordsFromQuery($rq);
        foreach ($resources as $r) {
            $id = (string) $r->{$this->resourceIdField};
            $gantt->addResource($id, [
                'name'     => (string) ($r->{$this->resourceNameField} ?? ''),
                'role'     => (string) ($r->{$this->resourceRoleField} ?? ''),
                'capacity' => (int)    ($r->{$this->resourceCapacityField} ?? 8),
            ]);
        }
    }

    /** Carrega assignments (pivot task-resource). */
    protected function loadAssignments(MadGantt $gantt): void
    {
        $cfg = $this->parseSourceSpec($this->assignmentSource);
        $model = $cfg['_model'] ?? '';
        if (!$model) return;
        $taskField = $cfg['task'] ?? 'task_id';
        $resField  = $cfg['resource'] ?? 'resource_id';
        $hoursField = $cfg['hours'] ?? null;
        $items = QuerySource::recordsFromQuery($this->_modelQuery($model));
        foreach ($items as $a) {
            $tid = (string) ($a->{$taskField} ?? '');
            $rid = (string) ($a->{$resField}  ?? '');
            if (!$tid || !$rid) continue;
            $hours = $hoursField ? (int) ($a->{$hoursField} ?? 0) : null;
            $gantt->assignResource($tid, $rid, $hours);
        }
    }

    /**
     * Parsea spec tipo 'ModelName,key1=val1,key2=val2'.
     */
    protected function parseSourceSpec(string $spec): array
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

    /**
     * Resolve short name (ex: 'DocProjetoTarefa') → FQCN (App\Models\...).
     * Mantém o nome original quando a resolução falha — o erro real (se
     * houver) estoura adiante, como no legado.
     */
    private function _resolveModel(string $model): string
    {
        if ($model !== '' && !class_exists($model)) {
            try {
                $model = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            } catch (\Throwable $e) {
                // mantém o original; erro real (se houver) aparece downstream
            }
        }
        return $model;
    }

    // ── Ações default ────────────────────────────────────────────────────

    /**
     * Handler default do drag (payload do wire: task_id/start/end/mode).
     * $task_id casa com a chave do payload — o antigo `$id` nunca resolvia e o
     * _resolveAndCall caía no fallback de array (TypeError em toda persistência).
     *
     * O id vem do navegador. A busca é pela consulta do próprio Gantt
     * (_visibleRecord → buildQuery): só se reagenda tarefa que ele mostra —
     * com `find()`, a chave de uma tarefa que o `buildQuery()` da tela deixa de
     * fora era reagendada do mesmo jeito.
     */
    public function onTaskUpdate(string $task_id, string $start, string $end, $progress = null): MadResponse
    {
        try {
            $rec = $this->model !== '' ? $this->_visibleRecord($task_id) : null;
            if (!$rec) {
                // Nada foi gravado. O Gantt é redesenhado para a barra voltar ao
                // lugar (ou sumir, se a tarefa não é dele).
                $this->forceFullRender();

                return MadToast::warning(mad_t('mad.gantt_task_gone'));
            }
            $saved = true;
            // Transaction na conexão REAL do model — usar $this->database aqui
            // deixava o save() fora da transação quando as conexões divergiam.
            $conn = $rec->getConnectionName() ?: ($this->database ?: 'business');
            DB::connection($conn)->transaction(function () use ($rec, $start, $end, $progress, &$saved) {
                // Zoom hora manda 'Y-m-d H:i:s' — só trunca quando é date-only.
                $norm = static fn (string $v): string => strlen($v) > 10 && !preg_match('/\d{2}:\d{2}/', $v)
                    ? substr($v, 0, 10)
                    : $v;
                if ($this->startField) $rec->{$this->startField} = $norm($start);
                if ($this->endField)   $rec->{$this->endField}   = $norm($end);
                if ($progress !== null && $progress !== '' && $this->progressField) {
                    $rec->{$this->progressField} = (float) $progress;
                }
                // save() devolve false quando o Model recusa (evento `saving`).
                $saved = $rec->save() !== false;
            });
            if (!$saved) {
                $this->forceFullRender();

                return MadToast::warning(mad_t('mad.gantt_update_refused'));
            }

            return MadToast::success("Atualizado");
        } catch (\Throwable $e) {
            return MadToast::danger(\Mad\Ui\MadUserError::isTechnical($e)
                ? \Mad\Ui\MadUserError::message($e, mad_t('mad.error.save_failed'), static::class . '::onTaskUpdate')
                : "Erro: " . $e->getMessage());
        }
    }

    /**
     * A tarefa `$id`, se for uma que ESTE Gantt mostra a quem está logado: a
     * consulta das tarefas (`buildQuery()` — recortes do Model, `:filters`,
     * `<mad-gantt-filter>` e o que a tela acrescenta) restrita à chave do
     * `id-field`, que é o id que a barra leva ao navegador.
     */
    protected function _visibleRecord(int|string $id): ?object
    {
        $q   = $this->buildQuery();
        $key = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $this->idField) === 1
            ? $this->idField
            : $q->getModel()->getKeyName();

        return $q->where($q->getModel()->qualifyColumn($key), '=', $id)->first();
    }

    public function onReload(string $startDate, string $endDate): void
    {
        $this->startDate = $startDate;
    }

    // Nao usar _needsFullRender = true aqui (forca HTML inteiro em toda action).
    // Subclass pode overridar caso precise.

    // ── Compiler entry point ─────────────────────────────────────────────

    /**
     * @internal Chamado pelo PHP gerado pelo MadGanttCompiler.
     * Aplica config inline (atributos do <mad-gantt> + sub-tags) e devolve
     * o HTML do Gantt pronto.
     */
    public function _renderInlineGantt(array $config): string
    {
        $this->_captureDefaults();
        $this->_resetToDefaults();
        $this->_applyInlineConfig($config);

        if ($this->startDate === '') {
            $this->startDate = date('Y-m-d', strtotime('-3 days'));
        }

        return $this->buildGantt()->render();
    }

    protected function _captureDefaults(): void
    {
        if ($this->_defaultsCaptured) return;
        foreach (self::COMPILER_ATTRS as $name) {
            if (property_exists($this, $name)) {
                $this->_capturedDefaults[$name] = $this->{$name};
            }
        }
        $this->_defaultsCaptured = true;
    }

    protected function _resetToDefaults(): void
    {
        if (!$this->_defaultsCaptured) return;
        foreach ($this->_capturedDefaults as $name => $value) {
            $this->{$name} = $value;
        }
        $this->_inlineConfig = null;
    }

    /**
     * Aplica config do <mad-gantt> nas props correspondentes + extrai
     * sub-tags em $_inlineConfig (consumidos por buildQuery/_applyInlineQuery,
     * loadResources, _applyInlineConfigToBuilder).
     */
    protected function _applyInlineConfig(array $config): void
    {
        $stringMap = [
            'model','database',
            'idField','nameField','startField','endField','parentField',
            'progressField','colorField','ownerField','typeField',
            'codeField','milestoneField','resourceField',
            'orderBy','dependencySource',
            'resourceModel','resourceIdField','resourceNameField',
            'resourceRoleField','resourceCapacityField',
            'assignmentSource',
            'ganttTitle','startDate','interval','viewMode','zoom','workloadMode',
            'progressScale',
            // v2 visual
            'phaseField','arrowStyle','density',
            'phaseSource','personSource',
        ];
        foreach ($stringMap as $k) {
            if (array_key_exists($k, $config) && $config[$k] !== '' && $config[$k] !== null) {
                $this->$k = (string) $config[$k];
            }
        }

        $boolMap = ['criticalPath','autoSchedule','showWorkload','stripedRows'];
        foreach ($boolMap as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = $this->_truthy($config[$k]);
            }
        }

        // working-days: aceita csv "1,2,3,4,5" ou array PHP via :working-days
        if (isset($config['workingDays'])) {
            $wd = $config['workingDays'];
            if (is_string($wd)) {
                // filtra ANTES do intval — string vazia virava [0] (domingo-only)
                $wd = array_map('intval', array_values(array_filter(
                    array_map('trim', explode(',', $wd)),
                    static fn ($x) => $x !== ''
                )));
            }
            if (is_array($wd) && $wd !== []) {
                $this->workingDays = $wd;
            }
        }

        // holidays: attr csv "2026-01-01,2026-02-16" (documentado; antes era
        // silenciosamente ignorado — só a sub-tag <mad-gantt-holiday> valia).
        if (!empty($config['holidays']) && is_string($config['holidays'])) {
            $list = array_values(array_filter(array_map('trim', explode(',', $config['holidays']))));
            if ($list) {
                $this->holidays = array_values(array_unique(array_merge($this->holidays, $list)));
            }
        }

        // holidays via sub-tag <mad-gantt-holiday date="..."/>
        if (isset($config['holidayItems']) && is_array($config['holidayItems'])) {
            $list = [];
            foreach ($config['holidayItems'] as $h) {
                if (!empty($h['date'])) $list[] = (string) $h['date'];
            }
            if ($list) {
                $this->holidays = array_values(array_unique(array_merge($this->holidays, $list)));
            }
        }

        // v2: source sub-tags — convertem pra string format dos *Source props
        if (!empty($config['phasesSource']) && is_array($config['phasesSource'])) {
            $this->phaseSource = self::buildSourceString($config['phasesSource'], [
                'id-field' => 'id', 'name-field' => 'name',
                'code-field' => 'code', 'hue-field' => 'hue',
                'order-by' => 'order',
            ]);
        }
        if (!empty($config['personsSource']) && is_array($config['personsSource'])) {
            $this->personSource = self::buildSourceString($config['personsSource'], [
                'id-field'       => 'id',
                'name-field'     => 'name',
                'initials-field' => 'initials',
                'color-field'    => 'color',
            ]);
        }
        if (!empty($config['dependenciesSource']) && is_array($config['dependenciesSource'])) {
            $this->dependencySource = 'table:' . self::buildSourceString($config['dependenciesSource'], [
                'from' => 'from', 'to' => 'to', 'type' => 'type', 'lag' => 'lag',
            ]);
        }
        // v2: <mad-gantt-assignments model=".." task=".." resource=".." hours=".."/>
        if (!empty($config['assignmentsSource']) && is_array($config['assignmentsSource'])) {
            $this->assignmentSource = self::buildSourceString($config['assignmentsSource'], [
                'task' => 'task', 'resource' => 'resource', 'hours' => 'hours',
            ]);
        }

        // Resource — sub-tag <mad-gantt-resource> (singular, legado) ou
        // <mad-gantt-resources> (plural, v2). Ambas setam os mesmos props.
        $resourceAttrs = null;
        if (!empty($config['resourcesSource']) && is_array($config['resourcesSource'])) {
            $resourceAttrs = $config['resourcesSource'];
        } elseif (!empty($config['resource']) && is_array($config['resource'])) {
            $resourceAttrs = $config['resource'];
        }
        if ($resourceAttrs !== null) {
            $resMap = [
                'model'         => 'resourceModel',
                'idField'       => 'resourceIdField',
                'nameField'     => 'resourceNameField',
                'roleField'     => 'resourceRoleField',
                'capacityField' => 'resourceCapacityField',
            ];
            foreach ($resMap as $src => $dst) {
                if (isset($resourceAttrs[$src]) && $resourceAttrs[$src] !== '') {
                    $this->$dst = (string) $resourceAttrs[$src];
                }
            }
        }

        // Columns via sub-tag <mad-gantt-column .../>
        // As colunas sao a FONTE do field-mapping: cada uma traz `field` (chave
        // canonica) + `source` (coluna do banco, default = field). Derivamos os
        // *Field props a partir delas — os atributos name-field/etc viram
        // override (so aplicam quando declarados explicitamente).
        if (!empty($config['columns']) && is_array($config['columns'])) {
            $cols = [];
            foreach ($config['columns'] as $c) {
                if (!is_array($c)) continue;
                $field  = (string) ($c['field'] ?? '');
                $source = (isset($c['source']) && $c['source'] !== '') ? (string) $c['source'] : $field;
                $cols[] = [
                    'field'  => $field,
                    'source' => $source,
                    'label'  => (string) ($c['label'] ?? ''),
                    'width'  => isset($c['width']) ? (int) $c['width'] : 120,
                    'format' => (isset($c['format']) && $c['format'] !== '') ? (string) $c['format'] : null,
                    'tree'   => $this->_truthy($c['tree'] ?? false),
                    'align'  => isset($c['align']) && $c['align'] !== '' ? (string) $c['align'] : 'left',
                    'hidden' => $this->_truthy($c['hidden'] ?? false),
                ];
            }
            $this->columns = $cols;
            $this->_deriveFieldMapFromColumns($cols, $config);
        }

        // Guarda o restante pra uso em buildQuery/loadResources/buildGantt
        $this->_inlineConfig = [
            'filter'           => $config['filter']           ?? [],
            'filtersExpr'      => $config['filters']          ?? null,
            'resourceFilter'   => $config['resourceFilter']   ?? [],
            'headerActions'    => $config['headerActions']    ?? [],
            'baselines'        => $config['baselines']        ?? [],
            'callbacks' => [
                'onTaskClick'        => $config['onTaskClick']        ?? '',
                'onTaskUpdate'       => $config['onTaskUpdate']       ?? '',
                'onDayClick'         => $config['onDayClick']         ?? '',
                'onDependencyCreate' => $config['onDependencyCreate'] ?? '',
                'onDependencyDelete' => $config['onDependencyDelete'] ?? '',
                'onReload'           => $config['onReload']           ?? '',
            ],
            'features' => [
                'inlineEdit'    => $this->_optBool($config, 'inlineEdit'),
                'multiSelect'   => $this->_optBool($config, 'multiSelect'),
                'viewModeBtn'   => array_key_exists('viewModeBtn', $config) ? $this->_truthy($config['viewModeBtn']) : null,
                'zoomBtn'       => array_key_exists('zoomBtn',     $config) ? $this->_truthy($config['zoomBtn'])     : null,
                'stripedMonths' => $this->_optBool($config, 'stripedMonths'),
                'fullHours'     => $this->_optBool($config, 'fullHours'),
                'compactEvents' => $this->_optBool($config, 'compactEvents'),
                'minutesStep'   => $config['minutesStep']  ?? null,
                'locale'        => $config['locale']       ?? null,
                'workingHours'  => $config['workingHours'] ?? null,
            ],
            'popover' => [
                'title'   => $config['popoverTitle']   ?? '',
                'content' => $config['popoverContent'] ?? '',
                'body'    => $config['popoverBody']    ?? '', // base64
            ],
            'toolbar' => $config['toolbar'] ?? '', // base64 (consumido externamente — TODO render slot)

            // v2 visual
            'phases'        => $config['phases']        ?? [],
            'people'        => $config['people']        ?? [],
            // v2 source sub-tags <mad-gantt-phases/persons/dependencies>
            'phasesSource'       => $config['phasesSource']       ?? null,
            'personsSource'      => $config['personsSource']      ?? null,
            'dependenciesSource' => $config['dependenciesSource'] ?? null,
            'visualToggles' => [
                'showMinimap' => array_key_exists('showMinimap', $config) ? $this->_truthy($config['showMinimap']) : null,
                'showSearch'  => array_key_exists('showSearch',  $config) ? $this->_truthy($config['showSearch'])  : null,
                'showWeekends'=> array_key_exists('showWeekends',$config) ? $this->_truthy($config['showWeekends']): null,
                'showGrid'    => array_key_exists('showGrid',    $config) ? $this->_truthy($config['showGrid'])    : null,
                'showAvatars' => array_key_exists('showAvatars', $config) ? $this->_truthy($config['showAvatars']) : null,
                'taskColWidth'=> $config['taskColWidth'] ?? null,
            ],
        ];
    }

    /** Aplica filtros declarativos no builder das tarefas (F4d, 100% Query Builder). */
    protected function _applyInlineQuery($q): void
    {
        if (!$this->_inlineConfig) return;

        // :filters="[['campo','op','val'(,binds)], ...]"
        $filtersExpr = $this->_inlineConfig['filtersExpr'] ?? null;
        if (is_array($filtersExpr)) {
            QuerySource::applyArrayFilters($q, $filtersExpr);
        }

        // <mad-gantt-filter field=".." op=".." value=".." value2=".."/>
        $this->_applyRowsToQuery($q, $this->_inlineConfig['filter'] ?? []);
    }

    /** Aplica rows declarativas (field/op/value/value2) num builder — between/in/null/escalar. */
    protected function _applyRowsToQuery($q, $rows): void
    {
        if (!is_array($rows)) return;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $f = $row['field'] ?? null;
            if ($f === null) continue;
            $op = strtolower((string) ($row['op'] ?? '='));
            $v  = $this->_resolveFilterValue($row['value']  ?? null);
            $v2 = $this->_resolveFilterValue($row['value2'] ?? null);

            if (str_contains($op, 'between') && $v2 !== null && $v2 !== '') {
                str_contains($op, 'not') ? $q->whereNotBetween($f, [$v, $v2]) : $q->whereBetween($f, [$v, $v2]);
            } elseif ($op === 'in' && is_array($v)) {
                $q->whereIn($f, $v);
            } elseif (in_array($op, ['is', 'is not'], true) && $v === null) {
                $op === 'is' ? $q->whereNull($f) : $q->whereNotNull($f);
            } else {
                $q->where($f, $op === '!=' ? '<>' : $op, $v);
            }
        }
    }

    /** Resolve string 'null' literal pra null PHP. */
    protected function _resolveFilterValue($val)
    {
        if (is_string($val) && strtolower(trim($val)) === 'null') return null;
        return $val;
    }

    /**
     * Aplica config inline no MadGantt builder — roda DEPOIS de configureGantt
     * pra permitir override total da subclass.
     */
    protected function _applyInlineConfigToBuilder(MadGantt $gantt): void
    {
        if (!$this->_inlineConfig) return;
        $ic = $this->_inlineConfig;

        // Features
        $f = $ic['features'];
        if ($f['inlineEdit'])     $gantt->enableInlineEdit();
        if ($f['multiSelect'])    $gantt->enableMultiSelect();
        if ($f['stripedMonths'])  $gantt->stripedMonths();
        if ($f['fullHours'])      $gantt->fullHours();
        if ($f['compactEvents'])  $gantt->compactEvents();
        if ($f['viewModeBtn'] !== null) $gantt->enableViewModeButton((bool) $f['viewModeBtn']);
        if ($f['zoomBtn']     !== null) $gantt->enableZoomButton((bool) $f['zoomBtn']);
        if ($f['minutesStep'] !== null) $gantt->minutesStep((int) $f['minutesStep']);
        if (!empty($f['locale'])) $gantt->locale((string) $f['locale']);
        if (!empty($f['workingHours'])) {
            $wh = (string) $f['workingHours'];
            if (preg_match('/^(\d{1,2})(?::\d{1,2})?\s*-\s*(\d{1,2})(?::\d{1,2})?$/', $wh, $m)) {
                $gantt->workingHours((int) $m[1], (int) $m[2]);
            }
        }

        // Popover
        $p = $ic['popover'];
        if (!empty($p['body'])) {
            $body  = base64_decode((string) $p['body']);
            $title = $p['title'] !== '' ? (string) $p['title'] : '{title}';
            $gantt->popover($title, $body);
        } elseif ($p['title'] !== '' || $p['content'] !== '') {
            $gantt->popover((string) $p['title'], (string) $p['content']);
        }

        // Callbacks
        foreach ($ic['callbacks'] as $method => $value) {
            if (!empty($value)) {
                $gantt->{$method}((string) $value);
            }
        }

        // Header actions
        foreach ($ic['headerActions'] as $a) {
            if (!is_array($a)) continue;
            $label  = (string) ($a['label']  ?? '');
            $icon   = (string) ($a['icon']   ?? '');
            $method = (string) ($a['method'] ?? '');
            if ($label !== '' && $method !== '') {
                $gantt->addHeaderAction($label, $icon, $method);
            }
        }

        // Baselines
        foreach ($ic['baselines'] as $b) {
            if (!is_array($b)) continue;
            $tid   = (string) ($b['taskId'] ?? '');
            $start = (string) ($b['start']  ?? '');
            $end   = (string) ($b['end']    ?? '');
            if ($tid !== '' && $start !== '' && $end !== '') {
                $gantt->addBaseline($tid, $start, $end);
            }
        }

        // v2: phases inline (sub-tag <mad-gantt-phase>).
        // DB tem prioridade: se phaseSource setado, loadPhases() ja populou —
        // ignora o inline pra nao sobrescrever.
        if (empty($this->phaseSource) && !empty($ic['phases']) && is_array($ic['phases'])) {
            $list = [];
            foreach ($ic['phases'] as $p) {
                if (!is_array($p)) continue;
                $list[] = [
                    'id'   => (string) ($p['id']   ?? ''),
                    'name' => (string) ($p['name'] ?? ''),
                    'code' => (string) ($p['code'] ?? ''),
                    'hue'  => isset($p['hue']) ? (int) $p['hue'] : 250,
                ];
            }
            if ($list) $gantt->phases($list);
        }

        // v2: people inline (sub-tag <mad-gantt-person>).
        // DB tem prioridade: se personSource setado, loadPersons() ja populou.
        if (empty($this->personSource) && !empty($ic['people']) && is_array($ic['people'])) {
            $map = [];
            foreach ($ic['people'] as $p) {
                if (!is_array($p)) continue;
                $id = (string) ($p['id'] ?? '');
                if ($id === '') continue;
                $map[$id] = [
                    'name'     => (string) ($p['name']     ?? $id),
                    'initials' => (string) ($p['initials'] ?? strtoupper(substr($id, 0, 2))),
                    'color'    => (string) ($p['color']    ?? '#3b82f6'),
                ];
            }
            if ($map) $gantt->people($map);
        }

        // v2: visual toggles
        $vt = $ic['visualToggles'] ?? [];
        if ($vt['showMinimap']  !== null) $gantt->showMinimap((bool) $vt['showMinimap']);
        if ($vt['showSearch']   !== null) $gantt->showSearch((bool) $vt['showSearch']);
        if ($vt['showWeekends'] !== null) $gantt->showWeekends((bool) $vt['showWeekends']);
        if ($vt['showGrid']     !== null) $gantt->showGridLines((bool) $vt['showGrid']);
        if ($vt['showAvatars']  !== null) $gantt->showAvatars((bool) $vt['showAvatars']);
        if (!empty($vt['taskColWidth'])) $gantt->taskColWidth((int) $vt['taskColWidth']);

        // Pass-through das props v2 set diretamente no subclass via prop
        if (!empty($this->phaseField ?? null) && method_exists($gantt, 'phaseField')) {
            $gantt->phaseField((string) $this->phaseField);
        }
        if (!empty($this->arrowStyle ?? null) && method_exists($gantt, 'arrowStyle')) {
            $gantt->arrowStyle((string) $this->arrowStyle);
        }
        if (!empty($this->density ?? null) && method_exists($gantt, 'density')) {
            $gantt->density((string) $this->density);
        }
    }

    private function _truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v))  return $v !== 0;
        $s = strtolower(trim((string) $v));
        return !in_array($s, ['', '0', 'false', 'no', 'off'], true);
    }

    private function _optBool(array $cfg, string $k, bool $default = false): bool
    {
        if (!array_key_exists($k, $cfg)) return $default;
        return $this->_truthy($cfg[$k]);
    }

    // ── View ─────────────────────────────────────────────────────────────

    /**
     * Id estável usado pelo client como chave de persistência de preferências
     * (sessionStorage). Default: nome curto da classe. Override pra separar
     * múltiplos gantts do mesmo control; string vazia = client deriva da rota.
     */
    protected function ganttStorageId(): string
    {
        return 'gantt-' . strtolower((new \ReflectionClass($this))->getShortName());
    }

    /**
     * View default — pode ser sobrescrita por subclass.
     * Renderiza o Gantt no template e disponibiliza como $gantt na view.
     * Callbacks default são registrados no buildGantt() ANTES do
     * configureGantt — nada é sobrescrito aqui.
     */
    protected function view(): string|array
    {
        $this->gantt = $this->buildGantt()->render();
        return ['components.gantt-component-default', ['gantt' => $this->gantt]];
    }
}
