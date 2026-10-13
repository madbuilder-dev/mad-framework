<?php
namespace Mad\Form;

use Mad\Grid\MadGridCompiler;

/**
 * MadOptionHighlightCompiler — pré-compilador que extrai o
 * `<mad-option-highlights>` declarado dentro de um campo de banco e o converte
 * numa prop `:option-highlights="[...]"` no componente pai.
 *
 * Roda no `MadBlade::compileString()` DEPOIS do MadAutoFillCompiler (o mesmo
 * pai pode ter `<mad-quick-form>`, `<fill>` e destaques) e ANTES da etapa de
 * mapping `<mad-X>` → `<x-X>`.
 *
 * ┌──────────────────────────────────────────────────────────────────────────────┐
 * │  <mad-option-highlights> — Destaque das opções por condição                 │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  <mad-dbcombo-field name="cliente_id" model="Cliente" display="{nome}">     │
 * │      <mad-option-highlights>                                                │
 * │          <mad-option-highlight when-field="status" when-value="inativo"     │
 * │                                tone="danger" badge="Inativo" />             │
 * │          <mad-option-highlight when-field="status" when-value="pendente"    │
 * │                                tone="warning" badge="Pendente"              │
 * │                                paint="badge" />                             │
 * │          <mad-option-highlight when-field="saldo" when-op="lt"              │
 * │                                when-value="0" color="#fde68a" />            │
 * │          <mad-option-highlight display-condition="estaInadimplente"         │
 * │                                tone="danger" badge="{dias_atraso} dias" />  │
 * │      </mad-option-highlights>                                               │
 * │  </mad-dbcombo-field>                                                       │
 * │                                                                              │
 * │  Pais: <mad-dbcombo-field>, <mad-dbselect-field>,                           │
 * │  <mad-dbunique-search-field> e <mad-dbmulti-search-field>.                  │
 * │  Condição: a do <mad-row-highlight> da grade — when-field + when-value /    │
 * │  when-op (eq, neq, gt, gte, lt, lte) / when-in / when-nin, ou               │
 * │  display-condition="metodo" (public static da tela) / "Cls::metodo".        │
 * │  when-field é uma coluna do model do campo (o registro da opção).           │
 * │  tone: danger|warning|success|info; color="#hex" livre (vence o tom).       │
 * │  badge: texto do selo à direita da opção, literal ou máscara {coluna}.      │
 * │  paint: row (padrão, pinta a linha) ou badge (só o selo leva a cor; sem     │
 * │  badge vale row). Vale a primeira regra que casar; regra sem condição é     │
 * │  ignorada, com aviso.                                                       │
 * └──────────────────────────────────────────────────────────────────────────────┘
 *
 * Equivale a passar o array direto (formato do `rowHighlights()` da grade,
 * mais `badge` e `paint`):
 *
 *   <mad-dbcombo-field name="cliente_id" model="Cliente" display="{nome}"
 *       :option-highlights="[
 *           ['when' => ['field' => 'status', 'op' => 'eq', 'value' => 'inativo'], 'tone' => 'danger', 'badge' => 'Inativo'],
 *           ['when' => 'estaInadimplente', 'tone' => 'danger', 'badge' => '{dias_atraso} dias'],
 *       ]" />
 *
 * A leitura de cada regra é a do `<mad-row-highlights>`
 * ({@see MadGridCompiler::optionHighlightConfigs()}); a avaliação no runtime
 * fica em {@see OptionHighlights}.
 */
class MadOptionHighlightCompiler
{
    /** Campos de banco que aceitam `<mad-option-highlights>` como filho. */
    private const PARENT_TAGS = [
        'dbunique-search-field',
        'dbmulti-search-field',
        'dbselect-field',
        'dbcombo-field',
    ];

    /**
     * Atributos quoted-aware: um '>' dentro de aspas (ex.: name="{rel->fk}")
     * não trunca a tag. Idêntico ao MadAutoFillCompiler.
     */
    private const ATTR = '(?:"[^"]*"|\'[^\']*\'|[^>"\'])*';

    /** Corrida de atributos de uma tag que pode ser self-closing (o '/' de '/>' fica de fora). */
    private const ATTR_SC = '(?:"[^"]*"|\'[^\']*\'|\/(?!>)|[^>"\'\/])*';

    public static function process(string $template): string
    {
        if (stripos($template, '<mad-option-highlight') === false) {
            return $template;
        }

        // Comentário Blade e bloco @php ficam de fora, como no MadGridCompiler:
        // regra comentada não vale, e a tag citada num docblock (o dos próprios
        // campos de banco cita) não é template.
        $masks    = [];
        $template = (string) preg_replace_callback(
            '/\{\{--.*?--\}\}|@php\b[\s\S]*?@endphp/s',
            function (array $m) use (&$masks): string {
                $token = 'MAD__OH_MASK__' . count($masks) . '__';
                $masks[$token] = $m[0];

                return $token;
            },
            $template
        );
        if (stripos($template, '<mad-option-highlight') === false) {
            return strtr($template, $masks);
        }

        $parents = implode('|', self::PARENT_TAGS);
        $pattern = '/<mad-(' . $parents . ')(' . self::ATTR . ')(?<!\/)>' // 1=tag, 2=attrs do pai
                 . '([\s\S]*?)'                                           // 3=corpo
                 . '<\/mad-\1>/i';

        $counter  = 0;
        $template = preg_replace_callback($pattern, function (array $m) use (&$counter): string {
            $tag        = $m[1];
            $parentAttr = $m[2];
            $body       = $m[3];

            if (stripos($body, '<mad-option-highlight') === false) {
                return $m[0]; // sem destaque — re-emite intacto
            }

            // Vários containers somam as regras, na ordem em que aparecem.
            $configs = [];
            $diag    = '';
            $body = preg_replace_callback(
                '/<mad-option-highlights(?![\w-])' . self::ATTR_SC . '(?:>([\s\S]*?)<\/mad-option-highlights>|\s*\/>)/i',
                function (array $c) use (&$configs, &$diag): string {
                    [$rules, $notes] = MadGridCompiler::optionHighlightConfigs((string) ($c[1] ?? ''));
                    array_push($configs, ...$rules);
                    $diag .= $notes;

                    return '';
                },
                $body
            );
            $cleanBody = trim((string) $body);

            // Tira as tags do corpo mesmo sem regra válida: a tag crua cairia no
            // mapping <mad-X> → componente, que não existe.
            $injection = '';
            $phpDecl   = '';
            if ($configs !== []) {
                $counter++;
                $varName   = "__madOH_{$counter}";
                $phpDecl   = "<?php \${$varName} = [" . implode(', ', $configs) . "]; ?>";
                $injection = " :option-highlights=\"\${$varName}\"";
            }

            if ($cleanBody === '') {
                return $diag . $phpDecl . "<mad-{$tag}{$parentAttr}{$injection} />";
            }
            return $diag . $phpDecl . "<mad-{$tag}{$parentAttr}{$injection}>{$cleanBody}</mad-{$tag}>";
        }, $template);

        return strtr(self::dropStray((string) $template), $masks);
    }

    /**
     * O que sobrou está fora de lugar: `<mad-option-highlights>` num campo que
     * não é um dos quatro pais, ou `<mad-option-highlight>` sem o container.
     * Sai do template (senão a tela quebraria no mapping de componentes) e vira
     * aviso, como o `<mad-row-highlight>` fora do `<mad-row-highlights>`.
     */
    private static function dropStray(string $template): string
    {
        if (stripos($template, '<mad-option-highlight') === false) {
            return $template;
        }

        $stray = static function (string $pattern, string $msg) use (&$template): void {
            $template = (string) preg_replace_callback(
                $pattern,
                static fn (): string => MadGridCompiler::optionHighlightNote($msg),
                $template
            );
        };

        $stray(
            '/<mad-option-highlights(?![\w-])' . self::ATTR_SC . '(?:>[\s\S]*?<\/mad-option-highlights>|\s*\/>)/i',
            '<mad-option-highlights> fora de um campo que aceita destaque das opções'
            . ' (mad-dbcombo-field, mad-dbselect-field, mad-dbunique-search-field, mad-dbmulti-search-field) — ignorado.'
        );
        $stray(
            '/<mad-option-highlight(?![\w-])' . self::ATTR_SC . '(?:>[\s\S]*?<\/mad-option-highlight>|\s*\/>)/i',
            '<mad-option-highlight> fora de <mad-option-highlights> — ignorado.'
        );
        // Tag aberta sem fecho (ou fecho solto): some também, com o mesmo aviso.
        $stray(
            '/<\/?mad-option-highlights?(?![\w-])' . self::ATTR . '>/i',
            '<mad-option-highlights> sem fecho (ou fecho solto) — ignorado.'
        );

        return $template;
    }
}
