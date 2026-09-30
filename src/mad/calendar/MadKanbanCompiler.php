<?php
namespace Mad\Calendar;

/**
 * MadKanbanCompiler — compila <mad-kanban>...</mad-kanban> para PHP puro
 * em compile-time.
 *
 * Chamado por MadBladeOne::compileString() antes do BladeOne processar os
 * componentes <x-*>. As tags <mad-kanban*> nunca chegam ao sistema de
 * componentes Blade.
 *
 * ┌── Sintaxe suportada ────────────────────────────────────────────────────────┐
 * │                                                                              │
 * │  <mad-kanban> — Atributos do board (todos opcionais — fallback no PHP)      │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  model="PedidoVenda"                Classe do model Eloquent dos cards                 │
 * │  database="business"                 Conexão                                  │
 * │  stage-model="EstadoPedidoVenda"    Classe do model Eloquent das colunas              │
 * │  stage-field="estado_pedido_venda_id"  FK no card model                     │
 * │  card-order-field="ordem"           Coluna de ordenação (vazio = sem reorder)│
 * │  value-field="valor_total"          Campo p/ somar totais por coluna       │
 * │  cards-per-load="20"                Page size do scroll infinito            │
 * │  cards-draggable                    Habilita drag-drop (default true)      │
 * │  click-target="ClasseForm::onShow({id})"  Form que abre ao clicar no card  │
 * │  card-view="components.meu-card"    Override total do template do card     │
 * │                                                                              │
 * │  <mad-kanban-card> — Configuração do card default                           │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  title="cliente.nome"               Path para o título (dot notation)       │
 * │                                                                              │
 * │  <mad-kanban-badge> — Filhos do card                                        │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  type="id"          → "#42"                                                 │
 * │  type="state"       → estado colorido auto (via stage relation)             │
 * │  type="text" path="..." color-path="..." color="#xxx" value="literal"       │
 * │                                                                              │
 * │  <mad-kanban-meta> — Linha de metadata no corpo                             │
 * │  icon="user" path="cliente.nome" sub-path="cliente.fone" muted              │
 * │                                                                              │
 * │  <mad-kanban-footer> — Item do rodapé                                       │
 * │  type="money|date|text" path="valor_total" icon="calendar"                  │
 * │  prefix="R$" format="d/m/Y" decimals="2"                                    │
 * │                                                                              │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo $that->_renderInlineKanban([...config...]); ?>
 */
class MadKanbanCompiler
{
    /**
     * Pattern para casar atributos HTML permitindo `>` dentro de aspas.
     * Necessário para suportar a sintaxe de mask com navegação:
     *   title="{cliente_id->nome} - {vendedor_id->nome}"
     *   path="{estado->cor}"
     *
     * Aceita: name="val", name='val', name=val (sem aspas), name (boolean),
     * com `>` dentro de aspas duplas/simples.
     */
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    /**
     * Compila todas as ocorrências de <mad-kanban>...</mad-kanban> no template.
     */
    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-kanban') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio (uniformiza para um caminho único).
        // Lookahead garante que NÃO casa <mad-kanban-filters/>, <mad-kanban-card/>, etc.
        $value = preg_replace_callback(
            '#<mad-kanban(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-kanban' . $m[1] . '></mad-kanban>',
            $value
        );

        // Block form: <mad-kanban ...>...</mad-kanban>
        // O lookahead `(?=[\s/>])` evita casar tags-filho `<mad-kanban-card>` etc.
        $value = preg_replace_callback(
            '#<mad-kanban(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-kanban\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: remove tags reservadas órfãs (caso usuario tenha
        // colocado <mad-kanban-badge>, <mad-kanban-meta>, etc fora de
        // <mad-kanban-card> — sem isso, BladeOne tentaria resolver como
        // componente <x-kanban-badge> e quebraria com "Template not found").
        $orphans = ['badge', 'meta', 'meta-group', 'footer', 'action', 'card', 'stage-action', 'toolbar'];
        foreach ($orphans as $sub) {
            $tag = preg_quote('mad-kanban-' . $sub, '#');
            // Self-closing
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '/>#s', '', $value);
            // Pair (open+close)
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tag . '\s*>#s', '', $value);
        }

        return $value;
    }

    protected static function compileBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';
        $A       = self::ATTRS;

        $attrs        = self::parseAttrs($attrStr);
        $configParts  = self::buildBoardConfig($attrs);

        // Toolbar (body arbitrário renderizado ACIMA do board, antes das colunas).
        $toolbar = self::extractToolbar($body);
        if ($toolbar !== null) {
            $configParts[] = "'toolbar' => " . self::qs(base64_encode($toolbar));
            // Remove a tag <mad-kanban-toolbar>...</mad-kanban-toolbar> do body
            // pra não interferir na extração de card/stage-action.
            $body = preg_replace(
                '#<mad-kanban-toolbar(?=[\s/>])' . $A . '>[\s\S]*?</mad-kanban-toolbar\s*>#s',
                '', $body
            );
        }

        // Stage actions (renderiza no header de cada coluna).
        $stageActions = self::extractChildren($body, 'mad-kanban-stage-action', []);
        if (!empty($stageActions)) {
            $configParts[] = "'stageActions' => " . self::arrayLiteral($stageActions);
        }

        $cardConfig = self::extractCard($body);
        if ($cardConfig !== null) {
            $configParts[] = "'card' => {$cardConfig}";
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Calendar\\MadKanbanCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /**
     * Entry point chamado em runtime pelo PHP gerado. Roteia para o host
     * MadKanban (se for subclass) ou cria MadKanbanStandalone temporário —
     * evita o fatal "Call to a member function on null" quando <mad-kanban>
     * aparece numa view cujo host não é um MadKanban. Espelha
     * MadGanttCompiler::renderInline.
     */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadKanban) {
            return $host->_renderInlineKanban($config);
        }

        return (new MadKanbanStandalone())->_renderInlineKanban($config);
    }

    /**
     * Extrai conteúdo de <mad-kanban-toolbar>...</mad-kanban-toolbar> como
     * Blade arbitrário (rendered above the board).
     */
    protected static function extractToolbar(string $body): ?string
    {
        if (!preg_match('#<mad-kanban-toolbar(?=[\s/>])' . self::ATTRS . '>([\s\S]*?)</mad-kanban-toolbar\s*>#s', $body, $m)) {
            return null;
        }
        return trim($m[1]);
    }

    /**
     * Extrai <mad-kanban-card>...</mad-kanban-card> (ou self-closing) do body
     * e retorna a representação PHP do array de config do card. Null se ausente.
     *
     * Suporta dois modos no body do <mad-kanban-card>:
     *
     *  1. Declarativo: apenas tags-filho do trio
     *     <mad-kanban-badge|meta|footer .../> + whitespace
     *     → usa template default (kanban-card-default.blade.php)
     *
     *  2. Custom: qualquer HTML/Blade/<mad-*> arbitrário no body
     *     → encoda o body inteiro como template, renderizado per-item via
     *       MadBlade::renderString com $item, $id, $kanban, $attrs em escopo.
     *       Tags do trio que sobrarem dentro do custom são removidas (suas
     *       configs ainda são extraídas mas ignoradas pelo render custom).
     */
    protected static function extractCard(string $body): ?string
    {
        $A = self::ATTRS;

        // Auto-close
        $body = preg_replace_callback(
            '#<mad-kanban-card(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-kanban-card' . $m[1] . '></mad-kanban-card>',
            $body
        );

        if (!preg_match('#<mad-kanban-card(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-kanban-card\s*>#s', $body, $m)) {
            return null;
        }

        $cardAttrs = self::parseAttrs($m[1]);
        $cardBody  = $m[2];

        $parts = [];

        if (isset($cardAttrs['title'])) {
            $parts[] = "'title' => " . self::emit($cardAttrs['title']);
        }
        if (isset($cardAttrs['actions-mode'])) {
            $parts[] = "'actionsMode' => " . self::emit($cardAttrs['actions-mode']);
        }
        if (isset($cardAttrs['no-id-badge'])) {
            $parts[] = "'noIdBadge' => true";
        }
        if (isset($cardAttrs['no-state-badge'])) {
            $parts[] = "'noStateBadge' => true";
        }

        $badges = self::extractChildren($cardBody, 'mad-kanban-badge', [
            'type', 'path', 'color', 'color-path', 'colorPath', 'value',
        ]);
        if (!empty($badges)) {
            $parts[] = "'badges' => " . self::arrayLiteral($badges);
        }

        // <mad-kanban-meta-group> blocos — extraidos ANTES dos metas top-level
        // (senao os filhos do group seriam consumidos pelo extractChildren).
        $groups = [];
        $cardBody = preg_replace_callback(
            '#<mad-kanban-meta-group(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-kanban-meta-group\s*>#s',
            function (array $gm) use (&$groups): string {
                $gAttrs = self::parseAttrs($gm[1]);
                $gBody  = $gm[2];

                $items = self::extractChildren($gBody, 'mad-kanban-meta', []);
                if (empty($items)) return '';

                $cfgParts = [];
                foreach (['direction', 'justify', 'align', 'gap'] as $k) {
                    if (isset($gAttrs[$k])) {
                        $cfgParts[] = "'" . self::kebabToCamel($k) . "' => " . self::emit($gAttrs[$k]);
                    }
                }
                $cfgParts[] = "'metas' => " . self::arrayLiteral($items);

                $groups[] = '[' . implode(', ', $cfgParts) . ']';
                return ''; // remove do body
            },
            $cardBody
        );
        if (!empty($groups)) {
            $parts[] = "'metaGroups' => [" . implode(', ', $groups) . "]";
        }

        $meta = self::extractChildren($cardBody, 'mad-kanban-meta', [
            'icon', 'path', 'sub-path', 'subPath', 'muted', 'inline', 'position', 'type',
            'prefix', 'decimals', 'format',
        ]);
        if (!empty($meta)) {
            $parts[] = "'meta' => " . self::arrayLiteral($meta);
        }

        $footer = self::extractChildren($cardBody, 'mad-kanban-footer', [
            'type', 'path', 'icon', 'prefix', 'format', 'decimals', 'class',
        ]);
        if (!empty($footer)) {
            $parts[] = "'footer' => " . self::arrayLiteral($footer);
        }

        $actions = self::extractChildren($cardBody, 'mad-kanban-action', [
            'method', 'target', 'icon', 'label', 'variant',
            'confirm', 'drawer', 'inline',
            'when-field', 'whenField', 'when-value', 'whenValue',
            'when-in', 'whenIn', 'when-nin', 'whenNin',
            'display-condition', 'displayCondition',
        ]);
        if (!empty($actions)) {
            $parts[] = "'actions' => " . self::arrayLiteral($actions);
        }

        // Detecta modo custom: residual após strip do trio + actions tem conteúdo?
        // (meta-group já foi removido acima na extração)
        // Strip aceita ambas formas: <tag .../> E <tag ...></tag>.
        $stripPatterns = [
            '#<mad-kanban-(badge|meta|footer|action)(?=[\s/>])' . $A . '/>#s',
            '#<mad-kanban-(badge|meta|footer|action)(?=[\s/>])' . $A . '></mad-kanban-\1\s*>#s',
        ];
        $cleaned = $cardBody;
        foreach ($stripPatterns as $p) $cleaned = preg_replace($p, '', $cleaned);
        $residual = trim($cleaned);
        if ($residual !== '') {
            // Encoda o body custom (sem tags reservadas — evita falhas na 2a passada de Blade).
            // A renderização será feita per-item via MadBlade::renderString.
            $parts[]  = "'template' => " . self::qs(base64_encode($cleaned));
        }

        return empty($parts) ? '[]' : '[' . implode(', ', $parts) . ']';
    }

    /** Emite string literal PHP escapada. */
    protected static function qs(string $s): string
    {
        return "'" . str_replace("'", "\\'", $s) . "'";
    }

    /**
     * Coleta todas as ocorrências de uma tag (self-closing OU open+close) e
     * produz uma lista de configs ['key' => valor]. Chaves kebab-case são
     * convertidas para camelCase.
     *
     * Aceita as duas formas:
     *   <mad-kanban-badge type="id" />
     *   <mad-kanban-badge type="id"></mad-kanban-badge>
     *
     * @param string[] $allowedKeys (apenas documentação — todos atributos sao aceitos)
     * @return array<int,array<string,string>>  cada item: ['key' => php_expr]
     */
    protected static function extractChildren(string $body, string $tag, array $allowedKeys): array
    {
        $items = [];
        $tagQ  = preg_quote($tag, '#');
        $A     = self::ATTRS;
        // Casa: <tag attrs />  OU  <tag attrs></tag>  — aceita `>` dentro de aspas
        $regex = '#<' . $tagQ . '(?=[\s/>])(' . $A . ')(?:/>|></' . $tagQ . '\s*>|>\s*</' . $tagQ . '\s*>)#s';

        if (preg_match_all($regex, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs   = self::parseAttrs($m[1]);
                $entry   = [];
                foreach ($attrs as $key => $attr) {
                    $camel = self::kebabToCamel($key);
                    // Permite tudo, mas normaliza colorPath/subPath etc.
                    $entry[$camel] = self::emit($attr);
                }
                if (!empty($entry)) {
                    $items[] = $entry;
                }
            }
        }

        return $items;
    }

    /**
     * Constrói uma lista [['k' => expr, ...], ...] como literal PHP.
     *
     * @param array<int,array<string,string>> $list
     */
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

    /**
     * Configs do board (atributos da tag <mad-kanban>).
     * Todos opcionais — controller PHP tem fallback.
     */
    protected static function buildBoardConfig(array $a): array
    {
        $c = [];

        if (isset($a['model']))                $c[] = "'model' => "               . self::emit($a['model']);
        if (isset($a['database']))             $c[] = "'database' => "            . self::emit($a['database']);
        if (isset($a['stage-model']))          $c[] = "'stageModel' => "          . self::emit($a['stage-model']);
        if (isset($a['stage-field']))          $c[] = "'stageField' => "          . self::emit($a['stage-field']);
        if (isset($a['stage-title-field']))    $c[] = "'stageTitleField' => "     . self::emit($a['stage-title-field']);
        if (isset($a['stage-color-field']))    $c[] = "'stageColorField' => "     . self::emit($a['stage-color-field']);
        if (isset($a['stage-order-field']))    $c[] = "'stageOrderField' => "     . self::emit($a['stage-order-field']);
        if (isset($a['stage-order-direction'])) $c[] = "'stageOrderDirection' => " . self::emit($a['stage-order-direction']);
        if (isset($a['stages-reorderable']))   $c[] = "'stagesReorderable' => " . self::emitBool($a['stages-reorderable']);
        if (isset($a['card-order-field']))     $c[] = "'cardOrderField' => "      . self::emit($a['card-order-field']);
        if (isset($a['value-field']))          $c[] = "'valueField' => "          . self::emit($a['value-field']);
        if (isset($a['value-format']))         $c[] = "'valueFormat' => "         . self::emit($a['value-format']);
        if (isset($a['card-view']))            $c[] = "'cardView' => "            . self::emit($a['card-view']);
        if (isset($a['click-target']))         $c[] = "'clickTarget' => "         . self::emit($a['click-target']);

        if (isset($a['cards-per-load'])) {
            $attr = $a['cards-per-load'];
            $val  = $attr['type'] === 'php' ? $attr['value'] : (int) $attr['value'];
            $c[]  = "'cardsPerLoad' => {$val}";
        }

        // cards-draggable boolean (default true). Aceita "cards-draggable" e
        // "no-cards-draggable" como atalho explícito de desativar.
        if (isset($a['cards-draggable'])) {
            $c[] = "'cardsDraggable' => " . self::emitBool($a['cards-draggable']);
        } elseif (isset($a['no-cards-draggable'])) {
            $c[] = "'cardsDraggable' => false";
        }

        // top-scroll boolean — duplica scrollbar horizontal no topo do board
        if (isset($a['top-scroll'])) {
            $c[] = "'topScroll' => " . self::emitBool($a['top-scroll']);
        }

        return $c;
    }

    private static function truthyString(string $v): bool
    {
        $v = strtolower(trim($v));
        return !in_array($v, ['', '0', 'false', 'no', 'off'], true);
    }

    /**
     * Emite expressão PHP de atributo booleano: attr presente sem valor → true;
     * attr="false|0|no|off" → false; :attr="expr" → expressão crua.
     * (Antes stages-reorderable/top-scroll viravam true por mera presença —
     * top-scroll="false" ATIVAVA o recurso.)
     */
    private static function emitBool(array $attr): string
    {
        return match ($attr['type']) {
            'bool'  => 'true',
            'php'   => (string) $attr['value'],
            default => self::truthyString((string) $attr['value']) ? 'true' : 'false',
        };
    }

    // ── Helpers (espelham MadGridCompiler) ────────────────────────────────────

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

    /**
     * Emite expressão PHP do atributo.
     */
    protected static function emit(array $attr): string
    {
        return match ($attr['type']) {
            'php'  => html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5),
            'bool' => 'true',
            default => "'" . str_replace("'", "\\'", html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5)) . "'",
        };
    }

    /** Converte kebab-case → camelCase. Ex: sub-path → subPath. */
    protected static function kebabToCamel(string $key): string
    {
        if (strpos($key, '-') === false) return $key;
        return lcfirst(str_replace('-', '', ucwords($key, '-')));
    }
}
