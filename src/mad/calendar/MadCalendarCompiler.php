<?php
namespace Mad\Calendar;

/**
 * MadCalendarCompiler — compila <mad-calendar>...</mad-calendar> para PHP puro
 * em compile-time.
 *
 * Chamado por MadBladeOne::compileString() antes do BladeOne processar os
 * componentes <x-*>. As tags <mad-calendar*> nunca chegam ao sistema de
 * componentes Blade — exceto <mad-calendar-filters>, que e consumido por
 * MadDashFiltersCompiler em um passo posterior do pipeline.
 *
 * ┌── Sintaxe suportada ────────────────────────────────────────────────────────┐
 * │                                                                              │
 * │  <mad-calendar>                                                              │
 * │    Atributos: model, database, id-field, title-field, start-field,           │
 * │               end-field, color-field, color, color-map, extra-fields,        │
 * │               order-by, events-url, default-view, time-range, enable-days,   │
 * │               no-weekend, locale, current-date, slot-duration, num-days,     │
 * │               height, full-height, calendar-id, header,                      │
 * │               editable, no-dragging, no-resizing, auto-update,               │
 * │               confirm-update, click-target, click-target-mode,               │
 * │               day-click-target, slot-click-target, event-update-method,      │
 * │               day-click-method, event-click-method, slot-click-method,       │
 * │               popover-title, popover-content, popover-trigger,               │
 * │               period-type, date-field, period-fields, remember-filters,      │
 * │               default-current-period, use-presets, apply-unit-filter,        │
 * │               unit-field, unit-fields                                        │
 * │                                                                              │
 * │  Sub-tags:                                                                   │
 * │    <mad-calendar-toolbar>...</mad-calendar-toolbar>   — Blade arbitrario     │
 * │    <mad-calendar-popover>...</mad-calendar-popover>   — body do popover      │
 * │    <mad-calendar-resource model="..." title-field="..." ... />               │
 * │    <mad-calendar-filter field="..." op="..." value="..." />                  │
 * │    <mad-calendar-resource-filter field="..." op="..." value="..." />         │
 * │                                                                              │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo $that->_renderInlineCalendar([...config...]); ?>
 *
 * Atributos kebab-case sao convertidos para camelCase nas chaves do array.
 */
class MadCalendarCompiler
{
    /**
     * Pattern para casar atributos HTML permitindo `>` dentro de aspas.
     * (Mesma logica do MadKanbanCompiler::ATTRS.)
     */
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-calendar') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio (uniformiza). Lookahead `(?=[\s/>])`
        // garante que NAO casa <mad-calendar-filters>, <mad-calendar-resource>, etc.
        $value = preg_replace_callback(
            '#<mad-calendar(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-calendar' . $m[1] . '></mad-calendar>',
            $value
        );

        // Block form: <mad-calendar ...>...</mad-calendar>
        $value = preg_replace_callback(
            '#<mad-calendar(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-calendar\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: remove sub-tags orfas (fora de <mad-calendar>).
        // <mad-calendar-filters> NAO entra aqui — eh consumido por MadDashFiltersCompiler.
        $orphans = ['toolbar', 'popover', 'resource', 'filter', 'resource-filter'];
        foreach ($orphans as $sub) {
            $tag = preg_quote('mad-calendar-' . $sub, '#');
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '/>#s', '', $value);
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tag . '\s*>#s', '', $value);
        }

        return $value;
    }

    protected static function compileBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';
        $A       = self::ATTRS;

        $attrs       = self::parseAttrs($attrStr);
        $configParts = self::buildBoardConfig($attrs);

        // Toolbar — body Blade arbitrario.
        if (preg_match('#<mad-calendar-toolbar(?=[\s/>])' . $A . '>([\s\S]*?)</mad-calendar-toolbar\s*>#s', $body, $tm)) {
            $configParts[] = "'toolbar' => " . self::qs(base64_encode(trim($tm[1])));
            $body = preg_replace(
                '#<mad-calendar-toolbar(?=[\s/>])' . $A . '>[\s\S]*?</mad-calendar-toolbar\s*>#s',
                '', $body
            );
        }

        // Popover — body Blade (alternativo aos attrs popover-*).
        if (preg_match('#<mad-calendar-popover(?=[\s/>])' . $A . '>([\s\S]*?)</mad-calendar-popover\s*>#s', $body, $pm)) {
            $configParts[] = "'popover' => " . self::qs(base64_encode(trim($pm[1])));
            $body = preg_replace(
                '#<mad-calendar-popover(?=[\s/>])' . $A . '>[\s\S]*?</mad-calendar-popover\s*>#s',
                '', $body
            );
        }

        // Resource — sub-tag unica (self-closing OU bloco vazio).
        $resourceAttrs = self::extractFirst($body, 'mad-calendar-resource');
        if ($resourceAttrs !== null) {
            $configParts[] = "'resource' => " . self::assocArrayLiteral($resourceAttrs);
            $body = preg_replace('#<mad-calendar-resource(?=[\s/>])' . $A . '(?:/>|></mad-calendar-resource\s*>|>\s*</mad-calendar-resource\s*>)#s', '', $body);
        }

        // Filtros fixos (repetivel)
        $filter = self::extractAll($body, 'mad-calendar-filter');
        if (!empty($filter)) {
            $configParts[] = "'filter' => " . self::listOfAssocLiterals($filter);
        }

        // Resource filtros fixos (repetivel)
        $resFilter = self::extractAll($body, 'mad-calendar-resource-filter');
        if (!empty($resFilter)) {
            $configParts[] = "'resourceFilter' => " . self::listOfAssocLiterals($resFilter);
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Calendar\\MadCalendarCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /**
     * Entry point chamado em runtime pelo PHP gerado. Roteia para o host
     * MadCalendarComponent (se for subclass) ou cria MadCalendarStandalone
     * temporário — evita o fatal "Call to a member function on null" quando
     * <mad-calendar> aparece numa view cujo host não é um MadCalendarComponent.
     * Espelha MadGanttCompiler::renderInline.
     */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadCalendarComponent) {
            return $host->_renderInlineCalendar($config);
        }

        return (new MadCalendarStandalone())->_renderInlineCalendar($config);
    }

    /** Configs root do <mad-calendar>. Todos opcionais. */
    protected static function buildBoardConfig(array $a): array
    {
        // Mapa: atributo kebab → chave camelCase usada por _applyInlineConfig.
        $map = [
            'model'                  => 'model',
            'database'               => 'database',
            'id-field'               => 'idField',
            'title-field'            => 'titleField',
            'start-field'            => 'startField',
            'end-field'              => 'endField',
            'color-field'            => 'colorField',
            'resource-field'         => 'resourceField',
            'all-day-field'          => 'allDayField',
            'editable-field'         => 'editableField',
            'color'                  => 'color',
            'color-map'              => 'colorMap',
            'extra-fields'           => 'extraFields',
            'order-by'               => 'orderBy',
            'events-url'             => 'eventsUrl',
            'default-view'           => 'defaultView',
            'time-range'             => 'timeRange',
            'enable-days'            => 'enableDays',
            'locale'                 => 'locale',
            'current-date'           => 'currentDate',
            'slot-duration'          => 'slotDuration',
            'num-days'               => 'numDays',
            'height'                 => 'height',
            'calendar-id'            => 'calendarId',
            'header'                 => 'header',
            'extra-options'          => 'extraOptions',
            'options'                => 'extraOptions',
            'confirm-update'         => 'confirmUpdate',
            'click-target'           => 'clickTarget',
            'click-target-mode'      => 'clickTargetMode',
            'day-click-target'       => 'dayClickTarget',
            'slot-click-target'      => 'slotClickTarget',
            'event-update-method'    => 'eventUpdateMethod',
            'day-click-method'       => 'dayClickMethod',
            'event-click-method'     => 'eventClickMethod',
            'slot-click-method'      => 'slotClickMethod',
            'popover-title'          => 'popoverTitle',
            'popover-content'        => 'popoverContent',
            'popover-trigger'        => 'popoverTrigger',
            'period-type'            => 'periodType',
            'date-field'             => 'dateField',
            'period-fields'          => 'periodFields',
            'unit-field'             => 'unitField',
            'unit-fields'            => 'unitFields',
        ];
        $boolMap = [
            'no-weekend'             => 'noWeekend',
            'full-height'            => 'fullHeight',
            'editable'               => 'editable',
            'no-dragging'            => 'noDragging',
            'no-resizing'            => 'noResizing',
            'auto-update'            => 'autoUpdate',
            'remember-filters'       => 'rememberFilters',
            'default-current-period' => 'defaultToCurrentPeriod',
            'use-presets'            => 'usePresets',
            'apply-unit-filter'      => 'applyUnitFilter',
        ];

        $c = [];
        foreach ($map as $kebab => $camel) {
            if (isset($a[$kebab])) {
                $c[] = "'{$camel}' => " . self::emit($a[$kebab]);
            }
        }
        foreach ($boolMap as $kebab => $camel) {
            if (isset($a[$kebab])) {
                $attr = $a[$kebab];
                $val = match ($attr['type']) {
                    'bool'  => 'true',
                    'php'   => $attr['value'],
                    default => self::truthyString($attr['value']) ? 'true' : 'false',
                };
                $c[] = "'{$camel}' => {$val}";
            }
        }
        return $c;
    }

    /** Captura primeira ocorrencia de uma sub-tag, retornando attrs ou null. */
    protected static function extractFirst(string $body, string $tag): ?array
    {
        $A = self::ATTRS;
        $tagQ = preg_quote($tag, '#');
        $regex = '#<' . $tagQ . '(?=[\s/>])(' . $A . ')(?:/>|></' . $tagQ . '\s*>|>\s*</' . $tagQ . '\s*>)#s';
        if (preg_match($regex, $body, $m)) {
            return self::parseAttrs($m[1]);
        }
        return null;
    }

    /** Captura TODAS ocorrencias de uma sub-tag, retornando lista de attrs. */
    protected static function extractAll(string $body, string $tag): array
    {
        $A = self::ATTRS;
        $tagQ = preg_quote($tag, '#');
        $regex = '#<' . $tagQ . '(?=[\s/>])(' . $A . ')(?:/>|></' . $tagQ . '\s*>|>\s*</' . $tagQ . '\s*>)#s';
        $out = [];
        if (preg_match_all($regex, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[] = self::parseAttrs($m[1]);
            }
        }
        return $out;
    }

    /**
     * Emite array PHP associativo a partir de attrs parseados.
     * Chaves kebab → camelCase.
     */
    protected static function assocArrayLiteral(array $attrs): string
    {
        if (empty($attrs)) return '[]';
        $kvs = [];
        foreach ($attrs as $key => $attr) {
            $camel = self::kebabToCamel($key);
            $kvs[] = "'{$camel}' => " . self::emit($attr);
        }
        return '[' . implode(', ', $kvs) . ']';
    }

    /**
     * Emite array PHP de arrays associativos.
     * @param list<array<string,array>> $list
     */
    protected static function listOfAssocLiterals(array $list): string
    {
        if (empty($list)) return '[]';
        $rows = [];
        foreach ($list as $attrs) {
            $rows[] = self::assocArrayLiteral($attrs);
        }
        return '[' . implode(', ', $rows) . ']';
    }

    /** Emite string literal PHP escapada. */
    protected static function qs(string $s): string
    {
        return "'" . str_replace("'", "\\'", $s) . "'";
    }

    private static function truthyString(string $v): bool
    {
        $v = strtolower(trim($v));
        return !in_array($v, ['', '0', 'false', 'no', 'off'], true);
    }

    /**
     * Analisa string de atributos HTML.
     * Retorna mapa name → ['type' => 'string'|'php'|'bool', 'value' => ...].
     */
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
            if (preg_match('/\G(:?)([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*(["\'])((?:(?!\3)[\s\S])*?)\3/s', $str, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $result[$m[2]] = [
                    'type'  => $m[1] === ':' ? 'php' : 'string',
                    'value' => $m[4],
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

    /** Emite expressao PHP do atributo. */
    protected static function emit(array $attr): string
    {
        return match ($attr['type']) {
            'php'  => html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5),
            'bool' => 'true',
            default => "'" . str_replace("'", "\\'", html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5)) . "'",
        };
    }

    /** kebab-case → camelCase. */
    protected static function kebabToCamel(string $key): string
    {
        if (strpos($key, '-') === false) return $key;
        return lcfirst(str_replace('-', '', ucwords($key, '-')));
    }
}
