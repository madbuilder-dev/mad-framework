<?php
namespace Mad\Form;

/**
 * MadQuickFormCompiler — pre-compilador que extrai blocos `<mad-quick-form>...</mad-quick-form>`
 * declarados como filhos de componentes de selecao MAD e converte em props
 * `:no-results-quick-fields="[...]"` + `no-results-quick-register-*` no
 * componente pai.
 *
 * Roda no `MadBlade::compileString()` ANTES da etapa de mapping `<mad-X>` → `<x-X>`.
 *
 * Sintaxe declarativa suportada:
 *
 *   <mad-dbunique-search-field name="cidade_id" model="Cidade" display="nome">
 *       <mad-quick-form action="CidadeForm::quickRegister"
 *                       label="Cadastrar"
 *                       icon="check"
 *                       message="Cadastre a cidade:">
 *           <mad-input-field name="nome"      label="Nome" required />
 *           <mad-dbcombo-field name="estado_id" label="Estado"
 *                              model="Estado" display="nome"
 *                              order-by="nome" required />
 *           <mad-input-field name="codigo_ibge" label="IBGE" />
 *       </mad-quick-form>
 *   </mad-dbunique-search-field>
 *
 * E equivalente a:
 *
 *   <mad-dbunique-search-field name="cidade_id" model="Cidade" display="nome"
 *       no-results-quick-register-action="CidadeForm::quickRegister"
 *       no-results-quick-register-label="Cadastrar"
 *       no-results-quick-register-icon="check"
 *       no-results-message="Cadastre a cidade:"
 *       :no-results-quick-fields="[
 *           ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
 *           ['name' => 'estado_id', 'label' => 'Estado', 'type' => 'dbcombo',
 *            'model' => 'Estado', 'display' => 'nome', 'order_by' => 'nome', 'required' => true],
 *           ['name' => 'codigo_ibge', 'label' => 'IBGE', 'type' => 'text'],
 *       ]" />
 *
 * Tags filhas suportadas dentro de <mad-quick-form>:
 *   <mad-input-field>     → text/number/email/tel/password/date (via `type` attr)
 *   <mad-numeric-field>   → text (mascara nao funciona inline — usa input puro)
 *   <mad-date-field>      → date
 *   <mad-textarea-field>  → text (textarea nao tem suporte no JS dropdown)
 *   <mad-dbcombo-field>   → dbcombo (auto-query)
 *   <mad-select-field>    → select (passa items se literal array)
 */
class MadQuickFormCompiler
{
    /**
     * Tags de selecao MAD que aceitam <mad-quick-form> como filho.
     * Ordem importa: tags mais especificas primeiro.
     */
    private const PARENT_TAGS = [
        'dbunique-search-field',
        'dbmulti-search-field',
        'dbselect-check-field',
        'dbselect-field',
        'dbcombo-field',
        'unique-search-field',
        'multi-search-field',
        'select-check-field',
        'select-field',
    ];

    /**
     * Processa o template Blade. Encontra cada `<mad-quick-form>...</mad-quick-form>`
     * dentro de uma tag MAD de selecao e converte em props no pai.
     */
    public static function process(string $template): string
    {
        if (stripos($template, '<mad-quick-form') === false) {
            return $template;
        }

        $parents = implode('|', self::PARENT_TAGS);

        // Match: <mad-PARENT ...attrs1...> ANY1 <mad-quick-form ...qfattrs...>QFBODY</mad-quick-form> ANY2 </mad-PARENT>
        // (?<!\/) garante que nao casa tags self-closing (`<mad-X ... />`).
        //
        // $attr: lista de atributos QUE RESPEITA ASPAS. Sem isto, um valor com `>`
        // dentro das aspas — ex.: name="{estado->pais_id}" numa cascata — truncaria
        // a tag no primeiro `>` (do `->`), vazando o resto dos atributos como texto
        // cru na tela. As alternativas consomem strings entre aspas inteiras (com o
        // `>` protegido) ou qualquer char que nao seja `>`/aspas; param no `>` real.
        $attr = '(?:"[^"]*"|\'[^\']*\'|[^>"\'])*';

        $pattern = '/<mad-(' . $parents . ')(' . $attr . ')(?<!\/)>' // 1=tag, 2=attrs do pai
                 . '([\s\S]*?)'                              // 3=conteudo antes do quick-form
                 . '<mad-quick-form(' . $attr . ')(?<!\/)>'  // 4=attrs do quick-form
                 . '([\s\S]*?)'                              // 5=corpo do quick-form
                 . '<\/mad-quick-form>'
                 . '([\s\S]*?)'                              // 6=conteudo depois do quick-form
                 . '<\/mad-\1>/i';

        $counter = 0;
        $template = preg_replace_callback($pattern, function (array $m) use (&$counter): string {
            $counter++;
            $tag        = $m[1];
            $parentAttr = $m[2];
            $before     = $m[3];
            $qfAttrStr  = $m[4];
            $qfBody     = $m[5];
            $after      = $m[6];

            $qfAttrs = self::parseAttrs($qfAttrStr);
            $fields  = self::parseFields($qfBody);

            // Gera variavel PHP unica pra evitar conflitos de quoting no atributo Blade.
            // Em vez de inline `:prop="[...]"` (que tem problemas com quoting),
            // declaramos @php $__madQF_N = [...]; @endphp e referenciamos pela variavel.
            $varName    = "__madQF_{$counter}";
            $phpLiteral = self::fieldsToPhpLiteral($fields);
            $phpDecl    = "<?php \${$varName} = {$phpLiteral}; ?>";

            $injection = self::buildInjection($qfAttrs, $varName);
            $body      = trim($before . $after);

            if ($body === '') {
                return $phpDecl . "<mad-{$tag}{$parentAttr} {$injection} />";
            }
            return $phpDecl . "<mad-{$tag}{$parentAttr} {$injection}>{$body}</mad-{$tag}>";
        }, $template);

        return $template;
    }

    /**
     * Parse leve de atributos HTML em array assoc.
     * Suporta: name="val", :name="expr", name (booleano).
     */
    private static function parseAttrs(string $attrStr): array
    {
        $out = [];
        if (preg_match_all(
            '/\s*(:?[a-zA-Z_][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/',
            $attrStr,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $key = $m[1];
                if (isset($m[2]) && $m[2] !== '') {
                    $out[$key] = $m[2];
                } elseif (isset($m[3]) && $m[3] !== '') {
                    $out[$key] = $m[3];
                } else {
                    $out[$key] = true; // boolean
                }
            }
        }
        return $out;
    }

    /**
     * Parse dos filhos do <mad-quick-form>. Retorna array de field defs no formato
     * esperado pelo MadNoResultsHelper::resolveQuickFields.
     * Public: reutilizado pelo MadSheetCompiler (quick-form dentro de <mad-sheet-col>).
     */
    public static function parseFields(string $body): array
    {
        $fields = [];
        // Casa qualquer tag <mad-X ...attrs/>  ou  <mad-X ...attrs>...</mad-X>
        // Atributos quoted-aware (igual ao process()): um `>` dentro de aspas num
        // filho — ex.: <mad-dbcombo-field name="{rel->fk}"> — nao trunca a tag.
        preg_match_all(
            '/<mad-([a-z0-9-]+)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)\/?>/i',
            $body,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $tag   = strtolower($m[1]);
            $attrs = self::parseAttrs($m[2]);
            $field = self::buildField($tag, $attrs);
            if ($field !== null) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Mapeia uma tag MAD filha em um field def.
     */
    private static function buildField(string $tag, array $attrs): ?array
    {
        $name = $attrs['name'] ?? null;
        if (!$name) return null;

        // Mapping: tag → tipo de campo
        $type = match (true) {
            $tag === 'dbcombo-field'  => 'dbcombo',
            $tag === 'select-field'   => 'select',
            $tag === 'date-field'     => 'date',
            $tag === 'numeric-field'  => 'text',  // mascara nao funciona inline; usa text
            $tag === 'input-field'    => strtolower((string)($attrs['type'] ?? 'text')),
            default => null,
        };
        if (!$type) return null;

        // Tipos validos do JS (text/number/email/tel/password/date/select)
        $valid = ['text', 'number', 'email', 'tel', 'password', 'date'];
        if ($type !== 'select' && $type !== 'dbcombo' && !in_array($type, $valid, true)) {
            $type = 'text';
        }

        $field = [
            'name'  => $name,
            'label' => (string)($attrs['label'] ?? $name),
            'type'  => $type,
        ];
        if (!empty($attrs['required']))    $field['required']    = true;
        if (!empty($attrs['placeholder'])) $field['placeholder'] = (string) $attrs['placeholder'];
        if (!empty($attrs['value']))       $field['value']       = (string) $attrs['value'];

        if ($type === 'dbcombo') {
            if (!empty($attrs['model']))    $field['model']    = (string) $attrs['model'];
            if (!empty($attrs['display']))  $field['display']  = (string) $attrs['display'];
            if (!empty($attrs['key']))      $field['key']      = (string) $attrs['key'];
            if (!empty($attrs['order-by'])) $field['order_by'] = (string) $attrs['order-by'];
            if (!empty($attrs['database'])) $field['database'] = (string) $attrs['database'];
        } elseif ($type === 'select') {
            // <mad-select-field :items="$arr"> → recebe expressao PHP literal
            // Suportar somente valores em :items que sejam array literal inline:
            //   :items="['1' => 'A', '2' => 'B']"
            // Qualquer expressao mais complexa deve usar a prop :no-results-quick-fields direto.
            if (!empty($attrs[':items'])) {
                $field['_optionsExpr'] = (string) $attrs[':items'];
            } elseif (!empty($attrs['items'])) {
                $field['_optionsExpr'] = (string) $attrs['items'];
            }
        }

        return $field;
    }

    /**
     * Constroi a string de atributos a injetar no componente pai.
     * `$varName` e o nome da variavel PHP que carrega o array de fields,
     * declarada via <?php $__madQF_N = [...]; ?> antes da tag.
     */
    private static function buildInjection(array $qfAttrs, string $varName): string
    {
        $parts = [];

        // Aliases aceitos no <mad-quick-form>:
        //   action  → no-results-quick-register-action
        //   label   → no-results-quick-register-label
        //   icon    → no-results-quick-register-icon
        //   class   → no-results-quick-register-class
        //   message → no-results-message
        $map = [
            'action'  => 'no-results-quick-register-action',
            'label'   => 'no-results-quick-register-label',
            'icon'    => 'no-results-quick-register-icon',
            'class'   => 'no-results-quick-register-class',
            'message' => 'no-results-message',
        ];
        foreach ($map as $src => $dst) {
            if (isset($qfAttrs[$src]) && $qfAttrs[$src] !== true) {
                $val = htmlspecialchars((string) $qfAttrs[$src], ENT_QUOTES, 'UTF-8');
                $parts[] = "{$dst}=\"{$val}\"";
            }
        }

        // Array PHP via referencia a variavel (evita quoting hell)
        $parts[] = ":no-results-quick-fields=\"\${$varName}\"";

        return implode(' ', $parts);
    }

    /**
     * Converte array PHP em string PHP literal (legivel no Blade).
     * Ex: [['name' => 'nome', 'type' => 'text'], ...]
     * Public: reutilizado pelo MadSheetCompiler (quick-form dentro de <mad-sheet-col>).
     */
    public static function fieldsToPhpLiteral(array $fields): string
    {
        if (empty($fields)) return '';
        $items = [];
        foreach ($fields as $f) {
            $kv = [];
            foreach ($f as $k => $v) {
                if ($k === '_optionsExpr') {
                    $kv[] = "'options' => " . $v;
                } elseif (is_bool($v)) {
                    $kv[] = "'{$k}' => " . ($v ? 'true' : 'false');
                } else {
                    $kv[] = "'{$k}' => '" . str_replace("'", "\\'", (string) $v) . "'";
                }
            }
            $items[] = '[' . implode(', ', $kv) . ']';
        }
        return '[' . implode(', ', $items) . ']';
    }
}
