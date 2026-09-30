<?php
namespace Mad\OrgChart;

/**
 * MadOrgChartCompiler — compila <mad-org-chart>...</mad-org-chart> para PHP
 * puro em compile-time.
 *
 * Chamado por MadBladeCompiler::compileString() antes do BladeOne processar
 * os componentes <x-*>.
 *
 * ┌── Sintaxe suportada ───────────────────────────────────────────────────────┐
 * │                                                                             │
 * │  <mad-org-chart> — Atributos                                               │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │  model="Employee"            Model dos nós (obrigatório)                   │
 * │  database="business"          Conexão (default MAIN_DATABASE)               │
 * │  parent-field="manager_id"   FK auto-referente (default parent_id)         │
 * │  title="nome"                Campo do título (default nome)                │
 * │  subtitle="cargo"            Campo do subtítulo (opcional)                 │
 * │  avatar-field="foto"         Campo de foto (opcional; sem = iniciais)      │
 * │  metric="equipe_count"       Campo numérico do badge (opcional)            │
 * │  metric-label="equipe"       Rótulo da métrica                             │
 * │  order-field="nome"          Ordenação dos irmãos                          │
 * │  draggable                   Habilita drag re-parent                       │
 * │  lazy-depth="2"              Níveis no render inicial (0 = tudo)           │
 * │  max-nodes="1000"            Guard de nós por request                      │
 * │  root-id="5"                 Restringe a árvore a um nó raiz               │
 * │  click-target="Form::onShow({id})"  Abre form ao clicar no card            │
 * │  where="ativo=1|tipo=P"      Filtro DSL (ou :where="$filter_x" Closure)    │
 * │                                                                             │
 * │  <mad-org-chart-card> — Template custom do card (Blade, por nó)            │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │  Body arbitrário com $item, $id e $chart em escopo.                        │
 * │                                                                             │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo \Mad\OrgChart\MadOrgChartCompiler::renderInline([...], $that ?? null); ?>
 */
class MadOrgChartCompiler
{
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    private const STRING_PROPS = [
        'model'        => 'model',
        'database'     => 'database',
        'parent-field' => 'parentField',
        'title'        => 'titleField',
        'subtitle'     => 'subtitleField',
        'avatar-field' => 'avatarField',
        'metric'       => 'metricField',
        'metric-label' => 'metricLabel',
        'order-field'  => 'orderField',
        'click-target' => 'clickTarget',
    ];

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-org-chart') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio. Lookahead evita <mad-org-chart-card/>.
        $value = preg_replace_callback(
            '#<mad-org-chart(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-org-chart' . $m[1] . '></mad-org-chart>',
            $value
        );

        $value = preg_replace_callback(
            '#<mad-org-chart(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-org-chart\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: card órfão fora de <mad-org-chart> some.
        $tag = preg_quote('mad-org-chart-card', '#');
        $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '/>#s', '', $value);
        $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tag . '\s*>#s', '', $value);

        return $value;
    }

    protected static function compileBlock(array $match): string
    {
        $attrs = self::parseAttrs($match[1] ?? '');
        $body  = $match[2] ?? '';

        $configParts = [];
        foreach (self::STRING_PROPS as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $configParts[] = "'{$prop}' => " . self::emit($attrs[$attr]);
            }
        }
        foreach (['lazy-depth' => 'lazyDepth', 'max-nodes' => 'maxNodes', 'root-id' => 'rootId'] as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $a = $attrs[$attr];
                $configParts[] = "'{$prop}' => " . ($a['type'] === 'php' ? $a['value'] : (int) $a['value']);
            }
        }
        if (isset($attrs['draggable'])) {
            $configParts[] = "'draggable' => " . self::emitBool($attrs['draggable']);
        }
        // where: DSL string ("ativo=1|tipo=P") OU :where="$filter_x" —
        // Closure PHP crua do builder (emit já devolve o código sem cast).
        if (isset($attrs['where'])) {
            $configParts[] = "'where' => " . self::emit($attrs['where']);
        }

        // Card custom: body do <mad-org-chart-card> vira template base64
        // (renderizado por nó via MadBlade::renderString — padrão kanban).
        if (preg_match('#<mad-org-chart-card(?=[\s/>])' . self::ATTRS . '>([\s\S]*?)</mad-org-chart-card\s*>#s', $body, $m)) {
            $tpl = trim($m[1]);
            if ($tpl !== '') {
                $configParts[] = "'cardTemplate' => '" . base64_encode($tpl) . "'";
            }
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\OrgChart\\MadOrgChartCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /** Entry point runtime: roteia pro host MadOrgChart ou Standalone. */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadOrgChart) {
            return $host->_renderInlineOrgChart($config);
        }

        return (new MadOrgChartStandalone())->_renderInlineOrgChart($config);
    }

    // ── Helpers (espelham MadKanbanCompiler) ──────────────────────────────

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

    protected static function emit(array $attr): string
    {
        return match ($attr['type']) {
            'php'  => html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5),
            'bool' => 'true',
            default => "'" . str_replace("'", "\\'", html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5)) . "'",
        };
    }

    private static function truthyString(string $v): bool
    {
        $v = strtolower(trim($v));
        return !in_array($v, ['', '0', 'false', 'no', 'off'], true);
    }

    private static function emitBool(array $attr): string
    {
        return match ($attr['type']) {
            'bool'  => 'true',
            'php'   => (string) $attr['value'],
            default => self::truthyString((string) $attr['value']) ? 'true' : 'false',
        };
    }
}
