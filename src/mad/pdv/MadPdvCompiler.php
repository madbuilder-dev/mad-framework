<?php
namespace Mad\Pdv;

/**
 * MadPdvCompiler — compila <mad-pdv>...</mad-pdv> para PHP puro
 * em compile-time.
 *
 * Chamado por MadBladeCompiler::compileString() (passo 1.11) antes do BladeOne
 * processar os componentes <x-*>. As tags <mad-pdv*> nunca chegam ao sistema
 * de componentes Blade.
 *
 * Contrato completo (props, payloads, erros): spec `docs/specs/mad-pdv-v1.md`
 * (Rev. 2) no monorepo da plataforma.
 *
 * ┌── Sintaxe suportada ───────────────────────────────────────────────────────┐
 * │                                                                             │
 * │  <mad-pdv> — frente de caixa (POS)                                          │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │  Produto (busca/bip):                                                       │
 * │    model="Produto" (obrig.)  database=  name-field= (obrig.)                │
 * │    price-field= (obrig.)  code-field=  barcode-field= (≥1 dos dois)         │
 * │    stock-field=  unit-field=  image-field=  active-field= active-value="1"  │
 * │    order-by=  where="estoque > 0" | :where="$closure"                       │
 * │    search-min-length="2"  search-limit="10"                                 │
 * │                                                                             │
 * │  Persistência (defaults = esquema canônico venda/venda_item/venda_pagamento;│
 * │  só os *-model são obrigatórios):                                           │
 * │    sale-model=  sale-total-field=  sale-subtotal-field=                     │
 * │    sale-discount-field=  sale-datetime-field=  sale-customer-field=         │
 * │    sale-operator-field=  sale-status-field=  sale-status-done=              │
 * │    sale-document-field=  sale-change-field=  sale-uuid-field=               │
 * │    item-model=  item-sale-field=  item-product-field=  item-qty-field=      │
 * │    item-price-field=  item-discount-field=  item-total-field=               │
 * │    payment-model=  payment-sale-field=  payment-method-field=               │
 * │    payment-amount-field=  payment-tendered-field=                           │
 * │                                                                             │
 * │  Cliente (opcional):                                                        │
 * │    customer-model=  customer-key=  customer-display="{nome}"                │
 * │    customer-order-by=  customer-where= | :customer-where=                   │
 * │    customer-required  customer-default-id=                                  │
 * │                                                                             │
 * │  Comportamento:                                                             │
 * │    discount-mode="none|item|total|both"  max-discount-percent="10"          │
 * │    allow-price-override  allow-fraction  stock-mode="off|warn|block"        │
 * │    stock-decrement  ask-document  hold-sales  hold-limit="10"               │
 * │    print-mode="off|browser"  receipt-width="58|80"  receipt-header=         │
 * │    receipt-footer=  auto-print  on-finalized="metodoDoHost"                 │
 * │                                                                             │
 * │  Visual: title=  fullscreen-toggle  currency-symbol="R$"  locale=           │
 * │          density="normal|compact"  show-images                              │
 * │                                                                             │
 * │  <mad-pdv-payment> — forma de pagamento (repetível; ordem = botões)         │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │    method="dinheiro" (obrig.)  label=  icon=  allow-change  hotkey="1"      │
 * │    (nenhuma declarada → runtime usa dinheiro/débito/crédito/pix)            │
 * │                                                                             │
 * │  <mad-pdv-action> — ação extra na toolbar (repetível)                       │
 * │  ─────────────────────────────────────────────────────────────────────────  │
 * │    label= (obrig.)  method="metodoWireDoHost" (obrig.)  icon=  hotkey=      │
 * │    confirm="mensagem de confirmação"                                        │
 * │                                                                             │
 * │  Reservadas (v2 — presença gera warning e é ignorada, render segue):        │
 * │    <mad-pdv-column>  <mad-pdv-receipt>  <mad-pdv-hotkey>                    │
 * │                                                                             │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Output: <?php echo \Mad\Pdv\MadPdvCompiler::renderInline([...], $that ?? null); ?>
 *
 * Atributos kebab-case são convertidos para camelCase nas chaves do array.
 *
 * `active-value` no padrão booleano ("1", "true" ou "T") lê a coluna de forma
 * tolerante — 1, "1", true, "true", "T", "S", "Y", "sim" e "yes" contam como
 * ativo; um marcador próprio (ex.: active-value="A") continua sendo comparado
 * exatamente.
 */
class MadPdvCompiler
{
    /** Pattern de atributos que aceita `>` dentro de aspas (espelha MadSheetCompiler). */
    private const ATTRS = '(?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*';

    /** Attrs booleanos da tag raiz (bare = true; string passa por truthy). */
    private const ROOT_BOOL_ATTRS = [
        'allow-price-override', 'allow-fraction', 'stock-decrement',
        'ask-document', 'hold-sales', 'auto-print', 'fullscreen-toggle',
        'show-images', 'customer-required', 'product-picker',
    ];

    /** Attrs inteiros da tag raiz (cast no compile; :attr passa cru). */
    private const ROOT_INT_ATTRS = [
        'search-min-length', 'search-limit', 'hold-limit', 'receipt-width',
        'customer-default-id', 'product-picker-page-size',
    ];

    /** Attrs numéricos com casas (float). */
    private const ROOT_FLOAT_ATTRS = ['max-discount-percent'];

    /** Attrs booleanos do <mad-pdv-payment> (já em camelCase). */
    private const PAYMENT_BOOL_ATTRS = [
        'allowChange', 'geraTitulo', 'entraNoCaixa', 'exigeCliente',
    ];

    /** Attrs inteiros do <mad-pdv-payment>. */
    private const PAYMENT_INT_ATTRS = [
        'maxParcelas', 'prazoPrimeiraDias', 'intervaloDias',
    ];

    /** Attrs booleanos do <mad-pdv-column> (já em camelCase). */
    private const COLUMN_BOOL_ATTRS = ['required', 'receipt', 'mergeIgnore'];

    /** Attrs inteiros do <mad-pdv-column>. */
    private const COLUMN_INT_ATTRS = ['maxlength'];

    /** Sub-tags reservadas de v2: warning + strip, nunca quebra o render. */
    private const RESERVED_SUBTAGS = ['receipt', 'hotkey'];

    /** Todas as sub-tags conhecidas (p/ limpeza de órfãs). */
    private const ALL_SUBTAGS = ['payment', 'action', 'column', 'receipt', 'hotkey'];

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-pdv') === false) {
            return $value;
        }

        $A = self::ATTRS;

        // Self-closing → block form vazio. Lookahead evita casar <mad-pdv-payment/>.
        $value = preg_replace_callback(
            '#<mad-pdv(?=[\s/>])(' . $A . ')\s*/>#s',
            fn(array $m): string => '<mad-pdv' . $m[1] . '></mad-pdv>',
            $value
        );

        $value = preg_replace_callback(
            '#<mad-pdv(?=[\s/>])(' . $A . ')>([\s\S]*?)</mad-pdv\s*>#s',
            [self::class, 'compileBlock'],
            $value
        );

        // Defense in depth: sub-tags órfãs (fora de <mad-pdv>) somem em vez de
        // virar componente <x-pdv-*> inexistente no BladeOne.
        foreach (self::ALL_SUBTAGS as $sub) {
            $tag = preg_quote('mad-pdv-' . $sub, '#');
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '/>#s', '', $value);
            $value = preg_replace('#<' . $tag . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tag . '\s*>#s', '', $value);
        }

        return $value;
    }

    /**
     * ⚠️ Sub-tag do PDV NÃO respeita `@if`.
     *
     * Este compilador roda ANTES do BladeOne, sobre o texto cru: ele extrai
     * `<mad-pdv-payment>` / `<mad-pdv-column>` por regex e as transforma em
     * config. Um `@if` em volta continua no texto e some no render, mas a
     * sub-tag JÁ foi extraída — ela entra na config de qualquer jeito.
     *
     * O sintoma é confuso: a forma de pagamento não aparece na tela mas
     * dispara a validação de config dela (foi assim que isto foi descoberto).
     * Condicione pelos ATRIBUTOS (`:gera-titulo="$cond"`), não pela presença
     * da tag.
     */
    protected static function compileBlock(array $match): string
    {
        $attrs = self::parseAttrs($match[1] ?? '');
        $body  = $match[2] ?? '';
        $A     = self::ATTRS;

        // Sub-tags reservadas (v2): sai do body com warning; o caixa renderiza.
        foreach (self::RESERVED_SUBTAGS as $sub) {
            $tag = 'mad-pdv-' . $sub;
            if (strpos($body, '<' . $tag) === false) {
                continue;
            }
            error_log("[MadPdvCompiler] <{$tag}> é reservada (v2) e foi ignorada neste render.");
            $tagQ = preg_quote($tag, '#');
            $body = preg_replace('#<' . $tagQ . '(?=[\s/>])' . $A . '/>#s', '', $body);
            $body = preg_replace('#<' . $tagQ . '(?=[\s/>])' . $A . '>[\s\S]*?</' . $tagQ . '\s*>#s', '', $body);
        }

        $configParts = self::buildRootConfig($attrs);

        $payments = self::extractAll($body, 'mad-pdv-payment');
        if (!empty($payments)) {
            $configParts[] = "'payments' => " . self::subTagList($payments, self::PAYMENT_BOOL_ATTRS, self::PAYMENT_INT_ATTRS);
        }

        $actions = self::extractAll($body, 'mad-pdv-action');
        if (!empty($actions)) {
            $configParts[] = "'actions' => " . self::subTagList($actions, []);
        }

        // Colunas do carrinho (Rev. 5). Chave AUSENTE quando ninguém declara —
        // é assim que o runtime sabe usar o conjunto canônico, mesma convenção
        // de payments/actions.
        $columns = self::extractAll($body, 'mad-pdv-column');
        if (!empty($columns)) {
            $configParts[] = "'columns' => "
                . self::subTagList($columns, self::COLUMN_BOOL_ATTRS, self::COLUMN_INT_ATTRS);
        }

        $configStr = empty($configParts)
            ? '[]'
            : "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Pdv\\MadPdvCompiler::renderInline({$configStr}, \$that ?? null); ?>";
    }

    /**
     * Entry point chamado em runtime pelo PHP gerado. Roteia para o host
     * MadPdvComponent (se for subclass) ou cria MadPdvStandalone temporário
     * com o host externo injetado (resolução de callbacks/actions por reflexão).
     */
    public static function renderInline(array $config, ?object $host = null): string
    {
        if ($host instanceof MadPdvComponent) {
            return $host->_renderInlinePdv($config);
        }

        $standalone = new MadPdvStandalone();
        if ($host !== null) {
            $standalone->_setExternalHost($host);
        }

        return $standalone->_renderInlinePdv($config);
    }

    /**
     * Config raiz: mapeamento GENÉRICO kebab→camel (sem whitelist — o
     * componente valida as chaves que conhece), com cast para os sets
     * bool/int/float declarados acima. `:attr="expr"` passa cru.
     */
    protected static function buildRootConfig(array $attrs): array
    {
        $c = [];
        foreach ($attrs as $key => $attr) {
            $camel = self::kebabToCamel($key);

            if (in_array($key, self::ROOT_BOOL_ATTRS, true)) {
                $c[] = "'{$camel}' => " . self::emitBool($attr);
            } elseif (in_array($key, self::ROOT_INT_ATTRS, true)) {
                $c[] = "'{$camel}' => " . ($attr['type'] === 'php'
                    ? $attr['value']
                    : (string) (int) $attr['value']);
            } elseif (in_array($key, self::ROOT_FLOAT_ATTRS, true)) {
                $c[] = "'{$camel}' => " . ($attr['type'] === 'php'
                    ? $attr['value']
                    : (string) (float) $attr['value']);
            } else {
                $c[] = "'{$camel}' => " . self::emit($attr);
            }
        }

        return $c;
    }

    /** Captura TODAS as ocorrências de uma sub-tag, ordem preservada. */
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

    /** Lista de arrays assoc a partir de attrs parseados, com set de bools. */
    protected static function subTagList(array $items, array $boolCamels, array $intCamels = []): string
    {
        $rows = [];
        foreach ($items as $attrs) {
            $kvs = [];
            foreach ($attrs as $key => $attr) {
                $camel = self::kebabToCamel($key);
                $expr  = match (true) {
                    in_array($camel, $boolCamels, true) => self::emitBool($attr),
                    in_array($camel, $intCamels, true)  => self::emitInt($attr),
                    default                             => self::emit($attr),
                };
                $kvs[] = "'{$camel}' => {$expr}";
            }
            if ($kvs !== []) {
                $rows[] = '[' . implode(', ', $kvs) . ']';
            }
        }
        return '[' . implode(', ', $rows) . ']';
    }

    // ── Helpers (espelham MadGanttCompiler/MadSheetCompiler) ──────────────

    /**
     * Analisa string de atributos HTML.
     * Retorna mapa name → ['type' => 'string'|'php'|'bool', 'value' => ...].
     * Aceita valor sem aspas (receipt-width=58).
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

    /** Emite expressão PHP do atributo. */
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

    /** Inteiro de sub-tag: `:attr` continua PHP cru; string vira (int). */
    private static function emitInt(array $attr): string
    {
        return $attr['type'] === 'php'
            ? (string) $attr['value']
            : (string) (int) $attr['value'];
    }

    private static function emitBool(array $attr): string
    {
        return match ($attr['type']) {
            'bool'  => 'true',
            'php'   => (string) $attr['value'],
            default => self::truthyString((string) $attr['value']) ? 'true' : 'false',
        };
    }

    /** kebab-case → camelCase. */
    protected static function kebabToCamel(string $key): string
    {
        if (strpos($key, '-') === false) return $key;
        return lcfirst(str_replace('-', '', ucwords($key, '-')));
    }
}
