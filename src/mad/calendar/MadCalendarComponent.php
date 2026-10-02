<?php
namespace Mad\Calendar;

use Mad\Component\MadComponent;
use Illuminate\Support\Facades\DB;
use Mad\Database\QuerySource;
use Mad\Filters\MadFilterable;
use Mad\Filters\MadFiltersTrait;
use Mad\Http\MadResponse;
use Mad\Ui\MadMessage;
use Mad\Ui\MadToast;
use Mad\View\MadBlade;

/**
 * MadCalendarComponent — calendario reativo Blade-first.
 *
 * Subclass declara behaviors (hooks) e a config inteira mora no Blade via
 * <mad-calendar model="..." title-field="..." start-field="..." ...>.
 *
 * ┌─ Como usar — caso minimo (auto-query) ──────────────────────────────────┐
 * │                                                                         │
 * │  class AgendaCalendar extends MadCalendarComponent {}                   │
 * │                                                                         │
 * │  // agenda-calendar.blade.php                                           │
 * │  <mad-calendar                                                          │
 * │      model="Evento" database="business"                                  │
 * │      title-field="titulo" start-field="data_inicio" end-field="data_fim"│
 * │      color-field="cor" default-view="agendaWeek" editable               │
 * │      event-form="EventoForm"                                            │
 * │      period-type="date-range" date-field="data_inicio" remember-filters/>│
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * `event-form="EventoForm"` liga o formulário dos eventos num atributo só:
 * clique no evento → EventoForm::onEdit({id}); clique em horário vazio →
 * EventoForm::onCreate({date}); botão "Novo" na toolbar. `click-target` /
 * `day-click-target` explícitos continuam valendo e ganham do `event-form`.
 *
 * Subclass adiciona PHP somente pra:
 *   - onSearch()              — busca custom (id-or-text, regras de negocio)
 *   - buildQuery()            — filtros derivados de props publicas (mount)
 *   - mapEvent()              — composicao custom de titulo/cor/payload
 *   - loadEvents()            — fonte de eventos custom (sem auto-query)
 *   - beforeEventUpdate()     — autorizar/bloquear drag/resize
 *   - afterEventUpdate()      — auditoria pos-save
 *   - onEventClick/DayClick/SlotClick — override total dos callbacks
 *   - onLimpar()              — limpar props publicas extras
 */
abstract class MadCalendarComponent extends MadComponent implements MadFilterable
{
    use MadFiltersTrait;

    // ── Wrapper default ──────────────────────────────────────────────────
    protected static string $wrapper = self::INTERNAL;

    // ── Data source ──────────────────────────────────────────────────────

    protected string $model       = '';
    protected string $database    = '';
    protected string $idField     = 'id';
    protected string $titleField  = 'title';
    protected string $startField  = 'start';
    protected string $endField    = 'end';
    protected string $colorField  = '';
    /** Coluna do model de evento com FK pro recurso. Emitida como `resourceId` no JSON do FullCalendar. */
    protected string $resourceField = '';
    /** Coluna booleana — evento dia inteiro. Emitida como `allDay` no JSON. */
    protected string $allDayField   = '';
    /** Coluna booleana — sobrescreve `editable` por evento. Emitida como `editable` no JSON. */
    protected string $editableField = '';
    protected string $color       = '#3b82f6';
    /** JSON: {"field":"tipo","map":{"A":"#3b82f6","B":"#f59e0b"}} */
    protected string $colorMap    = '';
    /** CSV de campos extras serializados no payload do evento. */
    protected string $extraFields = '';
    protected string $orderBy     = '';
    /** Escape hatch — bypass auto-query. Quando vazio, MadCalendarComponent fabrica URL apontando pra ::getEvents(). */
    protected string $eventsUrl   = '';

    // ── View / periodo ───────────────────────────────────────────────────

    protected string $defaultView    = 'dayGridMonth';
    protected string $timeRange      = '00:00-24:00';
    protected string $enableDays     = '0,1,2,3,4,5,6';
    protected bool   $noWeekend      = false;
    protected string $locale         = 'pt-br';
    protected string $currentDate    = '';
    protected string $slotDuration   = '01:00';
    protected int    $numDays        = 4;

    // ── Display ──────────────────────────────────────────────────────────

    protected int    $height         = 0;
    protected bool   $fullHeight     = false;
    protected string $calendarId     = '';
    protected string $header         = '';
    /** Pass-through de opcoes FullCalendar (JSON ou array). Ex: '{"slotDuration":"00:30:00","nowIndicator":true}'. */
    protected string $extraOptions   = '';

    // ── Editing / drag-drop ──────────────────────────────────────────────

    protected bool   $editable        = false;
    protected bool   $noDragging      = false;
    protected bool   $noResizing      = false;
    protected bool   $autoUpdate      = true;
    protected string $confirmUpdate   = '';

    // ── Click / callbacks ────────────────────────────────────────────────

    protected string $clickTarget        = '';
    protected string $clickTargetMode    = '';
    protected string $dayClickTarget     = '';
    protected string $slotClickTarget    = '';
    /**
     * Formulário dos eventos (classe em gaveta/modal). Atalho para os três
     * cliques — ver docblock da classe. Vazio = calendário só de visualização.
     */
    protected string $eventForm          = '';
    protected string $eventUpdateMethod  = 'onEventUpdate';
    protected string $dayClickMethod     = 'onDayClick';
    protected string $eventClickMethod   = 'onEventClick';
    protected string $slotClickMethod    = 'onSlotClick';

    // ── Popover ──────────────────────────────────────────────────────────

    protected string $popoverTitle   = '';
    protected string $popoverContent = '';
    protected string $popoverTrigger = 'hover';

    // ── Resource timeline ────────────────────────────────────────────────

    protected string $resourceModel       = '';
    protected string $resourceDatabase    = '';
    protected string $resourceIdField     = 'id';
    protected string $resourceTitleField  = 'name';
    protected string $resourceColorField  = '';
    protected string $resourceLabel       = 'Recurso';
    protected string $resourceOrderBy     = '';

    // ── MadFiltersTrait — props esperadas pelo trait ────────────────────

    protected string $periodType            = 'none';
    protected string $dateField             = '';
    protected array  $periodFields          = ['mes' => 'mes', 'ano' => 'ano'];
    protected bool   $defaultToCurrentPeriod = false;
    protected bool   $usePresets            = false;
    protected bool   $rememberFilters       = false;
    protected bool   $applyUnitFilter       = false;
    protected string $unitField             = 'unit_id';
    protected array  $unitFields            = [];
    protected array  $skipAutoFilter        = [];
    protected bool   $autoMergeDashFilters  = true;

    // Auto-discovery de props publicas como Filter segue o default do trait —
    // mesmo contrato de Kanban/Listagem/Dashboard (prop publica = coluna).
    // Props de state que nao sao coluna (negociacaoId etc) sao ignoradas pelo
    // guard modelHasColumn() em applyAutoFilters(); pra colisao real de nome,
    // use $skipAutoFilter. buildQuery() passa o FQCN resolvido pro guard
    // funcionar com short-names ('TesteEvento' → App\Models\TesteEvento).

    // ── Inline config (vinda do MadCalendarCompiler) ─────────────────────

    /**
     * Config extraida do <mad-calendar> declarativo.
     * Estrutura: ['toolbar' => 'base64', 'popover' => 'base64',
     *             'resource' => [...], 'filter' => [[...], ...],
     *             'resourceFilter' => [[...], ...]]
     */
    protected ?array $_inlineConfig = null;


    /** Indica se _renderInlineCalendar ja rodou (evita dupla aplicacao). */
    private bool $_configApplied = false;

    /** Snapshot de defaults capturado pre-render — pra reset entre blocos. */
    private array $_capturedDefaults = [];
    private bool  $_defaultsCaptured = false;

    /** Props que o compiler pode setar. Reset entre blocos do showcase. */
    private const COMPILER_ATTRS = [
        'model', 'database', 'idField', 'titleField', 'startField', 'endField',
        'colorField', 'resourceField', 'allDayField', 'editableField', 'color', 'colorMap', 'extraFields', 'orderBy', 'eventsUrl',
        'defaultView', 'timeRange', 'enableDays', 'noWeekend', 'locale', 'currentDate',
        'slotDuration', 'numDays', 'height', 'fullHeight', 'calendarId', 'header', 'extraOptions',
        'editable', 'noDragging', 'noResizing', 'autoUpdate', 'confirmUpdate',
        'clickTarget', 'clickTargetMode', 'dayClickTarget', 'slotClickTarget', 'eventForm',
        'eventUpdateMethod', 'dayClickMethod', 'eventClickMethod', 'slotClickMethod',
        'popoverTitle', 'popoverContent', 'popoverTrigger',
        'resourceModel', 'resourceDatabase', 'resourceIdField', 'resourceTitleField',
        'resourceColorField', 'resourceLabel', 'resourceOrderBy',
        'periodType', 'dateField', 'periodFields', 'rememberFilters',
        'defaultToCurrentPeriod', 'usePresets', 'applyUnitFilter', 'unitField', 'unitFields',
    ];

    /** HTML gerado do calendario — protected pra NAO virar bindable. */
    protected string $calendar = '';

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        $this->_initFiltersForm();
        if ($this->defaultToCurrentPeriod && $this->mes === '' && $this->ano === '') {
            $this->mes = date('m');
            $this->ano = date('Y');
        }
        $this->loadFilterSession();
        $req = array_merge($_GET ?? [], $_POST ?? []);
        $this->hydrateFiltersFromArray($req);
        $this->hydrateFiltersFromArray($params);
        $this->syncFormFields();
    }

    public function updated(string $prop, mixed $value): void
    {
        parent::updated($prop, $value);
        $this->_filtersUpdatedHook($prop, $value);
    }

    /**
     * Restaura config do <mad-calendar> em toda requisicao AJAX.
     *
     * Props de config (model, startField, etc) sao protected — nao serializam
     * no mad_state. Sem essa restauracao, actions como onEventUpdate enxergam
     * defaults vazios e abortam com "Drag handler nao configurado".
     */
    public function hydrate(): void
    {
        parent::hydrate();
        $this->_restoreConfigFromSession();
    }

    /**
     * Hook do MadFiltersTrait — re-renderiza o wrapper.
     *
     * Wrapper novo = Alpine init = calendar reconstruido = fetch eventos fresh.
     * Filtros aplicados via session no endpoint static getEvents().
     */
    protected function applyFiltersChanged(): void
    {
        $this->saveFilterSession();
        if (method_exists($this, 'forceFullRender')) {
            $this->forceFullRender();
        }
    }

    // ── Hooks query / mapeamento (override-able) ─────────────────────────

    /**
     * Hook: subclasse declara onSearch(?\Illuminate\Database\Eloquent\Builder $q)
     * e aplica $q->where(...) — chamado por buildQuery via _applyOnSearch (builder-native).
     * O contrato da grid — onSearch() SEM argumento montando $searchQuery — também
     * vale: é o que o stub do calendário gerado pela plataforma escreve.
     */

    /**
     * Filtro dos eventos montado por um `onSearch()` sem argumento:
     * closure fn(Builder $q). Mesmo contrato da MadDataGrid. Não serializada
     * (não é pública) — refeita a cada consulta.
     * @var callable|null
     */
    protected $searchQuery = null;

    /**
     * Builder dos eventos. Ordem aplicada no recordsFromQuery:
     * <mad-calendar-filter> fixos → viewport → onSearch → período/unit/auto-filters (trait).
     */
    protected function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $modelClass = $this->_resolveModelClass($this->model);
        $q     = $modelClass::query();
        $model = $this->model !== '' ? $modelClass : null;

        // 1. <mad-calendar-filter> fixos
        $this->_applyRowsToQuery($q, $this->_inlineConfig['filter'] ?? []);

        // 2. Viewport (start/end do FullCalendar) — normalizado pro formato naive.
        $start = self::_viewportStamp((string) ($_GET['start'] ?? ''), ' 00:00:00');
        $end   = self::_viewportStamp((string) ($_GET['end']   ?? ''), ' 23:59:59');
        if ($start !== '' && $this->startField !== '') {
            $q->where($this->startField, '>=', $start);
        }
        if ($end !== '' && $this->endField !== '') {
            $q->where($this->endField, '<=', $end);
        } elseif ($end !== '' && $this->startField !== '') {
            $q->where($this->startField, '<=', $end);
        }

        // 3. onSearch hook (builder-native ou bridge legado) + a closure
        //    $searchQuery que um onSearch() sem argumento monta (stub gerado).
        $this->_applyOnSearch($q, $model);
        $this->_applySearchQuery($q);

        // 4. trait — período + unit + auto-filters.
        $this->applyPeriodoToQuery($q, $model);
        $this->applyUnitToQuery($q, $model);
        $this->applyAutoFiltersToQuery($q, $model);

        return $q;
    }

    /**
     * Aplica a closure `$this->searchQuery` que um `onSearch()` sem argumento
     * montou — o stub do calendário gerado escreve os filtros da busca ali,
     * igual à listagem. Antes só a MadDataGrid a aplicava: no calendário a
     * busca era ignorada e todos os eventos apareciam.
     */
    private function _applySearchQuery($q): void
    {
        if (!is_callable($this->searchQuery)) {
            return;
        }
        try {
            ($this->searchQuery)($q);
        } catch (\Throwable $e) {
            // Mesma política do _applyOnSearch: loga e segue (a tela não cai).
            error_log('[MadCalendarComponent::searchQuery] ' . static::class . ': ' . $e->getMessage());
        }
    }

    /** Aplica rows declarativas (field/op/value/value2) num builder — between/in/null. */
    protected function _applyRowsToQuery($q, $rows): void
    {
        if (!is_array($rows)) return;
        foreach ($rows as $f) {
            if (!is_array($f)) continue;
            $field = (string) ($f['field'] ?? '');
            if ($field === '') continue;
            $op     = strtolower((string) ($f['op'] ?? '='));
            $value  = $f['value']  ?? null;
            $value2 = $f['value2'] ?? null;
            if ($value === 'null' && in_array($op, ['is', 'is not'], true)) {
                $value = null;
            }

            if (str_contains($op, 'between') && $value2 !== null && $value2 !== '') {
                $q->whereBetween($field, [$value, $value2]);
            } elseif (in_array($op, ['is', 'is not'], true) && $value === null) {
                $op === 'is' ? $q->whereNull($field) : $q->whereNotNull($field);
            } elseif ($op === 'in' && is_array($value)) {
                $q->whereIn($field, $value);
            } else {
                $q->where($field, $op === '!=' ? '<>' : $op, $value);
            }
        }
    }

    /**
     * Normaliza stamp de viewport do FullCalendar pro formato naive do banco.
     * Date-only ganha o sufixo ($suffix); datetime ISO vira 'Y-m-d H:i:s'
     * (troca o 'T' por espaço e poda offset/Z — o banco guarda hora local).
     */
    private static function _viewportStamp(string $v, string $suffix): string
    {
        if ($v === '') {
            return '';
        }
        $v = str_replace('T', ' ', trim($v));
        $v = preg_replace('/(?:Z|[+-]\d{2}:?\d{2})$/', '', $v);
        return strlen($v) > 10 ? $v : $v . $suffix;
    }

    /**
     * Mapeia um registro (model Eloquent) para o array de evento consumido
     * pelo FullCalendar. Override pra composicao custom de titulo/cor.
     */
    protected function mapEvent(object $r): array
    {
        $event = [
            'id'    => (string) $this->_resolvePath($r, $this->idField),
            'title' => (string) $this->_resolveFieldOrTemplate($r, $this->titleField),
            'start' => $this->_normalizeDateTime((string) ($this->_resolvePath($r, $this->startField) ?? '')),
        ];
        if ($this->endField !== '') {
            $endVal = $this->_resolvePath($r, $this->endField);
            if ($endVal !== null && $endVal !== '') {
                $event['end'] = $this->_normalizeDateTime((string) $endVal);
            }
        }

        // Cor: colorField > colorMap > fallback $color
        $color = '';
        if ($this->colorField !== '') {
            $color = (string) $this->_resolveFieldOrTemplate($r, $this->colorField);
        }
        if ($color === '' && $this->colorMap !== '') {
            $map = json_decode($this->colorMap, true);
            if (is_array($map) && !empty($map['field']) && !empty($map['map'])) {
                $key = (string) ($this->_resolvePath($r, $map['field']) ?? '');
                if (isset($map['map'][$key])) {
                    $color = (string) $map['map'][$key];
                }
            }
        }
        if ($color === '') $color = $this->color;
        if ($color !== '') $event['color'] = $color;

        // Resource FK → resourceId (key magic do FullCalendar resource timeline)
        if ($this->resourceField !== '') {
            $rid = $this->_resolvePath($r, $this->resourceField);
            if ($rid !== null && $rid !== '') {
                $event['resourceId'] = (string) $rid;
            }
        }

        // All-day flag → allDay
        if ($this->allDayField !== '') {
            $v = $this->_resolvePath($r, $this->allDayField);
            if ($v !== null) {
                $event['allDay'] = $this->_truthy($v);
            }
        }

        // Per-event editable override → editable
        if ($this->editableField !== '') {
            $v = $this->_resolvePath($r, $this->editableField);
            if ($v !== null) {
                $event['editable'] = $this->_truthy($v);
            }
        }

        // Extra fields
        if ($this->extraFields !== '') {
            foreach (array_map('trim', explode(',', $this->extraFields)) as $path) {
                if ($path === '') continue;
                $key = str_replace('.', '_', $path);
                $event[$key] = $this->_resolvePath($r, $path);
            }
        }

        return $event;
    }

    /**
     * Carrega eventos. Default: query Eloquent do $model (via buildQuery).
     * Override pra fontes custom (array hard-coded, multiplas tabelas, etc).
     */
    protected function loadEvents(string $start = '', string $end = ''): array
    {
        if ($this->model === '') {
            return [];
        }
        $order   = $this->orderBy !== '' ? $this->orderBy : $this->startField;
        $records = QuerySource::recordsFromQuery($this->buildQuery(), $order ?: null);
        $events = [];
        foreach ($records as $r) {
            $events[] = $this->mapEvent($r);
        }
        return $events;
    }

    /**
     * Carrega recursos (resource-timeline). Default: query Eloquent do resourceModel.
     * Subclass pode override pra retornar array hardcoded (sem resourceModel).
     */
    protected function loadResources(): array
    {
        if ($this->resourceModel === '') {
            return [];
        }
        $rcls = $this->_resolveModelClass($this->resourceModel);
        $rq   = $rcls::query();
        $this->_applyRowsToQuery($rq, $this->_inlineConfig['resourceFilter'] ?? []);
        $records = QuerySource::recordsFromQuery($rq, $this->resourceOrderBy ?: null);
        $resources = [];
        foreach ($records as $r) {
            $resources[] = $this->mapResource($r);
        }
        return $resources;
    }

    /**
     * Mapeia um registro do resourceModel para a linha do resource-timeline.
     * Irmã de mapEvent() — override pra composicao custom.
     *
     * Titulo e cor passam pelo MESMO resolvedor de titleField/colorField do
     * evento: o painel oferece mascara multi-atributo ("{nome} - andar {andar}")
     * e caminho de relacao ("setor.nome") para o recurso tambem. O acesso direto
     * `$r->{$campo}` devolvia NULL na mascara e a linha aparecia SEM nome na
     * timeline — sem erro, sem log.
     */
    protected function mapResource(object $r): array
    {
        $entry = [
            'id'    => (string) ($this->_resolvePath($r, $this->resourceIdField) ?? ''),
            'title' => $this->_resolveFieldOrTemplate($r, $this->resourceTitleField),
        ];
        if ($this->resourceColorField !== '') {
            $cor = $this->_resolveFieldOrTemplate($r, $this->resourceColorField);
            if ($cor !== '') $entry['color'] = $cor;
        }
        return $entry;
    }

    // ── Hooks de interacao (drag/resize/click) ───────────────────────────

    /**
     * Pre-hook do auto-update. Retornar false cancela o save.
     */
    protected function beforeEventUpdate(int|string $id, string $start, string $end): bool
    {
        return true;
    }

    /**
     * Pos-hook do auto-update. Override pra auditoria/log.
     */
    protected function afterEventUpdate(int|string $id, string $oldStart, string $oldEnd, string $newStart, string $newEnd): void
    {
        // No-op default.
    }

    /**
     * Drag ou resize do evento. Default: grava no banco se autoUpdate=true.
     */
    public function onEventUpdate(string $id, string $start, string $end): MadResponse
    {
        if (!$this->autoUpdate || $this->model === '') {
            return MadToast::info("Drag handler nao configurado.");
        }
        // PK de texto (UUID/ULID/codigo) tem que chegar inteira no find():
        // `(int) '0198f1e2-...'` virava 0 e o drag salvava no registro errado
        // (ou em nenhum). Inteiro continua inteiro — caminho de sempre.
        $recKey = ctype_digit($id) ? (int) $id : $id;
        if (!$this->beforeEventUpdate($recKey, $start, $end)) {
            return MadToast::warning('Operacao nao autorizada.');
        }

        $db = $this->database !== '' ? $this->database : $this->_filtersDb();
        try {
            $oldStart = '';
            $oldEnd   = '';
            DB::connection($db)->transaction(function () use ($recKey, $start, $end, &$oldStart, &$oldEnd) {
                $cls = $this->_resolveModelClass($this->model);
                $rec = $cls::find($recKey);
                if (!$rec) {
                    throw new \Exception("Registro {$recKey} nao encontrado em {$this->model}");
                }
                $oldStart = (string) ($rec->{$this->startField} ?? '');
                $oldEnd   = $this->endField !== '' ? (string) ($rec->{$this->endField} ?? '') : '';

                if ($this->startField !== '') $rec->{$this->startField} = $start;
                if ($this->endField   !== '') $rec->{$this->endField}   = $end;
                $rec->save();
            });

            $this->afterEventUpdate($recKey, $oldStart, $oldEnd, $start, $end);

            return MadToast::success('Atualizado');
        } catch (\Throwable $e) {
            return MadMessage::error('Erro', \Mad\Ui\MadUserError::message($e, mad_t('mad.error.save_failed'), static::class . '::onEventUpdate'));
        }
    }

    /**
     * Clique em evento. Default: abre clickTarget (Classe::metodo({id})).
     */
    public function onEventClick(string $id, string $title, string $view): MadResponse
    {
        if ($this->clickTarget === '') {
            return $this->_openEventForm('onEdit', ['id' => $id]);
        }
        [$class, $method, $params] = $this->_parseTarget($this->clickTarget, [
            'id' => $id, 'title' => $title,
        ]);
        return MadResponse::open($class, $params, $method);
    }

    /**
     * Clique em dia vazio. Default: abre dayClickTarget (Classe::metodo({date})).
     */
    public function onDayClick(string $date, string $view): MadResponse
    {
        if ($this->dayClickTarget === '') {
            return $this->_openEventForm('onCreate', ['date' => $date]);
        }
        [$class, $method, $params] = $this->_parseTarget($this->dayClickTarget, [
            'date' => $date,
        ]);
        return MadResponse::open($class, $params, $method);
    }

    /**
     * Clique em slot vazio do resource-timeline.
     */
    public function onSlotClick(string $date, string $resourceId, string $resourceTitle): MadResponse
    {
        if ($this->slotClickTarget === '') {
            return $this->_openEventForm('onCreate', ['date' => $date, 'resourceId' => $resourceId]);
        }
        [$class, $method, $params] = $this->_parseTarget($this->slotClickTarget, [
            'date' => $date, 'resourceId' => $resourceId, 'resourceTitle' => $resourceTitle,
        ]);
        return MadResponse::open($class, $params, $method);
    }

    /**
     * Botão "Novo" da toolbar (só existe com `event-form`). O evento novo
     * nasce na próxima hora cheia de hoje — o usuário ajusta no formulário.
     */
    public function onAddEvent(): MadResponse
    {
        $next = (new \DateTimeImmutable('now'))->setTime((int) date('H'), 0)->modify('+1 hour');

        return $this->_openEventForm('onCreate', ['date' => $next->format('Y-m-d H:i:s')]);
    }

    /**
     * Abre o `event-form` no método pedido. Sem formulário vinculado, nada
     * acontece (calendário só de visualização). Formulário que não existe mais
     * (apagado/renomeado depois de vinculado) avisa em vez de quebrar o clique.
     * Formulário comum sem `onCreate()` abre vazio (mount) com a data nos params.
     */
    protected function _openEventForm(string $method, array $params): MadResponse
    {
        $form = trim($this->eventForm);
        if ($form === '') {
            return new MadResponse();
        }
        if (!class_exists($form)) {
            return MadToast::warning(mad_t('mad.calendar_form_missing', ['form' => $form]));
        }
        if (!method_exists($form, $method)) {
            $method = 'show';
        }

        return MadResponse::open($form, $params, $method);
    }

    public function onReload(): void
    {
        // No-op — full re-render acontece via MadComponent ciclo.
    }

    /**
     * Hook low-level — recebe o builder MadFullCalendar antes do render().
     * Override pra setar options nao expostas via Blade attrs (->option(...)).
     */
    protected function configureCalendar(MadFullCalendar $cal): void
    {
        // No-op default.
    }

    // ── Render pipeline ──────────────────────────────────────────────────

    /**
     * Entry point do <mad-calendar> declarativo (compilado pelo MadCalendarCompiler).
     * Aplica os atributos do Blade nas props do componente + sobe config inline.
     */
    public function _renderInlineCalendar(array $config): string
    {
        // Captura defaults pre-render (uma vez por instancia) e reseta a
        // cada chamada — permite multiplos <mad-calendar> na mesma pagina
        // sem vazamento de estado entre blocos.
        $this->_captureDefaults();
        $this->_resetToDefaults();

        $this->_applyInlineConfig($config);
        $this->_persistConfigToSession($config);
        $this->_persistFilterState();

        return $this->renderCalendarHtml();
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
     * Atribui config do Blade nas props correspondentes + extrai inline blocks.
     */
    protected function _applyInlineConfig(array $config): void
    {
        // Mapa de chaves do compiler → propriedade local
        $scalar = [
            'model', 'database', 'idField', 'titleField', 'startField', 'endField',
            'colorField', 'resourceField', 'allDayField', 'editableField', 'color', 'colorMap', 'extraFields', 'orderBy', 'eventsUrl',
            'defaultView', 'timeRange', 'enableDays', 'locale', 'currentDate',
            'slotDuration', 'numDays',
            'height', 'calendarId', 'header', 'extraOptions',
            'confirmUpdate', 'clickTarget', 'clickTargetMode',
            'dayClickTarget', 'slotClickTarget', 'eventForm',
            'eventUpdateMethod', 'dayClickMethod', 'eventClickMethod', 'slotClickMethod',
            'popoverTitle', 'popoverContent', 'popoverTrigger',
            'resourceModel', 'resourceDatabase', 'resourceIdField', 'resourceTitleField',
            'resourceColorField', 'resourceLabel', 'resourceOrderBy',
            'periodType', 'dateField', 'unitField',
        ];
        $bool = [
            'noWeekend', 'fullHeight', 'editable', 'noDragging', 'noResizing',
            'autoUpdate', 'rememberFilters', 'defaultToCurrentPeriod', 'usePresets',
            'applyUnitFilter',
        ];
        $json = ['periodFields', 'unitFields'];

        foreach ($scalar as $k) {
            if (array_key_exists($k, $config) && $config[$k] !== '') {
                if ($k === 'height' || $k === 'numDays') {
                    $this->$k = (int) $config[$k];
                } else {
                    $this->$k = (string) $config[$k];
                }
            }
        }
        foreach ($bool as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = $this->_truthy($config[$k]);
            }
        }
        foreach ($json as $k) {
            if (!empty($config[$k])) {
                $decoded = json_decode((string) $config[$k], true);
                if (is_array($decoded)) {
                    $this->$k = $decoded;
                }
            }
        }

        if ($this->noWeekend) {
            $this->enableDays = '1,2,3,4,5';
        }

        // Inline blocks (toolbar/popover/resource/filter/resourceFilter)
        $inline = [];
        foreach (['toolbar', 'popover', 'resource', 'filter', 'resourceFilter'] as $k) {
            if (isset($config[$k])) {
                $inline[$k] = $config[$k];
            }
        }
        if (!empty($inline)) {
            $this->_inlineConfig = $inline;
        }

        // Resource sub-tag aplica props do resource (model, label, etc) — ja vem
        // como mapa associativo no $config['resource'].
        if (!empty($config['resource']) && is_array($config['resource'])) {
            $r = $config['resource'];
            foreach (['model' => 'resourceModel', 'database' => 'resourceDatabase',
                      'idField' => 'resourceIdField', 'titleField' => 'resourceTitleField',
                      'colorField' => 'resourceColorField', 'label' => 'resourceLabel',
                      'orderBy' => 'resourceOrderBy', 'slotDuration' => 'slotDuration',
                      'numDays' => 'numDays', 'slotClickTarget' => 'slotClickTarget'] as $src => $dst) {
                if (isset($r[$src]) && $r[$src] !== '') {
                    if ($dst === 'numDays') {
                        $this->$dst = (int) $r[$src];
                    } else {
                        $this->$dst = (string) $r[$src];
                    }
                }
            }
        }

        $this->_configApplied = true;
    }

    /** Salva config na session pro getEvents() static restaurar. */
    protected function _persistConfigToSession(array $config): void
    {
        session([static::class . '_cal_cfg' => $config]);
        // Fallback cross-sessão: getEvents() roda numa instância nova e, em
        // cenários onde o write da sessão do render não chega ao feed (sessão
        // regenerada, workers), ficava sem config e devolvia [] silencioso.
        // A config vem do Blade — estável por classe — então cache
        // compartilhado é seguro.
        try { cache()->put('mad_cal_cfg:' . static::class, $config, 3600); } catch (\Throwable $e) { /* opcional */ }
    }

    /**
     * Persiste valores ATUAIS dos filtros na session — transporte pro endpoint
     * static getEvents(), que roda numa instancia nova sem o mad_state.
     *
     * Chamado a cada render do <mad-calendar>: cobre mount, Aplicar (onShow →
     * forceFullRender) e Limpar. Sempre ativo — NAO gated por rememberFilters,
     * que segue controlando apenas a persistencia cross-visita (mount).
     */
    protected function _persistFilterState(): void
    {
        session([static::class . '_cal_filters' => $this->filterStateSnapshot()]);
    }

    /** Restaura valores de filtro do transporte — usado por getEvents() static. */
    protected function _restoreFilterState(): void
    {
        $saved = session(static::class . '_cal_filters');
        if (is_array($saved)) {
            $this->applyFilterStateSnapshot($saved);
        }
    }

    /** Restaura config persistida — usado por getEvents() static. */
    protected function _restoreConfigFromSession(): void
    {
        $cfg = session(static::class . '_cal_cfg');
        if (!is_array($cfg)) {
            try { $cfg = cache()->get('mad_cal_cfg:' . static::class); } catch (\Throwable $e) { $cfg = null; }
        }
        if (is_array($cfg)) {
            $this->_applyInlineConfig($cfg);
        }
    }

    /**
     * Monta o MadFullCalendar interno e renderiza o HTML.
     */
    protected function renderCalendarHtml(): string
    {
        $cal = MadFullCalendar::make($this->currentDate ?: null)
            ->defaultView($this->defaultView)
            ->locale($this->locale);

        if ($this->calendarId !== '') {
            $cal->id($this->calendarId);
        }

        // Time range
        [$min, $max] = $this->_parseTimeRange();
        if ($min !== '' || $max !== '') {
            $cal->timeRange($min ?: '00:00:00', $max ?: '24:00:00');
        }

        // Enable days
        $days = array_values(array_filter(array_map(
            fn($d) => is_numeric(trim($d)) ? (int) trim($d) : null,
            explode(',', $this->enableDays)
        ), fn($d) => $d !== null));
        if (!empty($days) && count($days) !== 7) {
            $cal->enableDays($days);
        }

        if ($this->height > 0) $cal->height($this->height);
        if ($this->fullHeight) $cal->fullHeight(true);
        if ($this->editable)   $cal->editable(true);
        if ($this->noDragging) $cal->disableDragging();
        if ($this->noResizing) $cal->disableResizing();

        // Eventos: events-url (escape hatch) OU URL fabricado apontando pra getEvents()
        $url = $this->eventsUrl !== '' ? $this->eventsUrl : $this->_buildEventsUrl();
        $cal->eventsUrl($url);

        // Callbacks PHP
        $cal->onDayClick($this->dayClickMethod);
        $cal->onEventClick($this->eventClickMethod);
        if (trim($this->eventForm) !== '') {
            $cal->onAddEvent('onAddEvent', mad_t('mad.btn.new'));
        }
        if ($this->editable) {
            $cal->onEventUpdate($this->eventUpdateMethod);
        }

        // Popover
        if ($this->popoverTitle !== '' || $this->popoverContent !== '') {
            $cal->popover($this->popoverTitle, $this->popoverContent);
        }

        // Resource timeline: ativa quando resourceModel definido OU defaultView eh resource*
        // OU subclass override loadResources() retornando array nao-vazio.
        $wantResource = $this->resourceModel !== ''
            || str_starts_with($this->defaultView, 'resourceTimeline');
        $resources = $this->loadResources();
        if (!empty($resources)) {
            $wantResource = true;
        }
        if ($wantResource && !empty($resources)) {
            $cal->resources($resources);
            $cal->resourceLabel($this->resourceLabel);
            $cal->slotDuration($this->slotDuration);
            if ($this->numDays > 0) $cal->numDays($this->numDays);
            $cal->onSlotClick($this->slotClickMethod);
        }

        // Slot duration tambem aplica em views nao-resource. SEMPRE enviado: o
        // default da prop (01:00) é o que o editor mostra como "1 hora", mas o
        // FullCalendar sozinho cai em 00:30 — pular o 01:00 fazia o app mostrar
        // meia hora enquanto o editor prometia uma.
        if (!$wantResource && $this->slotDuration !== '') {
            $cal->option('slotDuration', $this->slotDuration . (substr_count($this->slotDuration, ':') === 1 ? ':00' : ''));
        }

        // Header customizado
        if ($this->header !== '') {
            $cal->option('headerToolbar', json_decode($this->header, true) ?: $this->header);
        }

        // Extra options pass-through (JSON)
        if ($this->extraOptions !== '') {
            $opts = json_decode($this->extraOptions, true);
            if (is_array($opts)) {
                foreach ($opts as $k => $v) {
                    $cal->option($k, $v);
                }
            }
        }

        // Hook pra subclass adicionar config low-level (chamadas raw em MadFullCalendar)
        $this->configureCalendar($cal);

        $html = $cal->render();

        // Toolbar inline (renderizada acima do calendario)
        $toolbarHtml = '';
        if (!empty($this->_inlineConfig['toolbar'])) {
            $tpl = base64_decode((string) $this->_inlineConfig['toolbar']);
            try {
                $toolbarHtml = MadBlade::renderString($tpl, ['that' => $this, '_component' => $this]);
            } catch (\Throwable $e) {
                $toolbarHtml = '';
            }
        }

        $this->calendar = $toolbarHtml . $html;
        return $this->calendar;
    }

    /** Fabrica URL pro endpoint static getEvents() do proprio componente. */
    protected function _buildEventsUrl(): string
    {
        // getEvents é método estático. Usa o BASENAME (não o FQCN): o routeMap do
        // MadRoutes é indexado por basename (expose()/screen() gravam
        // $routeMap[<basename>]). Com o FQCN, toFriendlyUrl não acha a entrada e
        // cai no path do FQCN (/app/App/Control/.../getEvents) → 404 e calendário
        // vazio. Com a tela registrada via expose() (methodRoute=true), o
        // toFriendlyUrl emite /app/{slug}/getEvents?static=1. (issue #5)
        return \Mad\Routing\MadRoutes::urlFor(class_basename(static::class), 'getEvents', ['static' => 1]);
    }

    /** Endpoint estatico chamado pelo FullCalendar com ?start=&end=. */
    public static function getEvents($param = null): void
    {
        $param = $param ?? array_merge($_GET ?? [], $_POST ?? []);
        $start = (string) ($param['start'] ?? '');
        $end   = (string) ($param['end']   ?? '');

        $events = [];
        try {
            /** @var static $instance */
            $instance = new static();
            $instance->_restoreConfigFromSession();
            $instance->_initFiltersForm();
            $instance->loadFilterSession();
            // Transporte sempre vence o rememberFilters: reflete o estado da
            // ultima renderizacao do calendario (Aplicar/Limpar inclusos).
            $instance->_restoreFilterState();
            $instance->hydrateFiltersFromArray($param);
            // Espelha props publicas (hidratadas da session) em form->fields
            // pra $this->form->getData() funcionar no onSearch deste endpoint
            // mesmo sem POST do form de filtro.
            $instance->syncFormFields();
            $events = $instance->loadEvents($start, $end);
        } catch (\Throwable $e) {
            $events = [];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($events, JSON_UNESCAPED_UNICODE);
    }

    // ── View default ─────────────────────────────────────────────────────

    /**
     * View default — pode ser sobrescrita por subclass.
     * Quando subclass overrida, $this->calendar fica disponivel.
     */
    protected function view(): string|array
    {
        // Quando o template Blade da subclass usa <mad-calendar>, o
        // _renderInlineCalendar() ja foi chamado pelo compiler e populou
        // $this->calendar. Render via template default que so ecoa.
        return ['components.calendar-component-default', ['calendar' => $this->calendar]];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Resolve o model (short-name 'Pedido' ou FQCN) para FQCN App\Models\*.
     *
     * Captura \Throwable (class-not-found pode subir como Error, nao Exception)
     * e devolve o nome original — o erro real estoura no ponto de uso, com a
     * mesma semantica do legado.
     */
    private function _resolveModelClass(string $model): string
    {
        try {
            return \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        } catch (\Throwable $e) {
            return $model;
        }
    }

    /**
     * Resolve field OU template.
     *
     *   "titulo"                              → dot-notation simples
     *   "tipo.cor"                            → $r->tipo->cor
     *   "{tipo.nome} - {cliente.nome}"        → template (substitui {path})
     *   "{tipo->nome}" / "{tipo_id->nome}"    → template estilo legado (arrow);
     *                                           usa $r->render() se o model
     *                                           expuser, senao normaliza pra
     *                                           dot-path e navega via data_get.
     */
    protected function _resolveFieldOrTemplate(object $r, string $expr): string
    {
        if ($expr === '') return '';
        if (strpos($expr, '{') === false) {
            $v = $this->_resolvePath($r, $expr);
            return $v === null ? '' : (string) $v;
        }
        // Template arrow estilo legado — delega ao render() nativo quando existir.
        if (strpos($expr, '->') !== false && method_exists($r, 'render')) {
            try {
                return (string) $r->render($expr);
            } catch (\Throwable $e) {
                // segue pro fallback local abaixo
            }
        }
        return (string) preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($r) {
            $v = $this->_resolvePath($r, trim($m[1]));
            return $v === null ? '' : (string) $v;
        }, $expr);
    }

    /**
     * Normaliza datetime malformado por input bugado.
     *
     * Caso conhecido: form usou date('HH:ii') no lugar de date('H:i'), gerando
     * datas tipo "2026-06-18 0202:0000" (HH duplicado, MM duplicado, sem SS).
     * Detecta padrao \d{4}:\d{4} apos espaco e reduz cada grupo aos 2 primeiros
     * digitos + ":00" pra SS.
     *
     * Inputs validos (HH:MM:SS ou HH:MM) passam intactos.
     */
    protected function _normalizeDateTime(string $v): string
    {
        if ($v === '') return '';
        // "YYYY-MM-DD HHHH:MMSS" → "YYYY-MM-DD HH:MM:SS"
        return preg_replace_callback(
            '/(\d{4}-\d{2}-\d{2}\s+)(\d\d)\d\d:(\d\d)\d\d(?::\d\d)?/',
            fn($m) => $m[1] . $m[2] . ':' . $m[3] . ':00',
            $v
        );
    }

    /**
     * Resolve 'tipo.cor' → $r->tipo->cor (suporta dot-notation).
     *
     * Tambem aceita arrow-notation legada 'a->b' / 'a_id->b': segmentos
     * intermediarios perdem o sufixo '_id' (FK 'tipo_id' → relacao Eloquent
     * 'tipo') e o path vira 'a.b'. Eloquent resolve relacoes via __get usando
     * a conexao registrada em config/database.php (lazy, sem open explicito).
     * Fallback final: data_get($r, path).
     */
    protected function _resolvePath(object $r, string $path): mixed
    {
        if ($path === '') return null;

        // Arrow-notation → dot-path ('a_id->b' → 'a.b'; ultimo segmento intacto)
        if (strpos($path, '->') !== false) {
            $segs = array_map('trim', explode('->', $path));
            $last = array_pop($segs);
            $segs = array_map(
                fn($s) => str_ends_with($s, '_id') ? substr($s, 0, -3) : $s,
                $segs
            );
            $segs[] = $last;
            $path = implode('.', $segs);
        }

        if (strpos($path, '.') === false) {
            try { return $r->{$path} ?? null; } catch (\Throwable $e) { return null; }
        }
        $cur = $r;
        foreach (explode('.', $path) as $p) {
            if (is_object($cur)) {
                try { $cur = $cur->{$p} ?? null; } catch (\Throwable $e) { $cur = null; }
            } elseif (is_array($cur)) {
                $cur = $cur[$p] ?? null;
            } else {
                $cur = null;
            }
            if ($cur === null) break;
        }
        if ($cur === null) {
            // Fallback data_get — cobre accessors/ArrayAccess que o loop nao pegou.
            try { return data_get($r, $path); } catch (\Throwable $e) { return null; }
        }
        return $cur;
    }

    /** Parse "07:00-19:00" → ['07:00:00','19:00:00']. */
    protected function _parseTimeRange(): array
    {
        if ($this->timeRange === '' || $this->timeRange === '00:00-24:00') {
            return ['', ''];
        }
        $parts = explode('-', $this->timeRange);
        if (count($parts) !== 2) return ['', ''];
        $norm = function ($t) {
            $t = trim($t);
            if (preg_match('/^\d{1,2}:\d{2}$/', $t)) return $t . ':00';
            return $t;
        };
        return [$norm($parts[0]), $norm($parts[1])];
    }

    /** Parse "Classe::metodo({id},{date})" → [class, method, params]. */
    protected function _parseTarget(string $target, array $resolved): array
    {
        if (strpos($target, '::') === false) {
            return [$target, 'show', $resolved];
        }
        [$class, $rest] = explode('::', $target, 2);
        $method = $rest;
        $params = [];
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/', $rest, $m)) {
            $method = $m[1];
            foreach (array_map('trim', explode(',', $m[2])) as $p) {
                if ($p === '') continue;
                $key = trim($p, '{}');
                if (isset($resolved[$key])) {
                    $params[$key] = $resolved[$key];
                }
            }
        }
        // Merge demais valores resolvidos (caso target nao explicite mas a action espere)
        $params = array_merge($resolved, $params);
        return [$class, $method, $params];
    }

    /** Converte "true"/"false"/bool em bool. */
    protected function _truthy(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return (bool) $v;
        if (!is_string($v)) return (bool) $v;
        $s = strtolower(trim($v));
        if (in_array($s, ['', '0', 'false', 'no', 'off', 'null'], true)) return false;
        return true;
    }
}
