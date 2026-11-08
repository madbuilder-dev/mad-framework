<?php
namespace Mad\Agenda;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Mad\Component\MadComponent;
use Mad\Form\FieldListColumn;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;
use Mad\I18n\MadLang;
use Mad\Security\ActionVocab;
use Mad\Security\PermissionGate;

/**
 * MadAgenda — agenda de horários com UMA COLUNA POR RECURSO (<mad-agenda>).
 *
 * Profissional, sala, veterinário, mesa, quadra: cada recurso é uma coluna na
 * visão Dia; a Semana mostra os dias (com filtro de recurso) e o Mês a
 * contagem por dia. Cartões ricos (cor, selo de status, avatar, badges),
 * arrastar/redimensionar com validação NO SERVIDOR, linha do agora, expediente
 * e bloqueios sombreados. Feita em Alpine.js puro (assets/mad-agenda.js), sem
 * FullCalendar.
 *
 * ┌─ Dois modos de dados ───────────────────────────────────────────────────┐
 * │ 1. Método da tela: source-method="agendaDados" recebe                    │
 * │    ['start','end','view','date','resource','filters'] e devolve           │
 * │    ['resources' => [...], 'events' => [...], 'blocks' => [...]].         │
 * │ 2. Declarativo: model/start-field/end-field/resource-field/... e         │
 * │    resource-model/resource-title-field/...                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Numa tela comum (formulário, página livre) a agenda é EMBUTIDA: as ações
 * dela (onAgendaLoad/onAgendaMove/onAgendaResize) chegam à tela, que não as
 * tem — o handler pede a {@see _embeddedFor()}, que redesenha a tela "a seco"
 * para receber a config da tag (com closures vivas) e atende. Nada da config
 * vem do navegador: só a chave da agenda, o id do evento e as datas pedidas,
 * que são validados aqui e pela tela (on-move).
 *
 * Segurança: id, datas e recurso que voltam do navegador são PEDIDO. No modo
 * declarativo o evento é procurado na consulta ESCOPADA (where + query()) e o
 * recurso de destino tem de estar entre os recursos visíveis; no modo por
 * método, é o `on-move` da tela que confere dono/escopo antes de gravar.
 */
class MadAgenda extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    /** Ações da agenda que a tela dona não tem e a agenda embutida atende. */
    public const WIRE_ACTIONS = ['onAgendaLoad', 'onAgendaMove', 'onAgendaResize'];

    public const VIEWS = ['day', 'week', 'month'];

    /** Status que não contam na ocupação (padrão de `occupancy-ignore`). */
    private const DEFAULT_OCC_IGNORE = 'cancelado,cancelada,canceled,cancelled,faltou,no_show';

    private const MAX_EVENTS    = 3000;
    private const MAX_RESOURCES = 200;

    // ── Configuração (atributos da tag; subclasse pode fixar no corpo) ─────

    protected string $agendaKey = '';
    protected string $sourceMethod = '';

    protected string $model = '';
    protected string $database = '';
    protected string $resourceField = '';
    protected string $startField = 'start';
    protected string $endField = 'end';
    protected string $titleField = 'title';
    protected string $subtitleField = '';
    protected string $colorField = '';
    protected string $statusField = '';
    protected string $avatarField = '';
    protected string $groupField = '';
    protected string $where = '';
    protected ?\Closure $whereClosure = null;

    protected string $resourceModel = '';
    protected string $resourceTitleField = 'nome';
    protected string $resourceSubtitleField = '';
    protected string $resourceAvatarField = '';
    protected string $resourceColorField = '';
    protected string $resourceOrder = '';
    protected string $resourceWhere = '';
    protected ?\Closure $resourceWhereClosure = null;
    protected string $businessHours = '';

    protected string $onMove = '';
    protected string $onResize = '';
    protected string $onSlotClick = '';
    protected string $onEventClick = '';
    protected string $onRangeChange = '';
    protected string $slotClickTarget = '';
    protected string $eventClickTarget = '';

    protected string $defaultView = 'day';
    protected string $views = 'day,week,month';
    protected string $date = '';
    protected string $timeRange = '';
    protected string $height = '';
    protected string $statusLabels = '';
    protected string $statusTones = '';
    protected string $lockedStatuses = '';
    protected string $occupancyIgnore = self::DEFAULT_OCC_IGNORE;
    protected string $emptyText = '';
    /** Palavra do contador: 'atendimento|atendimentos' (singular|plural). Vazio = "evento(s)" do idioma. */
    protected string $eventLabel = '';
    protected int $slotMinutes = 15;
    protected int $hourHeight = 64;
    protected int $weekStart = 1;
    protected int $autoRefresh = 0;
    protected ?bool $editable = null;
    protected bool $showOccupancy = true;
    protected bool $nowLine = true;

    protected string $eventTemplate = '';
    protected string $toolbar = '';

    // ── Runtime ───────────────────────────────────────────────────────────

    /** Tela dona quando a agenda está embutida numa tela comum. */
    protected ?object $_externalHost = null;

    /** Agenda embutida esperando a tela dona entregar a config. */
    private static ?self $_hostProbe = null;

    /** Desenho "a seco": só coleta a config das tags, não desenha. */
    private bool $_probing = false;

    /** @var array<string, array> configs coletadas no desenho a seco, por chave */
    private array $_probedConfigs = [];

    /** Config aplicada (chave) — evita reaplicar. */
    private string $_appliedKey = '';

    /** Defaults da classe, capturados antes da 1ª config inline. */
    private ?array $_defaults = null;

    /** @var array<int|string, object> registros do modo declarativo (para o cartão próprio) */
    private array $_records = [];

    // ── Lifecycle ─────────────────────────────────────────────────────────

    protected function view(): string|array
    {
        // Subclasse sem blade próprio: desenha só a agenda com a config da classe.
        return ['components.agenda-host', ['__component' => $this]];
    }

    public function _setExternalHost(object $host): void
    {
        $this->_externalHost = $host;
    }

    /** A tela que atende os métodos nomeados nos atributos. */
    protected function _host(): object
    {
        return $this->_externalHost ?? $this;
    }

    // ── Embutida numa tela comum ──────────────────────────────────────────

    /**
     * A tela `$host` recebeu uma ação da agenda que não tem: devolve a agenda
     * que a atende, com a config entregue pela própria tela num desenho a seco.
     */
    public static function _embeddedFor(object $host, string $action): ?self
    {
        if ($host instanceof self || !in_array($action, self::WIRE_ACTIONS, true)
            || method_exists($host, $action) || !method_exists($host, 'render')) {
            return null;
        }

        $probe = new MadAgendaStandalone();
        $probe->_setExternalHost($host);
        $probe->_probing = true;

        $prev = self::$_hostProbe;
        self::$_hostProbe = $probe;
        try {
            $host->render();
        } catch (\Throwable $e) {
            error_log('[MadAgenda] a tela ' . get_class($host) . ' não pôde ser redesenhada para atender ' . $action . ': ' . $e->getMessage());
        } finally {
            self::$_hostProbe = $prev;
            $probe->_probing  = false;
        }

        return $probe->_probedConfigs !== [] ? $probe : null;
    }

    /** @internal MadAgendaCompiler: a agenda embutida que espera a config da tela dona. */
    public static function _pendingHostProbe(): ?self
    {
        return self::$_hostProbe;
    }

    /**
     * Garante a config da agenda `$key` antes de uma ação. Subclasse
     * (tela que É a agenda) também redesenha a seco: os atributos da tag não
     * viajam no estado.
     */
    protected function _useConfig(string $key): bool
    {
        if ($this->_appliedKey !== '' && ($key === '' || $key === $this->_appliedKey)) {
            return true;
        }
        if ($this->_probedConfigs === []) {
            $this->_probing = true;
            try {
                $this->render();
            } catch (\Throwable $e) {
                error_log('[MadAgenda] ' . static::class . ' não pôde ser redesenhada para a ação: ' . $e->getMessage());
            } finally {
                $this->_probing = false;
            }
        }
        $cfg = $this->_probedConfigs[$key] ?? null;
        if ($cfg === null && count($this->_probedConfigs) === 1 && ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key))) {
            $cfg = reset($this->_probedConfigs);
        }
        if ($cfg === null && $this->_probedConfigs === [] && $this->agendaKey !== '' && ($key === '' || $key === $this->agendaKey)) {
            // Subclasse configurada só no corpo da classe, sem tag.
            $this->_appliedKey = $this->agendaKey;
            return true;
        }
        if ($cfg === null) {
            return false;
        }
        $this->_applyInlineConfig($cfg);

        return true;
    }

    // ── Render inline (MadAgendaCompiler) ─────────────────────────────────

    public function _renderInlineAgenda(array $config): string
    {
        $key = (string) ($config['key'] ?? 'agenda');
        if ($this->_probing) {
            $this->_probedConfigs[$key] = $config;
            return '';
        }

        $this->_applyInlineConfig($config);

        return $this->_renderHtml();
    }

    /** HTML da agenda com a config aplicada (usado também pela view default). */
    public function _renderHtml(): string
    {
        $ctx     = $this->_initialContext();
        $payload = $this->_payload($ctx);

        return \Mad\View\MadBlade::render('components.agenda', [
            '__agenda' => $this,
            'cfg'      => $this->_clientConfig(),
            'payload'  => $payload,
            'toolbarHtml' => $this->_renderToolbar(),
        ]);
    }

    protected function _applyInlineConfig(array $config): void
    {
        if ($this->_defaults === null) {
            $this->_defaults = [];
            foreach (self::CONFIG_KEYS as $k) {
                $this->_defaults[$k] = $this->$k;
            }
        }
        // Cada tag parte dos defaults da classe: duas agendas na mesma tela não se misturam.
        foreach ($this->_defaults as $k => $v) {
            $this->$k = $v;
        }

        foreach (self::STRING_KEYS as $k) {
            if (array_key_exists($k, $config) && is_scalar($config[$k])) {
                $this->$k = trim((string) $config[$k]);
            }
        }
        if (isset($config['agendaId']) && is_scalar($config['agendaId']) && (string) $config['agendaId'] !== '') {
            $this->agendaKey = (string) $config['agendaId'];
        } elseif (isset($config['key'])) {
            $this->agendaKey = (string) $config['key'];
        }
        foreach (['slotMinutes', 'hourHeight', 'weekStart', 'autoRefresh'] as $k) {
            if (isset($config[$k]) && is_numeric($config[$k])) {
                $this->$k = (int) $config[$k];
            }
        }
        foreach (['showOccupancy', 'nowLine'] as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (bool) $config[$k];
            }
        }
        if (array_key_exists('editable', $config)) {
            $this->editable = (bool) $config['editable'];
        }
        foreach (['where' => 'whereClosure', 'resourceWhere' => 'resourceWhereClosure'] as $k => $closureProp) {
            if (!array_key_exists($k, $config)) {
                continue;
            }
            $w = $config[$k];
            if ($w instanceof \Closure) {
                $this->$closureProp = $w;
                $this->$k           = '';
            } elseif (is_string($w)) {
                $this->$k           = $w;
                $this->$closureProp = null;
            }
        }

        $this->slotMinutes = in_array($this->slotMinutes, [5, 10, 15, 20, 30, 60], true) ? $this->slotMinutes : 15;
        $this->hourHeight  = max(32, min(240, $this->hourHeight));
        $this->weekStart   = $this->weekStart === 0 ? 0 : 1;
        $this->autoRefresh = $this->autoRefresh > 0 ? max(15, $this->autoRefresh) : 0;
        if (!in_array($this->defaultView, $this->_views(), true)) {
            $this->defaultView = $this->_views()[0];
        }
        $this->_appliedKey = $this->agendaKey;
    }

    private const STRING_KEYS = [
        'sourceMethod', 'model', 'database', 'resourceField', 'startField', 'endField', 'titleField',
        'subtitleField', 'colorField', 'statusField', 'avatarField', 'groupField', 'resourceModel',
        'resourceTitleField', 'resourceSubtitleField', 'resourceAvatarField', 'resourceColorField',
        'resourceOrder', 'businessHours', 'onMove', 'onResize', 'onSlotClick', 'onEventClick',
        'onRangeChange', 'slotClickTarget', 'eventClickTarget', 'defaultView', 'views', 'date',
        'timeRange', 'height', 'statusLabels', 'statusTones', 'lockedStatuses', 'occupancyIgnore',
        'emptyText', 'eventLabel', 'eventTemplate', 'toolbar',
    ];

    private const CONFIG_KEYS = [
        'agendaKey', 'sourceMethod', 'model', 'database', 'resourceField', 'startField', 'endField',
        'titleField', 'subtitleField', 'colorField', 'statusField', 'avatarField', 'groupField', 'where',
        'whereClosure', 'resourceModel', 'resourceTitleField', 'resourceSubtitleField',
        'resourceAvatarField', 'resourceColorField', 'resourceOrder', 'resourceWhere',
        'resourceWhereClosure', 'businessHours', 'onMove', 'onResize', 'onSlotClick', 'onEventClick',
        'onRangeChange', 'slotClickTarget', 'eventClickTarget', 'defaultView', 'views', 'date',
        'timeRange', 'height', 'statusLabels', 'statusTones', 'lockedStatuses', 'occupancyIgnore',
        'emptyText', 'eventLabel', 'slotMinutes', 'hourHeight', 'weekStart', 'autoRefresh', 'editable',
        'showOccupancy', 'nowLine', 'eventTemplate', 'toolbar',
    ];

    // ── Hooks (subclasse que É a agenda) ──────────────────────────────────

    /** Escopo dos eventos (tenant/unidade). Vale em listar e mover. */
    protected function query(Builder $q): void
    {
    }

    /** Escopo dos recursos. */
    protected function resourceQuery(Builder $q): void
    {
    }

    /**
     * Pode mover/redimensionar este registro (modo declarativo)? `true` libera;
     * `false` ou uma mensagem recusam (a mensagem vai no aviso).
     */
    protected function canMove(object $record, string $start, string $end, ?string $resourceId): bool|string
    {
        return true;
    }

    /** Depois de gravar o movimento (modo declarativo). */
    protected function afterMove(object $record): void
    {
    }

    /** Ajuste fino do evento montado a partir do registro (modo declarativo). */
    protected function mapEvent(object $record, array $event): array
    {
        return $event;
    }

    /** Ajuste fino do recurso montado a partir do registro (modo declarativo). */
    protected function mapResource(object $record, array $resource): array
    {
        return $resource;
    }

    // ── Ações (wire) ──────────────────────────────────────────────────────

    /** Busca os dados de outro período/visão (navegação, filtro, atualização). */
    public function onAgendaLoad(string $key = '', array $ctx = []): MadResponse
    {
        if (!$this->_useConfig($key)) {
            return (new MadResponse())->toast(MadLang::t('mad.agenda.invalid'), 'warning');
        }
        $ctx = $this->_sanitizeContext($ctx);
        $this->_rememberContext($ctx);

        if ($this->onRangeChange !== '') {
            $this->_callHostMethod($this->onRangeChange, $ctx);
        }

        return (new MadResponse())->script($this->_eventScript('mad-agenda:data', $this->_payload($ctx)));
    }

    /**
     * Arrastou um evento: `$start`/`$end` "Y-m-d H:i" e o recurso de destino.
     * Recusa = o cartão volta e aparece o aviso.
     */
    public function onAgendaMove(string $key = '', string $id = '', string $start = '', string $end = '', string $resource = '', array $ctx = []): MadResponse
    {
        return $this->_change('move', $key, $id, $start, $end, $resource, $ctx);
    }

    /** Esticou/encolheu um evento pela borda de baixo: novo fim "Y-m-d H:i". */
    public function onAgendaResize(string $key = '', string $id = '', string $start = '', string $end = '', string $resource = '', array $ctx = []): MadResponse
    {
        return $this->_change('resize', $key, $id, $start, $end, $resource, $ctx);
    }

    private function _change(string $kind, string $key, string $id, string $start, string $end, string $resource, array $ctx): MadResponse
    {
        $r = new MadResponse();
        if (!$this->_useConfig($key)) {
            return $this->_refuse($r, $id, MadLang::t('mad.agenda.invalid'), $ctx, false);
        }
        $ctx   = $this->_sanitizeContext($ctx);
        $start = self::_normDateTime($start);
        $end   = self::_normDateTime($end);
        if ($id === '' || strlen($id) > 64 || $start === null || $end === null || $end <= $start || strlen($resource) > 64) {
            return $this->_refuse($r, $id, MadLang::t('mad.agenda.invalid'), $ctx);
        }

        $method = $kind === 'resize' ? $this->onResize : $this->onMove;

        if ($this->_isSourceMode()) {
            if ($method === '') {
                return $this->_refuse($r, $id, MadLang::t('mad.agenda.not_editable'), $ctx);
            }
            $denied = $this->_deniedHostMethod($method);
            if ($denied !== null) {
                return $this->_refuse($r, $id, $denied, $ctx);
            }
            $outcome = $this->_callHostMethod($method, [
                'id' => $id, 'start' => $start, 'end' => $end,
                'resource' => $resource === '' ? null : $resource,
                'resourceId' => $resource === '' ? null : $resource,
            ], true);
        } else {
            $outcome = $this->_moveRecord($kind, $method, $id, $start, $end, $resource);
        }

        [$ok, $message, $extra] = $outcome;
        if ($extra instanceof MadResponse) {
            $r->merge($extra);
        }
        if (!$ok) {
            return $this->_refuse($r, $id, $message !== '' ? $message : MadLang::t('mad.agenda.refused'), $ctx, true, !($extra instanceof MadResponse && self::_hasToast($extra)));
        }
        if (!($extra instanceof MadResponse && self::_hasToast($extra))) {
            $r->toast($message !== '' ? $message : MadLang::t('mad.agenda.moved'), 'success');
        }

        return $r
            ->script($this->_eventScript('mad-agenda:result', ['ok' => true, 'id' => $id]))
            ->script($this->_eventScript('mad-agenda:data', $this->_payload($ctx)));
    }

    private function _refuse(MadResponse $r, string $id, string $message, array $ctx, bool $reload = true, bool $toast = true): MadResponse
    {
        if ($toast) {
            $r->toast($message, 'warning');
        }
        $r->script($this->_eventScript('mad-agenda:result', ['ok' => false, 'id' => $id, 'message' => $message]));
        if ($reload) {
            $r->script($this->_eventScript('mad-agenda:data', $this->_payload($this->_sanitizeContext($ctx))));
        }

        return $r;
    }

    /** Movimento no modo declarativo: registro na consulta escopada, recurso visível. */
    private function _moveRecord(string $kind, string $method, string $id, string $start, string $end, string $resource): array
    {
        if ($method !== '') {
            // Declarativo com on-move: a tela decide e grava.
            $denied = $this->_deniedHostMethod($method);
            if ($denied !== null) {
                return [false, $denied, null];
            }
            return $this->_callHostMethod($method, [
                'id' => $id, 'start' => $start, 'end' => $end,
                'resource' => $resource === '' ? null : $resource,
                'resourceId' => $resource === '' ? null : $resource,
            ], true);
        }
        if ($this->editable !== true) {
            return [false, MadLang::t('mad.agenda.not_editable'), null];
        }

        $record = $this->_eventQuery()->find($id);
        if (!$record) {
            return [false, MadLang::t('mad.agenda.not_found'), null];
        }
        $status = $this->statusField !== '' ? (string) data_get($record, $this->statusField) : '';
        if ($status !== '' && in_array($status, self::_csv($this->lockedStatuses), true)) {
            return [false, MadLang::t('mad.agenda.locked'), null];
        }
        if ($kind === 'move' && $this->resourceField !== '' && $resource !== '') {
            $visible = array_column($this->_declarativeResources(), 'id');
            if ($this->resourceModel !== '' && !in_array($resource, $visible, true)) {
                return [false, MadLang::t('mad.agenda.not_found'), null];
            }
        }
        $ok = $this->canMove($record, $start, $end, $resource === '' ? null : $resource);
        if ($ok !== true) {
            return [false, is_string($ok) ? $ok : MadLang::t('mad.agenda.refused'), null];
        }

        $record->{$this->startField} = $start . ':00';
        if ($this->endField !== '') {
            $record->{$this->endField} = $end . ':00';
        }
        if ($kind === 'move' && $this->resourceField !== '' && $resource !== '' && !str_contains($this->resourceField, '.')) {
            $record->{$this->resourceField} = $resource;
        }
        $record->save();
        $this->afterMove($record);

        return [true, '', null];
    }

    // ── Chamada à tela dona ───────────────────────────────────────────────

    /** O perfil pode rodar o método da tela? null = pode; senão a mensagem. */
    private function _deniedHostMethod(string $method): ?string
    {
        $host = $this->_host();
        try {
            $key = PermissionGate::deniedActionKey($host, $method);
        } catch (\Throwable $e) {
            $key = null;
        }

        return $key === null ? null : ActionVocab::denyTitle($key, get_class($host));
    }

    /**
     * Chama o método `$method` da tela dona (pode ser protected) com os
     * parâmetros por NOME. Com `$asOutcome`, traduz o retorno:
     * true/null = ok · false = recusado · string = recusado com a mensagem ·
     * ['ok' => bool, 'message' => '...'] · MadResponse (recusado se trouxer
     * aviso de erro/alerta) · \DomainException/\InvalidArgumentException =
     * recusado com a mensagem da exceção.
     */
    protected function _callHostMethod(string $method, array $data, bool $asOutcome = false): mixed
    {
        $host = $this->_host();
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) || !method_exists($host, $method)) {
            error_log('[MadAgenda] método ' . $method . ' não existe em ' . get_class($host));
            return $asOutcome ? [false, MadLang::t('mad.agenda.invalid'), null] : null;
        }

        try {
            $result = $host instanceof MadComponent
                ? $host->_resolveAndCall($method, $data)
                : $host->$method(...array_values($data));
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $asOutcome ? [false, $e->getMessage(), null] : null;
        }

        if (!$asOutcome) {
            return $result;
        }
        if ($result instanceof MadResponse) {
            return [!self::_hasErrorToast($result), '', $result];
        }
        if ($result === false) {
            return [false, '', null];
        }
        if (is_string($result)) {
            return [$result === '', $result, null];
        }
        if (is_array($result)) {
            return [(bool) ($result['ok'] ?? true), (string) ($result['message'] ?? ''), null];
        }

        return [true, '', null];
    }

    private static function _hasToast(MadResponse $r): bool
    {
        foreach ($r->getOps() as $op) {
            if (in_array($op['op'] ?? '', ['toast', 'alert'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function _hasErrorToast(MadResponse $r): bool
    {
        foreach ($r->getOps() as $op) {
            if (in_array($op['op'] ?? '', ['toast', 'alert'], true)
                && in_array(strtolower((string) ($op['type'] ?? '')), ['error', 'warning', 'danger'], true)) {
                return true;
            }
        }
        return false;
    }

    // ── Contexto (data, visão, recurso, filtros) ──────────────────────────

    /** @return list<string> */
    public function _views(): array
    {
        $v = array_values(array_intersect(self::_csv($this->views), self::VIEWS));
        return $v === [] ? ['day'] : $v;
    }

    private function _stateKey(): string
    {
        return 'mad_agenda_state.' . md5(get_class($this->_host()) . '|' . $this->agendaKey);
    }

    protected function _initialContext(): array
    {
        $attrDate = self::_normDate($this->date);
        $state    = session($this->_stateKey());
        $ctx      = ['date' => $attrDate ?? CarbonImmutable::today()->toDateString(), 'view' => $this->defaultView, 'resource' => '', 'filters' => []];

        if (is_array($state)) {
            // A data do atributo só vence quando a TELA mudou o valor dela.
            $attrChanged = $attrDate !== null && $attrDate !== ($state['attrDate'] ?? null);
            if (!$attrChanged && isset($state['date'])) {
                $ctx['date'] = (string) $state['date'];
            }
            $ctx['view']     = (string) ($state['view'] ?? $ctx['view']);
            $ctx['resource'] = (string) ($state['resource'] ?? '');
            $ctx['filters']  = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        }

        return $this->_sanitizeContext($ctx);
    }

    private function _rememberContext(array $ctx): void
    {
        session([$this->_stateKey() => $ctx + ['attrDate' => self::_normDate($this->date)]]);
    }

    protected function _sanitizeContext(array $ctx): array
    {
        $date = self::_normDate((string) ($ctx['date'] ?? '')) ?? CarbonImmutable::today()->toDateString();
        $view = (string) ($ctx['view'] ?? $this->defaultView);
        if (!in_array($view, $this->_views(), true)) {
            $view = $this->defaultView;
        }
        $resource = (string) ($ctx['resource'] ?? '');
        if (strlen($resource) > 64) {
            $resource = '';
        }
        $filters = [];
        if (is_array($ctx['filters'] ?? null)) {
            foreach ($ctx['filters'] as $k => $v) {
                if (count($filters) >= 30 || !is_string($k) || !preg_match('/^[A-Za-z0-9_.\-\[\]]{1,64}$/', $k)) {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $filters[$k] = mb_substr((string) $v, 0, 200);
                }
            }
        }

        return ['date' => $date, 'view' => $view, 'resource' => $resource, 'filters' => $filters];
    }

    /** Primeiro e último dia (inclusive) carregados para a visão. */
    protected function _range(array $ctx): array
    {
        $d = CarbonImmutable::parse($ctx['date']);
        return match ($ctx['view']) {
            'week'  => [$s = $this->_startOfWeek($d), $s->addDays(6)],
            'month' => [$this->_startOfWeek($d->startOfMonth()), $this->_startOfWeek($d->endOfMonth())->addDays(6)],
            default => [$d, $d],
        };
    }

    private function _startOfWeek(CarbonImmutable $d): CarbonImmutable
    {
        $dow  = (int) $d->dayOfWeek; // 0 = domingo
        $diff = ($dow - $this->weekStart + 7) % 7;
        return $d->subDays($diff)->startOfDay();
    }

    // ── Dados ─────────────────────────────────────────────────────────────

    protected function _isSourceMode(): bool
    {
        return $this->sourceMethod !== '' || $this->model === '';
    }

    /** Payload que o navegador desenha: recursos, eventos, bloqueios e faixa de horas. */
    public function _payload(array $ctx): array
    {
        [$from, $to] = $this->_range($ctx);
        $query = [
            'start' => $from->toDateString(), 'end' => $to->toDateString(), 'view' => $ctx['view'],
            'date' => $ctx['date'], 'resource' => $ctx['resource'] === '' ? null : $ctx['resource'],
            'filters' => $ctx['filters'],
        ];

        $this->_records = [];
        if ($this->sourceMethod !== '') {
            $raw = $this->_callHostMethod($this->sourceMethod, $query);
            $raw = is_array($raw) ? $raw : [];
        } elseif ($this->model !== '') {
            $raw = ['resources' => $this->_declarativeResources(), 'events' => $this->_declarativeEvents($from, $to)];
        } else {
            $raw = [];
        }

        $resources = $this->_normResources(is_array($raw['resources'] ?? null) ? $raw['resources'] : []);
        $events    = $this->_normEvents(is_array($raw['events'] ?? null) ? $raw['events'] : []);
        $blocks    = $this->_normBlocks(is_array($raw['blocks'] ?? null) ? $raw['blocks'] : []);
        [$min, $max] = $this->_timeBounds($resources, $events, $raw['time_range'] ?? null);

        return [
            'date' => $ctx['date'], 'view' => $ctx['view'], 'resource' => $ctx['resource'], 'filters' => $ctx['filters'],
            'start' => $from->toDateString(), 'end' => $to->toDateString(),
            'today' => CarbonImmutable::today()->toDateString(), 'now' => (int) (now()->hour * 60 + now()->minute),
            'min' => $min, 'max' => $max,
            'resources' => $resources, 'events' => $events, 'blocks' => $blocks,
            'truncated' => count($events) >= self::MAX_EVENTS,
        ];
    }

    private function _declarativeResources(): array
    {
        if ($this->resourceModel === '') {
            return [];
        }
        $cls = $this->_resolveModel($this->resourceModel);
        if ($cls === '') {
            return [];
        }
        $q = $cls::query();
        $this->resourceQuery($q);
        if ($this->resourceWhereClosure) {
            ($this->resourceWhereClosure)($q);
        } elseif ($this->resourceWhere !== '') {
            FieldListColumn::applyWhereStringToQuery($q, $this->resourceWhere);
        }
        $order = $this->resourceOrder !== '' ? $this->resourceOrder : (str_contains($this->resourceTitleField, '{') ? '' : $this->resourceTitleField);
        if ($order !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $order)) {
            $q->orderBy($order);
        }
        $out = [];
        foreach ($q->limit(self::MAX_RESOURCES)->get() as $r) {
            $item = [
                'id'       => (string) $r->getKey(),
                'title'    => $this->_fieldOrMask($r, $this->resourceTitleField),
                'subtitle' => $this->resourceSubtitleField !== '' ? $this->_fieldOrMask($r, $this->resourceSubtitleField) : '',
                'avatar'   => $this->resourceAvatarField !== '' ? (string) data_get($r, $this->resourceAvatarField) : '',
                'color'    => $this->resourceColorField !== '' ? (string) data_get($r, $this->resourceColorField) : '',
            ];
            if ($this->businessHours !== '') {
                $item['hours'] = $this->businessHours;
            }
            $out[] = $this->mapResource($r, $item);
        }

        return $out;
    }

    private function _eventQuery(): Builder
    {
        $cls = $this->_resolveModel($this->model);
        if ($cls === '') {
            throw new \InvalidArgumentException('MadAgenda: model "' . $this->model . '" não encontrado.');
        }
        $q = $cls::query();
        $this->query($q);
        if ($this->whereClosure) {
            ($this->whereClosure)($q);
        } elseif ($this->where !== '') {
            FieldListColumn::applyWhereStringToQuery($q, $this->where);
        }

        return $q;
    }

    private function _declarativeEvents(CarbonImmutable $from, CarbonImmutable $to): array
    {
        foreach ([$this->startField, $this->endField, $this->resourceField] as $f) {
            if ($f !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $f)) {
                throw new \InvalidArgumentException("MadAgenda: campo '{$f}' inválido.");
            }
        }
        $rows = $this->_eventQuery()
            ->where($this->startField, '>=', $from->startOfDay()->toDateTimeString())
            ->where($this->startField, '<', $to->addDay()->startOfDay()->toDateTimeString())
            ->orderBy($this->startField)
            ->limit(self::MAX_EVENTS)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $id = (string) $r->getKey();
            $this->_records[$id] = $r;
            $start = (string) data_get($r, $this->startField);
            $end   = $this->endField !== '' ? (string) data_get($r, $this->endField) : '';
            $ev = [
                'id'          => $id,
                'resource_id' => $this->resourceField !== '' ? (string) data_get($r, $this->resourceField) : null,
                'start'       => $start,
                'end'         => $end,
                'title'       => $this->_fieldOrMask($r, $this->titleField),
                'subtitle'    => $this->subtitleField !== '' ? $this->_fieldOrMask($r, $this->subtitleField) : '',
                'avatar'      => $this->avatarField !== '' ? (string) data_get($r, $this->avatarField) : '',
                'color'       => $this->colorField !== '' ? (string) data_get($r, $this->colorField) : '',
                'status'      => $this->statusField !== '' ? (string) data_get($r, $this->statusField) : '',
                'group'       => $this->groupField !== '' ? (string) data_get($r, $this->groupField) : '',
            ];
            $out[] = $this->mapEvent($r, $ev);
        }

        return $out;
    }

    /** "nome" (campo, aceita relação "cliente.nome") ou máscara "{pet.nome} · {servico}". */
    protected function _fieldOrMask(object $r, string $expr): string
    {
        if ($expr === '') {
            return '';
        }
        if (!str_contains($expr, '{')) {
            return (string) data_get($r, $expr);
        }
        $out = (string) preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', fn ($m) => (string) data_get($r, $m[1]), $expr);
        // Máscara com parte vazia: tira o separador que sobrou ("Rex · ").
        return trim((string) preg_replace('/^\s*[·\-|,]\s*|\s*[·\-|,]\s*$/u', '', $out));
    }

    // ── Normalização (o que vai para o navegador) ─────────────────────────

    private function _normResources(array $list): array
    {
        $out = [];
        foreach (array_slice(array_values($list), 0, self::MAX_RESOURCES) as $r) {
            $r = is_object($r) ? (array) $r : $r;
            if (!is_array($r) || !isset($r['id']) || !is_scalar($r['id'])) {
                continue;
            }
            $hours = array_key_exists('hours', $r) ? $this->_normHours($r['hours']) : null;
            $item  = [
                'id'       => (string) $r['id'],
                'title'    => self::_str($r['title'] ?? ($r['name'] ?? $r['id'])),
                'subtitle' => self::_str($r['subtitle'] ?? ''),
                'avatar'   => self::_url($r['avatar'] ?? ''),
                'color'    => self::_color($r['color'] ?? ''),
                'initials' => \Mad\Util\MadInitials::of(self::_str($r['title'] ?? ($r['name'] ?? ''))),
                'hours'    => $hours,
            ];
            if (isset($r['occupancy']) && is_numeric($r['occupancy'])) {
                $item['occupancy'] = max(0, min(100, (int) $r['occupancy']));
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Expediente: null = aberto o dia todo (na faixa de horas); lista de
     * [ini, fim] ("HH:MM") para todos os dias; ou mapa por data "Y-m-d"
     * (ou dia da semana 0–6) → lista. Lista vazia = folga.
     */
    private function _normHours(mixed $h): ?array
    {
        if ($h === null) {
            return null;
        }
        if (is_string($h)) {
            $h = $h === '' ? null : array_map(fn ($p) => explode('-', $p, 2), array_filter(array_map('trim', explode(',', $h))));
            if ($h === null) {
                return null;
            }
        }
        if (!is_array($h)) {
            return null;
        }
        // Mapa por dia: chave "Y-m-d"/"0"–"6" e valor = LISTA de pares (ou vazia).
        // Chaves 0–6 em ordem viram lista no PHP — decide pelo formato do valor.
        $isMap = false;
        foreach ($h as $k => $v) {
            if (!is_int($k) || !is_array($v) || $v === [] || is_array(reset($v))) {
                $isMap = true;
                break;
            }
        }
        if ($isMap) {
            $out = [];
            foreach ($h as $k => $pairs) {
                $k = (string) $k;
                if (preg_match('/^(\d{4}-\d{2}-\d{2}|[0-6])$/', $k)) {
                    $out[$k] = $this->_normPairs(is_array($pairs) ? $pairs : []);
                }
            }
            return ['by' => $out];
        }

        return ['all' => $this->_normPairs($h)];
    }

    private function _normPairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $p) {
            if (!is_array($p) || count($p) < 2) {
                continue;
            }
            $a = self::_minutes($p[0] ?? ($p['start'] ?? null));
            $b = self::_minutes($p[1] ?? ($p['end'] ?? null));
            if ($a !== null && $b !== null && $b > $a) {
                $out[] = [$a, $b];
            }
        }
        usort($out, fn ($x, $y) => $x[0] <=> $y[0]);

        return $out;
    }

    private function _normEvents(array $list): array
    {
        $labels   = self::_kv($this->statusLabels);
        $tones    = self::_kv($this->statusTones);
        $locked   = self::_csv($this->lockedStatuses);
        $canMove  = $this->_movable();
        $canSize  = $this->_resizable();
        $tpl      = $this->eventTemplate !== '' ? base64_decode($this->eventTemplate) : '';
        $out      = [];

        foreach (array_slice(array_values($list), 0, self::MAX_EVENTS) as $e) {
            $e = is_object($e) ? (array) $e : $e;
            if (!is_array($e) || !isset($e['id']) || !is_scalar($e['id'])) {
                continue;
            }
            $start = self::_normDateTime((string) ($e['start'] ?? ''));
            if ($start === null) {
                continue;
            }
            $end = self::_normDateTime((string) ($e['end'] ?? ''));
            if ($end === null || $end <= $start) {
                $end = CarbonImmutable::parse($start)->addMinutes(60)->format('Y-m-d H:i');
            }
            $status = self::_str($e['status'] ?? '');
            $badges = [];
            foreach (array_slice(is_array($e['badges'] ?? null) ? $e['badges'] : [], 0, 6) as $b) {
                if (is_string($b)) {
                    $b = ['text' => $b];
                }
                if (is_array($b) && self::_str($b['text'] ?? '') !== '') {
                    $badges[] = ['text' => self::_str($b['text']), 'tone' => self::_tone($b['tone'] ?? ''), 'title' => self::_str($b['title'] ?? '')];
                }
            }
            $editable = !array_key_exists('editable', $e) || (bool) $e['editable'];
            if ($status !== '' && in_array($status, $locked, true)) {
                $editable = false;
            }
            $ev = [
                'id'           => (string) $e['id'],
                'resource_id'  => isset($e['resource_id']) && is_scalar($e['resource_id']) && (string) $e['resource_id'] !== '' ? (string) $e['resource_id'] : null,
                'start'        => $start,
                'end'          => $end,
                'title'        => self::_str($e['title'] ?? ''),
                'subtitle'     => self::_str($e['subtitle'] ?? ''),
                'avatar'       => self::_url($e['avatar'] ?? ''),
                'color'        => self::_color($e['color'] ?? ''),
                'status'       => $status,
                'status_label' => self::_str($e['status_label'] ?? ($labels[$status] ?? '')),
                'status_tone'  => self::_tone($e['status_tone'] ?? ($tones[$status] ?? '')),
                'badges'       => $badges,
                'group'        => self::_str($e['group'] ?? ''),
                'alert'        => self::_str($e['alert'] ?? ''),
                'movable'      => $canMove && $editable,
                'resizable'    => $canSize && $editable,
            ];
            if ($tpl !== '') {
                $ev['html'] = $this->_renderEventTemplate($tpl, $ev, $e);
            }
            $out[] = $ev;
        }

        return $out;
    }

    private function _renderEventTemplate(string $tpl, array $ev, array $raw): string
    {
        try {
            return \Mad\View\MadBlade::renderString($tpl, [
                'event'  => $ev,
                'item'   => $this->_records[$ev['id']] ?? (object) $raw,
                'agenda' => $this,
            ]);
        } catch (\Throwable $e) {
            error_log('[MadAgenda] cartão próprio falhou: ' . $e->getMessage());
            return '';
        }
    }

    private function _normBlocks(array $list): array
    {
        $out = [];
        foreach (array_slice(array_values($list), 0, 500) as $b) {
            $b = is_object($b) ? (array) $b : $b;
            if (!is_array($b)) {
                continue;
            }
            $start = self::_normDateTime((string) ($b['start'] ?? ''));
            $end   = self::_normDateTime((string) ($b['end'] ?? ''));
            if ($start === null || $end === null || $end <= $start) {
                continue;
            }
            $kind = (string) ($b['kind'] ?? 'block');
            $out[] = [
                'resource_id' => isset($b['resource_id']) && is_scalar($b['resource_id']) && (string) $b['resource_id'] !== '' ? (string) $b['resource_id'] : null,
                'start'       => $start,
                'end'         => $end,
                'label'       => self::_str($b['label'] ?? ''),
                'kind'        => in_array($kind, ['block', 'closed', 'holiday'], true) ? $kind : 'block',
            ];
        }

        return $out;
    }

    /** Faixa de horas (minutos): `time-range`, ou automática pelo expediente e pelos eventos. */
    private function _timeBounds(array $resources, array $events, mixed $override): array
    {
        $fixed = is_string($override) && $override !== '' ? $override : $this->timeRange;
        if ($fixed !== '' && str_contains($fixed, '-')) {
            [$a, $b] = array_map('trim', explode('-', $fixed, 2));
            $a = self::_minutes($a);
            $b = self::_minutes($b);
            if ($a !== null && $b !== null && $b > $a) {
                return [$a, $b];
            }
        }

        $min = null;
        $max = null;
        foreach ($resources as $r) {
            $sets = $r['hours'] === null ? [] : ($r['hours']['all'] ?? array_merge([], ...array_values($r['hours']['by'] ?? [])));
            foreach ($sets as [$a, $b]) {
                $min = $min === null ? $a : min($min, $a);
                $max = $max === null ? $b : max($max, $b);
            }
        }
        foreach ($events as $e) {
            $a = self::_minutes(substr($e['start'], 11, 5));
            $sameDay = substr($e['start'], 0, 10) === substr($e['end'], 0, 10);
            $b = $sameDay ? self::_minutes(substr($e['end'], 11, 5)) : 1440;
            $min = $min === null ? $a : min($min, $a);
            $max = $max === null ? $b : max($max, $b);
        }
        if ($min === null || $max === null || $max <= $min) {
            [$min, $max] = [8 * 60, 18 * 60];
        }
        $min = (int) (floor($min / 60) * 60);
        $max = (int) min(1440, ceil($max / 60) * 60);
        if ($max - $min < 120) {
            $max = min(1440, $min + 120);
        }

        return [$min, $max];
    }

    protected function _movable(): bool
    {
        if ($this->editable === false) {
            return false;
        }
        if ($this->onMove !== '') {
            return true;
        }
        return !$this->_isSourceMode() && $this->editable === true;
    }

    protected function _resizable(): bool
    {
        if ($this->editable === false) {
            return false;
        }
        if ($this->onResize !== '') {
            return true;
        }
        return !$this->_isSourceMode() && $this->editable === true && $this->endField !== '' && $this->onMove === '';
    }

    // ── Config do cliente ─────────────────────────────────────────────────

    public function _clientConfig(): array
    {
        $nav = fn (string $t) => $t === '' ? null : \Mad\Ui\MadAction::navTarget($t, 'show', ['id']);
        $slotNav  = $nav($this->slotClickTarget);
        $eventNav = $nav($this->eventClickTarget);

        return [
            'key'          => $this->agendaKey,
            'views'        => $this->_views(),
            'slot'         => $this->slotMinutes,
            'hourHeight'   => $this->hourHeight,
            'weekStart'    => $this->weekStart,
            'autoRefresh'  => $this->autoRefresh,
            'occupancy'    => $this->showOccupancy,
            'nowLine'      => $this->nowLine,
            'occIgnore'    => self::_csv($this->occupancyIgnore),
            'height'       => $this->height,
            'locale'       => str_replace('_', '-', MadLang::getLocale() ?: 'pt-BR'),
            'slotClick'    => $this->onSlotClick,
            'eventClick'   => $this->onEventClick,
            'slotTarget'   => $slotNav,
            'eventTarget'  => $eventNav,
            'emptyText'    => $this->emptyText,
            'eventLabel'   => self::_eventLabel($this->eventLabel),
            'i18n'         => MadLang::group('mad.agenda'),
        ];
    }

    /**
     * `event-label="atendimento|atendimentos"` → ['atendimento', 'atendimentos'].
     * Uma palavra só vale para os dois. Vazio = null (o cliente usa mad.agenda.event/events).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function _eventLabel(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $parts = array_map(static fn ($p) => mb_substr(trim(strip_tags($p)), 0, 40), explode('|', $raw, 2));
        $one = $parts[0];
        $many = ($parts[1] ?? '') !== '' ? $parts[1] : $one;
        if ($one === '') {
            $one = $many;
        }
        return $one === '' ? null : [$one, $many];
    }

    private function _renderToolbar(): string
    {
        if ($this->toolbar === '') {
            return '';
        }
        try {
            $data = ['agenda' => $this];
            if ($this->_externalHost !== null) {
                $data['that'] = $this->_externalHost;
            }
            return \Mad\View\MadBlade::renderString(base64_decode($this->toolbar), $data);
        } catch (\Throwable $e) {
            error_log('[MadAgenda] toolbar falhou: ' . $e->getMessage());
            return '';
        }
    }

    /** JS que despacha um CustomEvent na(s) agenda(s) desta chave. */
    protected function _eventScript(string $name, array $detail): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $key   = json_encode($this->agendaKey, $flags);
        $event = json_encode($name, $flags);
        $data  = json_encode($detail, $flags);

        return "document.querySelectorAll('[data-mad-agenda]').forEach(function (el) { if (el.getAttribute('data-mad-agenda') === {$key}) "
            . "el.dispatchEvent(new CustomEvent({$event}, { detail: {$data} })); });";
    }

    public function _agendaKey(): string
    {
        return $this->agendaKey;
    }

    /** Chave da agenda da subclasse desenhada sem tag (view default). */
    public function _classKey(): string
    {
        return $this->agendaKey !== '' ? $this->agendaKey : 'ag' . substr(md5(static::class), 0, 10);
    }

    // ── Utilitários ───────────────────────────────────────────────────────

    protected function _resolveModel(string $model): string
    {
        if ($model === '') {
            return '';
        }
        try {
            $cls = ModelOptionsLoader::resolveModelClass($model);
            return class_exists($cls) ? $cls : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** "Y-m-d H:i" a partir de "Y-m-d H:i[:s]", "Y-m-dTH:i" ou objeto de data. */
    public static function _normDateTime(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i');
        }
        $v = trim((string) $v);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $v, $m)) {
            if (!checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4)) || (int) $m[2] > 23 || (int) $m[3] > 59) {
                return null;
            }
            return $m[1] . ' ' . $m[2] . ':' . $m[3];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return self::_normDate($v) !== null ? $v . ' 00:00' : null;
        }

        return null;
    }

    public static function _normDate(string $v): ?string
    {
        $v = trim($v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    private static function _minutes(mixed $hhmm): ?int
    {
        if (is_int($hhmm)) {
            return $hhmm >= 0 && $hhmm <= 1440 ? $hhmm : null;
        }
        if (!is_string($hhmm) || !preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmm), $m)) {
            return null;
        }
        $v = (int) $m[1] * 60 + (int) $m[2];

        return $v <= 1440 ? $v : null;
    }

    private static function _str(mixed $v): string
    {
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, 300) : '';
    }

    /** Cor segura para ir num `style`: hex, rgb()/hsl() ou nome simples. */
    private static function _color(mixed $v): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) || preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/', $v) || preg_match('/^[a-zA-Z]{3,20}$/', $v)) {
            return $v;
        }

        return '';
    }

    /** URL de imagem segura: http(s), caminho relativo/absoluto ou data:image. */
    private static function _url(mixed $v): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        if ($v === '' || strlen($v) > 2000) {
            return '';
        }
        if (preg_match('#^(https?://|/|\./|data:image/(png|jpe?g|gif|webp);)#i', $v) || !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $v)) {
            return $v;
        }

        return '';
    }

    private static function _tone(mixed $v): string
    {
        $v = is_scalar($v) ? strtolower(trim((string) $v)) : '';
        return in_array($v, ['primary', 'success', 'warning', 'danger', 'info', 'muted', 'neutral'], true) ? $v : '';
    }

    /** "a,b , c" → ['a','b','c'] */
    private static function _csv(string $v): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
    }

    /** "a=Rótulo A|b=Rótulo B" → ['a' => 'Rótulo A', ...] */
    private static function _kv(string $v): array
    {
        $out = [];
        foreach (explode('|', $v) as $part) {
            if (str_contains($part, '=')) {
                [$k, $val] = explode('=', $part, 2);
                $out[trim($k)] = trim($val);
            }
        }

        return $out;
    }
}
