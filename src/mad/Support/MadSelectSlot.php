<?php

namespace Mad\Support;

/**
 * MadSelectSlot — marca `selected` nas `<option>` que vieram pelo SLOT de um
 * `<mad-select-field>`.
 *
 * ## Por que isto existe
 *
 * O `select-field` tem dois caminhos para as opções:
 *
 *   • `:items="[...]"` — o `@foreach` do template emite `selected` no
 *     SERVIDOR, comparando cada chave com o valor corrente;
 *   • `<option>` no slot — o template só imprimia `{!! $slot !!}` e mandava o
 *     valor corrente num `data-mad-selected="…"`, deixando a seleção para o JS.
 *
 * O JS (`mad-ui.js`, `madSelect.init`) só aplicava o `data-mad-selected` quando
 * o select estava VAZIO. E um `<select>` single nunca está vazio: sem uma
 * `<option value="">` na frente, o navegador já elege a primeira opção. Ou
 * seja: com options no slot e sem placeholder, o valor do servidor era
 * silenciosamente DESCARTADO — o formulário abria mostrando a primeira opção e
 * o Salvar gravava essa primeira opção por cima do valor real. Nenhum erro,
 * nenhum log: só o dado do usuário trocado.
 *
 * Marcar no servidor conserta a raiz (o HTML já nasce com a escolha certa, como
 * no caminho `:items`), funciona antes do JS montar e não depende de o autor da
 * tela lembrar de pôr um placeholder.
 *
 * ## Regras
 *
 * 1. Valor corrente vazio ⇒ não marca nada (o navegador segue com o default).
 * 2. `selected` escrito à mão pelo autor GANHA — a não ser que esteja numa
 *    option de value vazio, que é placeholder ("nada escolhido"), não escolha.
 * 3. Valor que não casa com nenhuma option ⇒ não marca nada; quem resolve é o
 *    JS, que cria a option sintética (combo ajax, por exemplo).
 * 4. Single-select marca só a PRIMEIRA option que casa; multi marca todas.
 * 5. Option sem atributo `value` tem como valor o próprio texto (spec HTML).
 * 6. Comparação por string, depois de decodificar entidades HTML do atributo.
 *
 * Toda varredura de atributo é *quote-aware*: valor de atributo pode conter
 * `>` (chains `{a->b}`) e `selected` como texto (`data-x="a selected b"`).
 */
final class MadSelectSlot
{
    /** Atributos que fazem do `<select>` um múltiplo (nativo ou modo MAD). */
    private const MULTI_ATTRS = [
        'multiple',
        'data-mad-multiselect',
        'data-mad-selectcheck',
        'data-mad-multientry',
        'data-mad-dbmultisearch',
    ];

    /** O bloco de atributos crus da tag declara um select múltiplo? */
    public static function isMultiple(string $attrs): bool
    {
        if ($attrs === '') {
            return false;
        }

        $bare = self::mask($attrs);

        foreach (self::MULTI_ATTRS as $attr) {
            if (preg_match('/(?:^|\s)' . preg_quote($attr, '/') . '\s*(?:=|(?=[\s\/]|$))/i', $bare)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valor corrente do campo como LISTA de strings.
     *
     * `$resolved` é o valor já achatado por `MadFieldValue::resolve()` (string).
     * `$candidates` são os valores CRUS na ordem de precedência (registro, prop
     * `selected`, prop `value`) — só eles preservam o array de um multi-select,
     * porque o `MadFieldValue` achata array em `''` de propósito (ele serve o
     * atributo `value=` de um `<input>`, que é escalar).
     *
     * @param  array<int, mixed> $candidates
     * @return array<int, string>
     */
    public static function valueList(string $resolved, array $candidates = [], bool $multiple = false): array
    {
        if ($multiple) {
            foreach ($candidates as $candidate) {
                if (is_array($candidate) && $candidate !== []) {
                    return self::normalize($candidate);
                }
            }
        }

        if ($resolved === '') {
            return [];
        }

        // Multi escalar chega como lista separada por vírgula — mesmo dialeto
        // que o `setValue()` do MAD Select já aceita no JS.
        return self::normalize($multiple ? explode(',', $resolved) : [$resolved]);
    }

    /**
     * Devolve o HTML do slot com `selected` na option do valor corrente.
     *
     * Mantém o HTML intacto (byte a byte) quando não há o que marcar.
     *
     * @param array<int, string> $values
     */
    public static function markSelected(string $html, array $values, bool $multiple = false): string
    {
        $values = self::normalize($values);
        if ($html === '' || $values === []) {
            return $html;
        }

        $wanted = array_fill_keys($values, true);

        $found = preg_match_all(
            '/<option(?=[\s>\/])((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/i',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );
        if (!$found) {
            return $html;
        }

        $options = [];
        foreach ($matches as $match) {
            $start = $match[0][1];
            $len   = strlen($match[0][0]);
            $attrs = $match[1][0];

            $value = self::attr($attrs, 'value');
            if ($value === null) {
                $value = self::textOf($html, $start + $len);
            }

            $options[] = [
                'start'     => $start,
                'len'       => $len,
                'attrs'     => $attrs,
                'selfClose' => $match[2][0] === '/',
                'value'     => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'selected'  => self::hasSelected($attrs),
            ];
        }

        // Regra 2 — escolha explícita do autor manda.
        foreach ($options as $option) {
            if ($option['selected'] && $option['value'] !== '') {
                return $html;
            }
        }

        $hits = [];
        foreach ($options as $i => $option) {
            if (isset($wanted[$option['value']])) {
                $hits[$i] = true;
                if (!$multiple) {
                    break; // regra 4
                }
            }
        }

        // Regra 3 — valor fora da lista: não inventa seleção nem tira a que existe.
        if ($hits === []) {
            return $html;
        }

        // Reescreve de trás para frente: os offsets do começo seguem válidos.
        foreach (array_reverse($options, true) as $i => $option) {
            $want = isset($hits[$i]);
            if ($want === $option['selected']) {
                continue;
            }

            $attrs = $want
                ? rtrim($option['attrs']) . ' selected'
                : self::stripSelected($option['attrs']);

            $html = substr_replace(
                $html,
                '<option' . $attrs . ($option['selfClose'] ? '/' : '') . '>',
                $option['start'],
                $option['len']
            );
        }

        return $html;
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private static function normalize(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $value = is_bool($value) ? ($value ? '1' : '') : (string) $value;
            if ($value === '') {
                continue; // vazio = "nada selecionado", nunca casa com option
            }
            $out[] = $value;
        }

        return array_values(array_unique($out));
    }

    /**
     * Texto da option a partir do fim da tag de abertura — o valor de uma
     * `<option>` sem atributo `value` (spec HTML: texto com espaços colapsados).
     */
    private static function textOf(string $html, int $offset): string
    {
        $rest = substr($html, $offset);
        $cut  = strpos($rest, '<');
        $text = $cut === false ? $rest : substr($rest, 0, $cut);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Substitui todo valor entre aspas por `#` do MESMO tamanho em bytes.
     *
     * É o que torna as varreduras seguras: no texto mascarado não existe `>`,
     * espaço nem a palavra `selected` dentro de valor de atributo, e os offsets
     * continuam alinhados com a string original.
     */
    private static function mask(string $attrs): string
    {
        return (string) preg_replace_callback(
            '/"[^"]*"|\'[^\']*\'/',
            static fn (array $m): string => str_repeat('#', strlen($m[0])),
            $attrs
        );
    }

    /** Valor do atributo `$name`, ou null quando o atributo não existe. */
    private static function attr(string $attrs, string $name): ?string
    {
        if ($attrs === '') {
            return null;
        }

        $re = '/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s>\/]*)/i';
        if (!preg_match($re, self::mask($attrs), $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $raw   = substr($attrs, $m[1][1], strlen($m[1][0]));
        $quote = $raw !== '' ? $raw[0] : '';

        return ($quote === '"' || $quote === "'") ? substr($raw, 1, -1) : $raw;
    }

    /** A tag traz o atributo `selected` (nu ou com valor)? */
    private static function hasSelected(string $attrs): bool
    {
        if ($attrs === '') {
            return false;
        }

        return (bool) preg_match('/(?:^|\s)selected\s*(?:=|(?=[\s\/]|$))/i', self::mask($attrs));
    }

    /** Remove o atributo `selected` preservando o resto byte a byte. */
    private static function stripSelected(string $attrs): string
    {
        $mask = self::mask($attrs);
        $re   = '/(^|\s)selected(\s*=\s*[^\s>\/]*)?(?=[\s\/]|$)/i';

        while (preg_match($re, $mask, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[1][0]);
            $len   = strlen($m[0][0]) - strlen($m[1][0]);
            if ($len <= 0) {
                break;
            }
            $attrs = substr_replace($attrs, '', $start, $len);
            $mask  = substr_replace($mask, '', $start, $len);
        }

        return $attrs;
    }
}
