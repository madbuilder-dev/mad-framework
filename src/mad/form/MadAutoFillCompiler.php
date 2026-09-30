<?php
namespace Mad\Form;

/**
 * MadAutoFillCompiler — pre-compilador que extrai filhos `<fill>` declarados
 * dentro de selects de banco MAD e converte numa prop `:auto-fill="[...]"` no
 * componente pai.
 *
 * Roda no `MadBlade::compileString()` DEPOIS do MadQuickFormCompiler e ANTES da
 * etapa de mapping `<mad-X>` → `<x-X>`.
 *
 * Sintaxe declarativa (espelha o idioma do dbseek `<fill>`):
 *
 *   <mad-dbcombo-field name="cliente_id" model="Cliente" display="nome">
 *       <fill field="email"  from="email" />
 *       <fill field="cidade" from="cidade->nome" />
 *       <fill field="nasc"   from="data_nasc" transform="date:d/m/Y" only-empty />
 *   </mad-dbcombo-field>
 *
 * Equivale a:
 *
 *   <mad-dbcombo-field name="cliente_id" model="Cliente" display="nome"
 *       :auto-fill="[
 *           ['field' => 'email',  'from' => 'email'],
 *           ['field' => 'cidade', 'from' => 'cidade->nome'],
 *           ['field' => 'nasc',   'from' => 'data_nasc', 'transform' => 'date:d/m/Y', 'only_empty' => true],
 *       ]" />
 *
 * Atributos do `<fill>`:
 *   field      (obrig.) campo do FORMULÁRIO a preencher (target)
 *   from       (obrig.) coluna · caminho de relação (a->b->c) · template {x} do registro
 *   transform  (opc.)   DSL server-side (ver Mad\Form\MadFillTransform)
 *   only-empty (opc.)   só preenche se o campo destino estiver vazio
 */
class MadAutoFillCompiler
{
    /** Selects de banco single-value que aceitam `<fill>` como filho. */
    private const PARENT_TAGS = [
        'dbunique-search-field',
        'dbselect-field',
        'dbcombo-field',
    ];

    public static function process(string $template): string
    {
        if (stripos($template, '<fill') === false) {
            return $template;
        }

        $parents = implode('|', self::PARENT_TAGS);
        // Atributos quoted-aware: um '>' dentro de aspas (ex.: name="{rel->fk}")
        // não trunca a tag. Idêntico ao MadQuickFormCompiler.
        $attr = '(?:"[^"]*"|\'[^\']*\'|[^>"\'])*';

        $pattern = '/<mad-(' . $parents . ')(' . $attr . ')(?<!\/)>' // 1=tag, 2=attrs do pai
                 . '([\s\S]*?)'                                       // 3=corpo
                 . '<\/mad-\1>/i';

        $counter = 0;
        return preg_replace_callback($pattern, function (array $m) use (&$counter): string {
            $tag        = $m[1];
            $parentAttr = $m[2];
            $body       = $m[3];

            if (stripos($body, '<fill') === false) {
                return $m[0]; // sem <fill> — re-emite intacto
            }

            $fills = self::parseFills($body);
            if (empty($fills)) {
                return $m[0];
            }

            $counter++;
            $varName = "__madAF_{$counter}";
            $phpDecl = "<?php \${$varName} = " . self::fillsToPhpLiteral($fills) . "; ?>";
            $injection = ":auto-fill=\"\${$varName}\"";

            // Remove os <fill> (e eventuais </fill>) do corpo; preserva o resto
            // (ex.: um <mad-quick-form> já convertido pelo compilador anterior).
            $cleanBody = preg_replace('/<fill(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?\/?>/i', '', $body);
            $cleanBody = preg_replace('/<\/fill\s*>/i', '', $cleanBody);
            $cleanBody = trim($cleanBody);

            if ($cleanBody === '') {
                return $phpDecl . "<mad-{$tag}{$parentAttr} {$injection} />";
            }
            return $phpDecl . "<mad-{$tag}{$parentAttr} {$injection}>{$cleanBody}</mad-{$tag}>";
        }, $template);
    }

    /** Extrai cada `<fill ... />` do corpo em [['field','from','transform','only_empty'], ...]. */
    private static function parseFills(string $body): array
    {
        $fills = [];
        preg_match_all(
            '/<fill((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)\/?>/i',
            $body,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $attrs = self::parseAttrs($m[1]);
            $field = isset($attrs['field']) && $attrs['field'] !== true ? (string) $attrs['field'] : '';
            $from  = isset($attrs['from'])  && $attrs['from']  !== true ? (string) $attrs['from']  : '';
            if ($field === '' || $from === '') {
                continue;
            }

            $entry = ['field' => $field, 'from' => $from];
            if (!empty($attrs['transform']) && $attrs['transform'] !== true) {
                $entry['transform'] = (string) $attrs['transform'];
            }
            if (array_key_exists('only-empty', $attrs)) {
                $entry['only_empty'] = true;
            }
            $fills[] = $entry;
        }

        return $fills;
    }

    /**
     * Parse leve de atributos HTML em array assoc.
     * Suporta: name="val", name='val', name (booleano). Idêntico ao MadQuickFormCompiler.
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

    /** Converte os fills em string PHP literal segura pra embutir no Blade. */
    private static function fillsToPhpLiteral(array $fills): string
    {
        $items = [];
        foreach ($fills as $f) {
            $kv = [];
            foreach ($f as $k => $v) {
                if (is_bool($v)) {
                    $kv[] = "'{$k}' => " . ($v ? 'true' : 'false');
                } else {
                    $kv[] = "'{$k}' => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v) . "'";
                }
            }
            $items[] = '[' . implode(', ', $kv) . ']';
        }
        return '[' . implode(', ', $items) . ']';
    }
}
