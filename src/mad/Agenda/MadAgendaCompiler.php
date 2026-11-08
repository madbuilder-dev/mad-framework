<?php
namespace Mad\Agenda;

/**
 * MadAgendaCompiler — compila <mad-agenda>...</mad-agenda> para PHP puro em
 * compile-time (chamado por MadBlade::compileString, passo 1.13).
 *
 * ┌── Sintaxe ─────────────────────────────────────────────────────────────────┐
 * │                                                                             │
 * │  <mad-agenda> — atributos (todos opcionais; veja o README do componente)    │
 * │    Fonte por método da tela: source-method="agendaDados"                    │
 * │    Fonte declarativa: model, database, resource-field, start-field,         │
 * │      end-field, title-field (máscara "{a} · {b}"), subtitle-field,          │
 * │      color-field, status-field, avatar-field, where (DSL ou :where),        │
 * │      resource-model, resource-title-field, resource-subtitle-field,         │
 * │      resource-avatar-field, resource-color-field, resource-order,           │
 * │      resource-where, business-hours                                         │
 * │    Ações: on-move, on-resize, on-slot-click, on-event-click,                │
 * │      on-range-change, slot-click-target, event-click-target                 │
 * │    Visual: default-view, views, date (:date), time-range, slot-minutes,     │
 * │      hour-height, week-start, height, agenda-id, editable, status-labels,   │
 * │      status-tones, locked-statuses, occupancy-ignore, show-occupancy,       │
 * │      now-line, auto-refresh                                                 │
 * │                                                                             │
 * │  <mad-agenda-event> — cartão próprio (Blade por evento: $event, $agenda)    │
 * │  <mad-agenda-toolbar> — Blade na barra (filtros/botões). Campo com `name`   │
 * │                         dentro dela vira filtro: muda → a agenda recarrega │
 * │                         e o método da fonte recebe em `filters`.            │
 * │                                                                             │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo \Mad\Agenda\MadAgendaCompiler::renderInline([...], $that ?? null); ?>
 */
class MadAgendaCompiler
{
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    /** Atributo kebab → chave da config (string). */
    private const STRING_PROPS = [
        'agenda-id'               => 'agendaId',
        'source-method'           => 'sourceMethod',
        'model'                   => 'model',
        'database'                => 'database',
        'resource-field'          => 'resourceField',
        'start-field'             => 'startField',
        'end-field'               => 'endField',
        'title-field'             => 'titleField',
        'subtitle-field'          => 'subtitleField',
        'color-field'             => 'colorField',
        'status-field'            => 'statusField',
        'avatar-field'            => 'avatarField',
        'group-field'             => 'groupField',
        'resource-model'          => 'resourceModel',
        'resource-title-field'    => 'resourceTitleField',
        'resource-subtitle-field' => 'resourceSubtitleField',
        'resource-avatar-field'   => 'resourceAvatarField',
        'resource-color-field'    => 'resourceColorField',
        'resource-order'          => 'resourceOrder',
        'business-hours'          => 'businessHours',
        'on-move'                 => 'onMove',
        'on-resize'               => 'onResize',
        'on-slot-click'           => 'onSlotClick',
        'on-event-click'          => 'onEventClick',
        'on-range-change'         => 'onRangeChange',
        'slot-click-target'       => 'slotClickTarget',
        'event-click-target'      => 'eventClickTarget',
        'default-view'            => 'defaultView',
        'views'                   => 'views',
        'date'                    => 'date',
        'time-range'              => 'timeRange',
        'height'                  => 'height',
        'status-labels'           => 'statusLabels',
        'status-tones'            => 'statusTones',
        'locked-statuses'         => 'lockedStatuses',
        'occupancy-ignore'        => 'occupancyIgnore',
        'empty-text'              => 'emptyText',
        'event-label'             => 'eventLabel',
    ];

    private const INT_PROPS = [
        'slot-minutes' => 'slotMinutes',
        'hour-height'  => 'hourHeight',
        'week-start'   => 'weekStart',
        'auto-refresh' => 'autoRefresh',
    ];

    private const BOOL_PROPS = [
        'editable'       => 'editable',
        'show-occupancy' => 'showOccupancy',
        'now-line'       => 'nowLine',
    ];

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-agenda') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → bloco vazio. Lookahead evita casar <mad-agenda-event/>.
        $value = preg_replace_callback(
            '#<mad-agenda(?=[\s/>])(' . $A . ')\s*/>#s',
            fn (array $m): string => '<mad-agenda' . $m[1] . '></mad-agenda>',
            $value
        );

        $value = preg_replace_callback(
            '#<mad-agenda(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-agenda\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: sub-tag órfã (fora de <mad-agenda>) some.
        foreach (['mad-agenda-event', 'mad-agenda-toolbar'] as $sub) {
            $tag   = preg_quote($sub, '#');
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '/>#s', '', $value);
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tag . '\s*>#s', '', $value);
        }

        return $value;
    }

    protected static function compileBlock(array $match): string
    {
        $rawAttrs = $match[1] ?? '';
        $attrs    = self::parseAttrs($rawAttrs);
        $body     = $match[2] ?? '';

        $parts = [];
        foreach (self::STRING_PROPS as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $parts[] = "'{$prop}' => " . self::emit($attrs[$attr]);
            }
        }
        foreach (self::INT_PROPS as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $a       = $attrs[$attr];
                $parts[] = "'{$prop}' => " . ($a['type'] === 'php' ? '(int) (' . self::emit($a) . ')' : (int) $a['value']);
            }
        }
        foreach (self::BOOL_PROPS as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $parts[] = "'{$prop}' => " . self::emitBool($attrs[$attr]);
            }
        }
        // where / resource-where: DSL ("ativo=1|tipo=P") ou :where="$filtro" (Closure).
        foreach (['where' => 'where', 'resource-where' => 'resourceWhere'] as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $parts[] = "'{$prop}' => " . self::emit($attrs[$attr]);
            }
        }

        if (preg_match('#<mad-agenda-event(?=[\s/>])' . self::ATTRS . '>([\s\S]*?)</mad-agenda-event\s*>#s', $body, $m)) {
            $tpl = trim($m[1]);
            if ($tpl !== '') {
                $parts[] = "'eventTemplate' => '" . base64_encode($tpl) . "'";
            }
        }
        if (preg_match('#<mad-agenda-toolbar(?=[\s/>])' . self::ATTRS . '>([\s\S]*?)</mad-agenda-toolbar\s*>#s', $body, $m)) {
            $tpl = trim($m[1]);
            if ($tpl !== '') {
                $parts[] = "'toolbar' => '" . base64_encode($tpl) . "'";
            }
        }

        // Chave estável da agenda: vem do texto da tag (compile-time), não dos
        // valores — `:date="$x"` muda a cada dia e a ação precisa achar a MESMA
        // agenda na tela que a desenhou.
        $key = isset($attrs['agenda-id']) && $attrs['agenda-id']['type'] === 'string'
            ? (string) $attrs['agenda-id']['value']
            : 'ag' . substr(md5(trim($rawAttrs)), 0, 10);
        $parts[] = "'key' => '" . preg_replace('/[^A-Za-z0-9_-]/', '', $key) . "'";

        $configStr = "[\n    " . implode(",\n    ", $parts) . "\n]";

        return "<?php echo \\Mad\\Agenda\\MadAgendaCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /**
     * Entry point runtime: host que é MadAgenda desenha a si mesmo; numa ação
     * da agenda embutida, a sonda recebe a config; senão uma agenda avulsa
     * ligada à tela dona (que atende `source-method`/`on-move`).
     */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadAgenda) {
            return $host->_renderInlineAgenda($config);
        }

        if (($probe = MadAgenda::_pendingHostProbe()) !== null) {
            return $probe->_renderInlineAgenda($config);
        }

        $standalone = new MadAgendaStandalone();
        if ($host !== null) {
            $standalone->_setExternalHost($host);
        }

        return $standalone->_renderInlineAgenda($config);
    }

    // ── Helpers (espelham MadOrgChartCompiler) ───────────────────────────

    protected static function parseAttrs(string $str): array
    {
        $result = [];
        $pos    = 0;
        $len    = strlen($str);

        while ($pos < $len) {
            if (preg_match('/\G\s+/', $str, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G(:?)([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*(?|"([^"]*)"|\'([^\']*)\')/s', $str, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $result[$m[2]] = [
                    'type'  => $m[1] === ':' ? 'php' : 'string',
                    'value' => $m[3],
                ];
                continue;
            }
            if (preg_match('/\G([a-zA-Z][a-zA-Z0-9_-]*)/', $str, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $result[$m[1]] = ['type' => 'bool', 'value' => true];
                continue;
            }
            $pos++;
        }

        return $result;
    }

    protected static function emit(array $attr): string
    {
        return match ($attr['type']) {
            'php'   => html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5),
            'bool'  => 'true',
            default => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5)) . "'",
        };
    }

    private static function emitBool(array $attr): string
    {
        return match ($attr['type']) {
            'bool'  => 'true',
            'php'   => '(bool) (' . (string) $attr['value'] . ')',
            default => in_array(strtolower(trim((string) $attr['value'])), ['', '0', 'false', 'no', 'off'], true) ? 'false' : 'true',
        };
    }
}
