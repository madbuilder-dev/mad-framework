<?php
namespace Mad\Reconcile;

/**
 * MadReconcileCompiler — compila <mad-reconcile ... /> para PHP puro
 * em compile-time.
 *
 * Chamado por MadBladeCompiler::compileString() antes do BladeOne processar
 * os componentes <x-*>.
 *
 * ┌── Sintaxe suportada ───────────────────────────────────────────────────────┐
 * │                                                                             │
 * │  <mad-reconcile ... />  (self-closing ou par, sem filhos)                   │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │  left-model="BankStatementLine"   Model do painel esquerdo (obrigatório)   │
 * │  right-model="LedgerEntry"        Model do painel direito (obrigatório)    │
 * │  database="business"               Conexão (default MAIN_DATABASE)          │
 * │  match-on="amount,date:3,document" Regras do auto-match (MatchEngine)      │
 * │  left-amount / right-amount        Campo de valor (default "valor")        │
 * │  left-date / right-date            Campo de data (opcional)                │
 * │  left-doc / right-doc              Campo de documento (opcional)           │
 * │  left-label / right-label          Campo de descrição exibido (opcional)   │
 * │  left-title / right-title          Título do painel (default: model)       │
 * │  tolerance="0.01"                  Tolerância de soma no match manual      │
 * │  max-rows="500"                    Cap de linhas por painel                │
 * │  context="banco-conta1"            Namespace da conciliação                │
 * │                                                                             │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo \Mad\Reconcile\MadReconcileCompiler::renderInline([...], $that ?? null); ?>
 */
class MadReconcileCompiler
{
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    /** attr kebab → prop do MadReconcile (strings). */
    private const STRING_PROPS = [
        'left-model'   => 'leftModel',
        'right-model'  => 'rightModel',
        'database'     => 'database',
        'match-on'     => 'matchOn',
        'left-amount'  => 'leftAmount',
        'right-amount' => 'rightAmount',
        'left-date'    => 'leftDate',
        'right-date'   => 'rightDate',
        'left-doc'     => 'leftDoc',
        'right-doc'    => 'rightDoc',
        'left-label'   => 'leftLabel',
        'right-label'  => 'rightLabel',
        'left-title'   => 'leftTitle',
        'right-title'  => 'rightTitle',
        'context'      => 'context',
    ];

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-reconcile') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio (caminho único).
        $value = preg_replace_callback(
            '#<mad-reconcile(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-reconcile' . $m[1] . '></mad-reconcile>',
            $value
        );

        return preg_replace_callback(
            '#<mad-reconcile(?=[\s/>])(' . $A . ')>[\s\S]*?</mad-reconcile\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );
    }

    protected static function compileBlock(array $match): string
    {
        $attrs = self::parseAttrs($match[1] ?? '');

        $configParts = [];
        foreach (self::STRING_PROPS as $attr => $prop) {
            if (isset($attrs[$attr])) {
                $configParts[] = "'{$prop}' => " . self::emit($attrs[$attr]);
            }
        }
        if (isset($attrs['tolerance'])) {
            $a = $attrs['tolerance'];
            $configParts[] = "'tolerance' => " . ($a['type'] === 'php' ? $a['value'] : (float) $a['value']);
        }
        if (isset($attrs['max-rows'])) {
            $a = $attrs['max-rows'];
            $configParts[] = "'maxRows' => " . ($a['type'] === 'php' ? $a['value'] : (int) $a['value']);
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Reconcile\\MadReconcileCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /** Entry point runtime: roteia pro host MadReconcile ou Standalone. */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadReconcile) {
            return $host->_renderInlineReconcile($config);
        }

        return (new MadReconcileStandalone())->_renderInlineReconcile($config);
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
}
