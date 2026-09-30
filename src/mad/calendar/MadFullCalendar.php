<?php
namespace Mad\Calendar;
use Mad\Component\MadComponent;
use Mad\View\MadBlade;


/**
 * MadFullCalendar — Builder interno consumido pelo MadCalendarComponent.
 *
 * @internal Engine que gera a configuração JSON do `madFullCalendar()` Alpine.
 *           A API publica do framework eh o <mad-calendar> (Blade) + a classe
 *           abstract MadCalendarComponent. NAO usar este builder diretamente
 *           em controllers de aplicacao — use <mad-calendar> + subclass de
 *           MadCalendarComponent.
 */
class MadFullCalendar
{
    protected string  $calendarId   = '';
    protected string  $currentDate  = '';
    protected string  $defaultView  = 'dayGridMonth';
    protected string  $minTime      = '00:00:00';
    protected string  $maxTime      = '24:00:00';
    protected array   $enabledDays  = [0, 1, 2, 3, 4, 5, 6];
    protected bool    $movable      = true;
    protected bool    $resizable    = true;
    protected bool    $isEditable   = false;
    protected bool    $fullHeightOn = false;
    protected int     $heightPx     = 0;
    protected string  $locale       = 'pt-br';
    protected array   $events       = [];
    protected string  $eventsUrlStr = '';
    protected array   $options      = [];

    // Ações mad-wire
    protected string $dayClickMethod    = '';
    protected string $eventClickMethod  = '';
    protected string $eventUpdateMethod = '';

    // Popover
    protected string $popTitle   = '';
    protected string $popContent = '';

    // Resource Timeline
    protected array   $resources         = [];
    protected string  $resourcesUrlStr   = '';
    protected string  $resourceLabel     = 'Recurso';
    protected string  $slotClickMethod   = '';
    protected string  $slotDurationStr   = '01:00';

    // ── Factory ────────────────────────────────────────────────────────

    public static function make(?string $currentDate = null): static
    {
        $instance = new static();
        if ($currentDate) {
            $instance->currentDate = $currentDate;
        }
        return $instance;
    }

    // ── Configuração ───────────────────────────────────────────────────

    /**
     * Define um ID fixo para o container do calendário.
     * Útil para referenciar via JS: document.querySelector('#meu-cal')
     */
    public function id(string $id): static
    {
        $this->calendarId = $id;
        return $this;
    }

    public function currentDate(string $date): static
    {
        $this->currentDate = $date;
        return $this;
    }

    /**
     * View padrão do calendário.
     * Valores: dayGridMonth, timeGridWeek, timeGridDay, listWeek
     * Atalhos: month, agendaWeek, agendaDay, listWeek
     */
    public function defaultView(string $view): static
    {
        $map = [
            'month'      => 'dayGridMonth',
            'agendaWeek' => 'timeGridWeek',
            'agendaDay'  => 'timeGridDay',
            'listWeek'   => 'listWeek',
        ];
        $this->defaultView = $map[$view] ?? $view;
        return $this;
    }

    public function timeRange(string $min, string $max): static
    {
        $this->minTime = $min;
        $this->maxTime = $max;
        return $this;
    }

    /**
     * Dias visíveis (0=Dom, 1=Seg, ..., 6=Sab).
     */
    public function enableDays(array $days): static
    {
        $this->enabledDays = $days;
        return $this;
    }

    public function disableWeekend(): static
    {
        $this->enabledDays = [1, 2, 3, 4, 5];
        return $this;
    }

    public function height(int $px): static
    {
        $this->heightPx = $px;
        return $this;
    }

    public function fullHeight(bool $v = true): static
    {
        $this->fullHeightOn = $v;
        return $this;
    }

    public function editable(bool $v = true): static
    {
        $this->isEditable = $v;
        return $this;
    }

    public function disableDragging(): static
    {
        $this->movable = false;
        return $this;
    }

    public function disableResizing(): static
    {
        $this->resizable = false;
        return $this;
    }

    public function locale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Passa opção direta para o FullCalendar.
     */
    public function option(string $key, mixed $val): static
    {
        $this->options[$key] = $val;
        return $this;
    }

    public function popover(string $title, string $content): static
    {
        $this->popTitle   = $title;
        $this->popContent = $content;
        return $this;
    }

    // ── Eventos ────────────────────────────────────────────────────────

    /**
     * Adiciona evento estático.
     */
    public function addEvent(
        string  $id,
        string  $title,
        string  $start,
        ?string $end   = null,
        ?string $color = null,
        array   $extra = []
    ): static {
        $evt = array_merge($extra, [
            'id'    => $id,
            'title' => $title,
            'start' => $start,
        ]);
        if ($end)   $evt['end']   = $end;
        if ($color) $evt['color'] = $color;

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

    /**
     * URL para carregamento dinâmico de eventos.
     * O FullCalendar envia ?start=...&end=... automaticamente.
     */
    public function eventsUrl(string $url): static
    {
        $this->eventsUrlStr = $url;
        return $this;
    }

    // ── Ações (nomes de métodos do MadComponent pai) ───────────────────

    /**
     * Método chamado ao clicar em um dia.
     * Recebe: (string $date, string $view)
     */
    public function onDayClick(string $method): static
    {
        $this->dayClickMethod = $method;
        return $this;
    }

    /**
     * Método chamado ao clicar em um evento.
     * Recebe: (string $id, string $title, string $view)
     */
    public function onEventClick(string $method): static
    {
        $this->eventClickMethod = $method;
        return $this;
    }

    /**
     * Método chamado ao arrastar/redimensionar evento.
     * Recebe: (string $id, string $start, string $end)
     */
    public function onEventUpdate(string $method): static
    {
        $this->eventUpdateMethod = $method;
        return $this;
    }

    // ── Resources (Timeline View) ────────────────────────────────────

    /**
     * Adiciona um recurso (sala, pessoa, equipamento).
     * Ao definir resources, o render usa a view resource-timeline.
     */
    public function addResource(string $id, string $title, ?string $color = null, array $extra = []): static
    {
        $res = array_merge($extra, ['id' => $id, 'title' => $title]);
        if ($color) $res['color'] = $color;
        $this->resources[] = $res;
        return $this;
    }

    public function resources(array $resources): static
    {
        $this->resources = $resources;
        return $this;
    }

    public function resourcesUrl(string $url): static
    {
        $this->resourcesUrlStr = $url;
        return $this;
    }

    public function resourceLabel(string $label): static
    {
        $this->resourceLabel = $label;
        return $this;
    }

    /**
     * Número de dias visíveis no modo multi-dia (default: 4).
     */
    public function numDays(int $n): static
    {
        $this->options['numDays'] = $n;
        return $this;
    }

    /**
     * Duração de cada slot na timeline. Ex: '01:00', '00:30'
     */
    public function slotDuration(string $duration): static
    {
        $this->slotDurationStr = $duration;
        return $this;
    }

    /**
     * Método chamado ao clicar em um slot vazio da timeline.
     * Recebe: (string $date, string $resourceId, string $resourceTitle)
     */
    public function onSlotClick(string $method): static
    {
        $this->slotClickMethod = $method;
        return $this;
    }

    // ── Output ─────────────────────────────────────────────────────────

    /**
     * Retorna [view, data] para uso em MadComponent::view().
     */
    public function view(): array
    {
        $allDays = [0, 1, 2, 3, 4, 5, 6];
        $hidden  = array_values(array_diff($allDays, $this->enabledDays));

        $config = [
            'calendarId'        => $this->calendarId,
            'currentDate'       => $this->currentDate ?: date('Y-m-d'),
            'defaultView'       => $this->defaultView,
            'events'            => $this->events,
            'eventsUrl'         => $this->eventsUrlStr,
            'minTime'           => $this->minTime,
            'maxTime'           => $this->maxTime,
            'hiddenDays'        => $hidden,
            'enabledDays'       => $this->enabledDays,
            'editable'          => $this->isEditable,
            'movable'           => $this->movable,
            'resizable'         => $this->resizable,
            'height'            => $this->heightPx,
            'fullHeight'        => $this->fullHeightOn,
            'locale'            => $this->locale,
            'dayClickMethod'    => $this->dayClickMethod,
            'eventClickMethod'  => $this->eventClickMethod,
            'eventUpdateMethod' => $this->eventUpdateMethod,
            'popTitle'          => $this->popTitle,
            'popContent'        => $this->popContent,
            'extraOptions'      => $this->options,
        ];

        // Se tem resources, usa a view de resource timeline
        if (!empty($this->resources) || !empty($this->resourcesUrlStr)) {
            $config['resources']       = $this->resources;
            $config['resourcesUrl']    = $this->resourcesUrlStr;
            $config['resourceLabel']   = $this->resourceLabel;
            $config['slotClickMethod'] = $this->slotClickMethod;
            $config['slotDuration']    = $this->slotDurationStr;
            return ['components.resource-timeline', ['config' => $config]];
        }

        return ['components.full-calendar', ['config' => $config]];
    }

    /**
     * Renderiza o HTML do calendário.
     */
    public function render(): string
    {
        [$view, $data] = $this->view();
        return MadBlade::render($view, $data);
    }
}