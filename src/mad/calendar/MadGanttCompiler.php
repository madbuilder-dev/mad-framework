<?php
namespace Mad\Calendar;

/**
 * MadGanttCompiler — compila <mad-gantt>...</mad-gantt> para PHP puro
 * em compile-time.
 *
 * Chamado por MadBladeOne::compileString() antes do BladeOne processar os
 * componentes <x-*>. As tags <mad-gantt*> nunca chegam ao sistema de
 * componentes Blade — exceto <mad-gantt-filters>, que e consumido por
 * MadDashFiltersCompiler em um passo posterior do pipeline.
 *
 * ┌── Sintaxe suportada ────────────────────────────────────────────────────────┐
 * │                                                                              │
 * │  <mad-gantt>                                                                 │
 * │    Atributos data source: model, database, id-field, name-field,             │
 * │                            start-field, end-field, parent-field,             │
 * │                            progress-field, color-field, owner-field,         │
 * │                            type-field, resource-field, order-by,             │
 * │                            dependency-source, resource-model,                │
 * │                            resource-id-field, resource-name-field,           │
 * │                            resource-role-field, resource-capacity-field,     │
 * │                            assignment-source, filters                        │
 * │                                                                              │
 * │    Atributos visuais:     title, start-date, interval, view-mode, zoom,      │
 * │                            striped-rows, critical-path, auto-schedule,       │
 * │                            show-workload, workload-mode                      │
 * │                                                                              │
 * │    Atributos toolbar:     inline-edit,                                       │
 * │                            multi-select, view-mode-btn, zoom-btn,            │
 * │                            striped-months, full-hours, compact-events,       │
 * │                            minutes-step, locale, popover-title,              │
 * │                            popover-content                                   │
 * │                                                                              │
 * │    Atributos calendar:    working-days, working-hours, holidays              │
 * │                                                                              │
 * │    Atributos callbacks:   on-task-click, on-task-update, on-day-click,       │
 * │                            on-dependency-create, on-dependency-delete,       │
 * │                            on-reload                                         │
 * │                                                                              │
 * │    Atributo legado:       chart (uso programatico: <mad-gantt :chart="$g">)  │
 * │                                                                              │
 * │  Sub-tags:                                                                   │
 * │    <mad-gantt-column field=".." label=".." width=".." format=".." tree align=".."/> │
 * │    <mad-gantt-filter field=".." op=".." value=".." value2=".."/>             │
 * │    <mad-gantt-header-action label=".." icon=".." method=".."/>               │
 * │    <mad-gantt-baseline task-id=".." start=".." end=".."/>                    │
 * │    <mad-gantt-holiday date=".."/>                                            │
 * │    <mad-gantt-resource model=".." id-field=".." name-field=".." .../>        │
 * │    <mad-gantt-resource-filter field=".." op=".." value=".."/>                │
 * │    <mad-gantt-toolbar>...Blade body...</mad-gantt-toolbar>                   │
 * │    <mad-gantt-popover>...Blade body...</mad-gantt-popover>                   │
 * │                                                                              │
 * │  Sub-tags v2 — fontes de fases/pessoas/deps/recursos/alocacoes.              │
 * │                                                                              │
 * │  INLINE (singular, repetivel) — set FIXO hardcoded no Blade.                 │
 * │  Use quando fases/pessoas sao poucas e estaveis (nao justificam tabela):     │
 * │    <mad-gantt-phase id=".." name=".." code=".." hue=".."/>                    │
 * │    <mad-gantt-person id=".." name=".." initials=".." color=".."/>             │
 * │                                                                              │
 * │  DB / AUTO-LOAD (plural, single) — carrega de uma tabela via model Eloquent.        │
 * │  PREFERIR em uso real (dados dinamicos, editaveis, compartilhados):          │
 * │    <mad-gantt-phases model=".." id-field=".." name-field=".." order-by=".."/> │
 * │    <mad-gantt-persons model=".." id-field=".." name-field=".." .../>          │
 * │    <mad-gantt-dependencies model=".." from=".." to=".." type=".." lag=".."/>  │
 * │    <mad-gantt-resources model=".." id-field=".." name-field=".." .../>        │
 * │    <mad-gantt-assignments model=".." task=".." resource=".." hours=".."/>     │
 * │                                                                              │
 * │  Regra: se ambos presentes p/ mesma colecao, o DB (plural) tem prioridade.   │
 * │                                                                              │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output:
 *   Modo declarativo: <?php echo \Mad\Calendar\MadGanttCompiler::renderInline([...], $that ?? null); ?>
 *   Modo legado:      <?php echo (<chart>)->render(); ?>
 *
 * Atributos kebab-case sao convertidos para camelCase nas chaves do array.
 */
class MadGanttCompiler
{
    /** Pattern para casar atributos HTML permitindo `>` dentro de aspas. */
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-gantt') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio. Lookahead garante que NAO casa
        // <mad-gantt-filters>, <mad-gantt-column>, etc.
        $value = preg_replace_callback(
            '#<mad-gantt(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-gantt' . $m[1] . '></mad-gantt>',
            $value
        );

        // Block form: <mad-gantt ...>...</mad-gantt>
        $value = preg_replace_callback(
            '#<mad-gantt(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-gantt\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: remove sub-tags orfas (fora de <mad-gantt>).
        // <mad-gantt-filters> NAO entra — eh consumido por MadDashFiltersCompiler.
        $orphans = [
            'column', 'filter', 'header-action', 'baseline', 'holiday',
            'resource', 'resource-filter', 'toolbar', 'popover',
            'phase', 'person',
            // v2 sub-tags declarativas
            'phases', 'persons', 'dependencies', 'resources', 'assignments',
        ];
        foreach ($orphans as $sub) {
            $tag = preg_quote('mad-gantt-' . $sub, '#');
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

        $attrs = self::parseAttrs($attrStr);

        // Modo legado: <mad-gantt :chart="$g"> ou <mad-gantt chart="$g">
        // → emit echo direto do builder, ignorando todo o resto.
        if (isset($attrs['chart'])) {
            $chartExpr = self::emit($attrs['chart']);
            // Em modo legado, retornar HTML do builder fornecido.
            return "<?php echo ({$chartExpr})->render(); ?>";
        }

        $configParts = self::buildBoardConfig($attrs);

        // Toolbar — body Blade arbitrario (base64).
        if (preg_match('#<mad-gantt-toolbar(?=[\s/>])' . $A . '>([\s\S]*?)</mad-gantt-toolbar\s*>#s', $body, $tm)) {
            $configParts[] = "'toolbar' => " . self::qs(base64_encode(trim($tm[1])));
            $body = preg_replace(
                '#<mad-gantt-toolbar(?=[\s/>])' . $A . '>[\s\S]*?</mad-gantt-toolbar\s*>#s',
                '', $body
            );
        }

        // Popover — body Blade (alternativo aos attrs popover-*).
        if (preg_match('#<mad-gantt-popover(?=[\s/>])' . $A . '>([\s\S]*?)</mad-gantt-popover\s*>#s', $body, $pm)) {
            $configParts[] = "'popoverBody' => " . self::qs(base64_encode(trim($pm[1])));
            $body = preg_replace(
                '#<mad-gantt-popover(?=[\s/>])' . $A . '>[\s\S]*?</mad-gantt-popover\s*>#s',
                '', $body
            );
        }

        // Resource — sub-tag unica.
        $resourceAttrs = self::extractFirst($body, 'mad-gantt-resource');
        if ($resourceAttrs !== null) {
            $configParts[] = "'resource' => " . self::assocArrayLiteral($resourceAttrs);
            $body = preg_replace('#<mad-gantt-resource(?=[\s/>])' . $A . '(?:/>|></mad-gantt-resource\s*>|>\s*</mad-gantt-resource\s*>)#s', '', $body);
        }

        // Sub-tags repetiveis.
        $columns = self::extractAll($body, 'mad-gantt-column');
        if (!empty($columns)) {
            $configParts[] = "'columns' => " . self::listOfAssocLiterals($columns);
        }

        $filter = self::extractAll($body, 'mad-gantt-filter');
        if (!empty($filter)) {
            $configParts[] = "'filter' => " . self::listOfAssocLiterals($filter);
        }

        $headerActions = self::extractAll($body, 'mad-gantt-header-action');
        if (!empty($headerActions)) {
            $configParts[] = "'headerActions' => " . self::listOfAssocLiterals($headerActions);
        }

        $baselines = self::extractAll($body, 'mad-gantt-baseline');
        if (!empty($baselines)) {
            $configParts[] = "'baselines' => " . self::listOfAssocLiterals($baselines);
        }

        $holidays = self::extractAll($body, 'mad-gantt-holiday');
        if (!empty($holidays)) {
            $configParts[] = "'holidayItems' => " . self::listOfAssocLiterals($holidays);
        }

        $resFilter = self::extractAll($body, 'mad-gantt-resource-filter');
        if (!empty($resFilter)) {
            $configParts[] = "'resourceFilter' => " . self::listOfAssocLiterals($resFilter);
        }

        // v2: phases (sub-tag declarativa de cada fase)
        $phases = self::extractAll($body, 'mad-gantt-phase');
        if (!empty($phases)) {
            $configParts[] = "'phases' => " . self::listOfAssocLiterals($phases);
        }

        // v2: people / avatares
        $persons = self::extractAll($body, 'mad-gantt-person');
        if (!empty($persons)) {
            $configParts[] = "'people' => " . self::listOfAssocLiterals($persons);
        }

        // v2: source sub-tags (alternativa declarativa as strings *-source).
        // Cada uma e single (extractFirst) e emite uma chave *Source no config.
        // tag => chave config
        $sourceSubTags = [
            'mad-gantt-phases'       => 'phasesSource',
            'mad-gantt-persons'      => 'personsSource',
            'mad-gantt-dependencies' => 'dependenciesSource',
            'mad-gantt-resources'    => 'resourcesSource',
            'mad-gantt-assignments'  => 'assignmentsSource',
        ];
        foreach ($sourceSubTags as $tag => $cfgKey) {
            $attrs = self::extractFirst($body, $tag);
            if ($attrs !== null) {
                $configParts[] = "'{$cfgKey}' => " . self::assocArrayLiteral($attrs);
            }
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Calendar\\MadGanttCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /**
     * Entry point chamado em runtime pelo PHP gerado.
     * Roteia para o host MadGanttComponent (se for subclass) ou cria
     * MadGanttStandalone temporario.
     */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadGanttComponent) {
            return $host->_renderInlineGantt($config);
        }

        // Host nao e MadGanttComponent — cria standalone temp.
        $standalone = new MadGanttStandalone();

        // Permite que callbacks (on-task-click=onFoo) tentem invocar metodos
        // no host externo via reflexao no _resolveCallback do componente.
        if ($host !== null) {
            $standalone->_setExternalHost($host);
        }

        return $standalone->_renderInlineGantt($config);
    }

    /** Configs root do <mad-gantt>. Todos opcionais. */
    protected static function buildBoardConfig(array $a): array
    {
        // Mapa: atributo kebab → chave camelCase usada por _applyInlineConfig.
        $map = [
            // Data source
            'model'                    => 'model',
            'database'                 => 'database',
            'id-field'                 => 'idField',
            'name-field'               => 'nameField',
            'start-field'              => 'startField',
            'end-field'                => 'endField',
            'parent-field'             => 'parentField',
            'progress-field'           => 'progressField',
            'color-field'              => 'colorField',
            'owner-field'              => 'ownerField',
            'type-field'               => 'typeField',
            'code-field'               => 'codeField',
            'milestone-field'          => 'milestoneField',
            'resource-field'           => 'resourceField',
            'order-by'                 => 'orderBy',
            'dependency-source'        => 'dependencySource',
            'resource-model'           => 'resourceModel',
            'resource-id-field'        => 'resourceIdField',
            'resource-name-field'     => 'resourceNameField',
            'resource-role-field'      => 'resourceRoleField',
            'resource-capacity-field'  => 'resourceCapacityField',
            'assignment-source'        => 'assignmentSource',
            'filters'                  => 'filters',
            // Visual
            'title'                    => 'ganttTitle',
            'start-date'               => 'startDate',
            'interval'                 => 'interval',
            'view-mode'                => 'viewMode',
            'zoom'                     => 'zoom',
            'workload-mode'            => 'workloadMode',
            'progress-scale'           => 'progressScale',
            // v2 visual
            'phase-field'              => 'phaseField',
            'arrow-style'              => 'arrowStyle',
            'density'                  => 'density',
            'task-col-width'           => 'taskColWidth',
            // v2 collections (use :phases="$arr" / :people="$arr" para passar PHP)
            'phases'                   => 'phases',
            'people'                   => 'people',
            // v2 source-style auto-load
            'phase-source'             => 'phaseSource',
            'person-source'            => 'personSource',
            // Toolbar / features
            'minutes-step'             => 'minutesStep',
            'locale'                   => 'locale',
            'popover-title'            => 'popoverTitle',
            'popover-content'          => 'popoverContent',
            // Calendar
            'working-days'             => 'workingDays',
            'working-hours'            => 'workingHours',
            'holidays'                 => 'holidays',
            // Callbacks
            'on-task-click'            => 'onTaskClick',
            'on-task-update'           => 'onTaskUpdate',
            'on-day-click'             => 'onDayClick',
            'on-dependency-create'     => 'onDependencyCreate',
            'on-dependency-delete'     => 'onDependencyDelete',
            'on-reload'                => 'onReload',
        ];

        $boolMap = [
            'striped-rows'             => 'stripedRows',
            'critical-path'            => 'criticalPath',
            'auto-schedule'            => 'autoSchedule',
            'show-workload'            => 'showWorkload',
            'inline-edit'              => 'inlineEdit',
            'multi-select'             => 'multiSelect',
            'view-mode-btn'            => 'viewModeBtn',
            'zoom-btn'                 => 'zoomBtn',
            'striped-months'           => 'stripedMonths',
            'full-hours'               => 'fullHours',
            'compact-events'           => 'compactEvents',
            // v2 visual toggles
            'show-minimap'             => 'showMinimap',
            'show-search'              => 'showSearch',
            'show-weekends'            => 'showWeekends',
            'show-grid'                => 'showGrid',
            'show-avatars'             => 'showAvatars',
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
                    default => self::truthyString((string) $attr['value']) ? 'true' : 'false',
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

    /** Captura TODAS ocorrencias de uma sub-tag. */
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

    /** Emite array PHP associativo a partir de attrs parseados. */
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

    /** Emite array PHP de arrays associativos. */
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
            // Valor SEM aspas (width=100): antes o nome virava bool true e o
            // valor era descartado silenciosamente.
            if (preg_match('/\G(:?)([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*([^\s"\'=<>\/`]+)/', $str, $m, 0, $pos)) {
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
