<?php
namespace Mad\Sheet;

/**
 * MadSheetCompiler — compila <mad-sheet>...</mad-sheet> para PHP puro
 * em compile-time.
 *
 * Chamado por MadBladeCompiler::compileString() antes do BladeOne processar
 * os componentes <x-*>. As tags <mad-sheet*> nunca chegam ao sistema de
 * componentes Blade.
 *
 * ┌── Sintaxe suportada ───────────────────────────────────────────────────────┐
 * │                                                                             │
 * │  <mad-sheet> — Atributos da planilha                                        │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │  model="StockCount"        Model Eloquent alvo dos inserts (obrigatório)   │
 * │  database="business"        Conexão (default MAIN_DATABASE)                 │
 * │  rows-min="10"             Linhas em branco iniciais (default 8)           │
 * │  max-rows="2000"           Guard de payload do batch                       │
 * │  totals / totals="false"   Linha de totais (default true)                  │
 * │                                                                             │
 * │  <mad-sheet-col> — Uma coluna (repetível; ordem = ordem visual)            │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │  field="produto_id"        Coluna do model (obrigatório)                   │
 * │  label="Produto"           Header (default: field)                         │
 * │  type="text|number|money|date|combo"  (default text; dbcombo = combo)      │
 * │  required / readonly       Flags                                           │
 * │  width="140px"             Largura fixa da coluna                          │
 * │  total="sum|count"         Totalizador da coluna                           │
 * │  default="0"               Valor inicial das células novas                 │
 * │  placeholder="..."         Placeholder do input                            │
 * │  compute="{qtd} * {valor}" Coluna calculada (readonly, nunca persiste)     │
 * │                                                                             │
 * │  combo (superfície do mad-dbcombo-field):                                   │
 * │  options="1:Ativo,2:Inativo"  Opções estáticas (vencem model)              │
 * │  model="Produto"           Model das opções (source = alias legado)        │
 * │  database="business"        Conexão ('' = herda a do sheet)                │
 * │  key="id"                  Coluna de valor                                 │
 * │  display="{nome} - {uf}"   Coluna de label OU mask (source-label = alias)  │
 * │  order-by="nome" order="asc"                                                │
 * │  where="ativo=1|tipo=P"    Filtro DSL (ou :where="$filter_x" Closure)      │
 * │  search / min-length="3"   Busca server-side (MadDbSearchService)          │
 * │  depends-on="estado_id"    Cascade por linha (coluna irmã; força search)   │
 * │  depends-column="estado_id" Coluna do WHERE do cascade                     │
 * │  no-results-create-* / no-results-quick-register-* / no-results-message    │
 * │  filho <mad-quick-form action="..."><mad-input-field .../></mad-quick-form>│
 * │                                                                             │
 * │  number/money: decimals min max step prefix suffix decimal-sep             │
 * │                thousand-sep fill-direction allow-negative                  │
 * │  date:  min max display-mask="dd/mm/yyyy" database-mask="yyyy-mm-dd"       │
 * │  text:  mask="cpf|cnpj|..." strip-mask maxlength force-case                │
 * │                                                                             │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo \Mad\Sheet\MadSheetCompiler::renderInline([...], $that ?? null); ?>
 */
class MadSheetCompiler
{
    /** Pattern de atributos que aceita `>` dentro de aspas (espelha MadKanbanCompiler). */
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-sheet') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio. Lookahead evita casar <mad-sheet-col/>.
        $value = preg_replace_callback(
            '#<mad-sheet(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-sheet' . $m[1] . '></mad-sheet>',
            $value
        );

        $value = preg_replace_callback(
            '#<mad-sheet(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-sheet\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: <mad-sheet-col> órfão fora de <mad-sheet> some em
        // vez de virar componente <x-sheet-col> inexistente no BladeOne.
        $tag = preg_quote('mad-sheet-col', '#');
        $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '/>#s', '', $value);
        $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tag . '\s*>#s', '', $value);

        return $value;
    }

    protected static function compileBlock(array $match): string
    {
        $attrs = self::parseAttrs($match[1] ?? '');
        $body  = $match[2] ?? '';

        $configParts = [];
        if (isset($attrs['model']))    $configParts[] = "'model' => "    . self::emit($attrs['model']);
        if (isset($attrs['database'])) $configParts[] = "'database' => " . self::emit($attrs['database']);
        if (isset($attrs['rows-min'])) {
            $a = $attrs['rows-min'];
            $configParts[] = "'rowsMin' => " . ($a['type'] === 'php' ? $a['value'] : (int) $a['value']);
        }
        if (isset($attrs['max-rows'])) {
            $a = $attrs['max-rows'];
            $configParts[] = "'maxRows' => " . ($a['type'] === 'php' ? $a['value'] : (int) $a['value']);
        }
        if (isset($attrs['totals'])) {
            $configParts[] = "'showTotals' => " . self::emitBool($attrs['totals']);
        } elseif (isset($attrs['no-totals'])) {
            $configParts[] = "'showTotals' => false";
        }

        $cols = self::extractColumns($body);
        if (!empty($cols)) {
            $configParts[] = "'columns' => " . self::arrayLiteral($cols);
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Sheet\\MadSheetCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /**
     * Entry point chamado em runtime pelo PHP gerado. Roteia para o host
     * MadSheet (se for subclass) ou cria MadSheetStandalone temporário.
     */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadSheet) {
            return $host->_renderInlineSheet($config);
        }

        return (new MadSheetStandalone())->_renderInlineSheet($config);
    }

    /** Attrs booleanos do <mad-sheet-col> (bare = true; string passa por truthy). */
    private const COL_BOOL_ATTRS = ['required', 'readonly', 'search', 'allowNegative', 'stripMask'];

    /**
     * Coleta os <mad-sheet-col> na ordem em que aparecem. Aceita self-closing
     * e block form; um filho <mad-quick-form> vira no-results-quick-register
     * (mesma gramática do MadQuickFormCompiler — que roda DEPOIS deste
     * compiler e nunca veria o corpo do sheet).
     */
    protected static function extractColumns(string $body): array
    {
        $items = [];
        $A     = self::ATTRS;
        $regex = '#<mad-sheet-col(?=[\s/>])(' . $A . ')(?:/>|>([\s\S]*?)</mad-sheet-col\s*>)#s';

        if (preg_match_all($regex, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs = self::parseAttrs($m[1]);
                $entry = [];
                foreach ($attrs as $key => $attr) {
                    $camel = self::kebabToCamel($key);
                    if (in_array($camel, self::COL_BOOL_ATTRS, true)) {
                        $entry[$camel] = self::emitBool($attr);
                    } else {
                        $entry[$camel] = self::emit($attr);
                    }
                }

                $colBody = $m[2] ?? '';
                if ($colBody !== '' && strpos($colBody, '<mad-quick-form') !== false) {
                    $entry = self::injectQuickForm($entry, $colBody);
                }

                if (!empty($entry)) {
                    $items[] = $entry;
                }
            }
        }

        return $items;
    }

    /**
     * Captura <mad-quick-form ...>filhos</mad-quick-form> dentro da coluna e
     * injeta os no-results-quick-register-* + quick-fields no entry. Reusa o
     * parser de campos do MadQuickFormCompiler (mesma gramática dos combos).
     */
    protected static function injectQuickForm(array $entry, string $colBody): array
    {
        $A = self::ATTRS;
        if (!preg_match(
            '#<mad-quick-form(?=[\s/>])(' . $A . ')(?:/>|>([\s\S]*?)</mad-quick-form\s*>)#s',
            $colBody,
            $qf
        )) {
            return $entry;
        }

        $qfAttrs = self::parseAttrs($qf[1] ?? '');
        $map = [
            'action'  => 'noResultsQuickRegisterAction',
            'label'   => 'noResultsQuickRegisterLabel',
            'icon'    => 'noResultsQuickRegisterIcon',
            'class'   => 'noResultsQuickRegisterClass',
            'message' => 'noResultsMessage',
        ];
        foreach ($map as $src => $dst) {
            if (isset($qfAttrs[$src]) && !isset($entry[$dst])) {
                $entry[$dst] = self::emit($qfAttrs[$src]);
            }
        }

        $fields = \Mad\Form\MadQuickFormCompiler::parseFields($qf[2] ?? '');
        $literal = \Mad\Form\MadQuickFormCompiler::fieldsToPhpLiteral($fields);
        if ($literal !== '') {
            $entry['noResultsQuickFields'] = $literal;
        }

        return $entry;
    }

    // ── Helpers (espelham MadKanbanCompiler) ──────────────────────────────

    protected static function arrayLiteral(array $list): string
    {
        $rows = [];
        foreach ($list as $entry) {
            $kvs = [];
            foreach ($entry as $k => $expr) {
                $kvs[] = "'" . addslashes($k) . "' => {$expr}";
            }
            $rows[] = '[' . implode(', ', $kvs) . ']';
        }
        return '[' . implode(', ', $rows) . ']';
    }

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

    protected static function kebabToCamel(string $key): string
    {
        if (strpos($key, '-') === false) return $key;
        return lcfirst(str_replace('-', '', ucwords($key, '-')));
    }
}
