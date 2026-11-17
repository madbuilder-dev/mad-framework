<?php

namespace Mad\View;

/**
 * "Visível para" (fórum #346) — `visible-groups="rh, financeiro"` e
 * `visible-users="joao.silva"` na aba, na seção, na grade e no campo.
 *
 * Roda no começo do MadBlade::compileString(), antes de qualquer compilador
 * tocar nas tags: no render é tarde (o slot desenha os campos ANTES do template
 * do bloco, e eles já teriam se registrado no formulário). A tag inteira vira
 *
 *     <?php if (\Mad\Security\VisibleFor::allows([grupos], [logins])): MadFormRegistry::guardFields(…); ?>
 *         …a tag, sem os dois atributos…
 *     <?php else: \Mad\Form\MadFormRegistry::denyFields([campos], [grupos], [logins]); endif; ?>
 *
 * O `if` roda a cada requisição, para o usuário logado. Negado, o bloco não
 * executa — nada dele vai no HTML nem no estado da tela — e os campos dele
 * ficam negados no formulário (o Salvar ignora o que o navegador mandar para
 * eles). No `<mad-tab-panel>`, o título `<mad-tab>` de mesmo `name` recebe o
 * mesmo `if`.
 *
 * Valor literal (o Studio e o agente escrevem assim) ou expressão
 * (`:visible-groups="$grupos"`, string ou array). Sem os atributos, o texto
 * sai igual.
 */
final class VisibleForCompiler
{
    /** Bloco de atributos ciente de aspas (valor pode ter `>`). */
    private const ATTRS = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*?';

    /**
     * Tags cujo conteúdo é de OUTRA coisa (colunas da linha de uma Lista de
     * itens/detalhe, colunas da listagem): o nome delas conta, o de dentro não
     * — uma coluna `descricao` da lista não pode negar o campo `descricao` do
     * formulário.
     */
    private const OWN_SCOPE = '/^mad-(field-list|detail-form|detail-fields|grid|data-grid|sheet|kanban|calendar|gantt|agenda|[a-z]+-filters)$/';

    /** Tags de layout/ação: o `name` delas não é campo. */
    private const NOT_FIELD = '/^mad-(tabs?|tabs-list|tab-panel|form|form-section|form-grid|form-stack|form-actions|card|row|columns?|stack|btn|act|actions?|action-group|grid|col|dropdown|menu|nav|page-[a-z-]+|section|modal|drawer|steps|segmented|[a-z]+-filters)(-|$)/';

    /** Item de grade que POSTA linhas: o `name` dele é campo do formulário. */
    private const ROWS_FIELD = ['mad-field-list', 'mad-detail-form', 'mad-sheet'];

    /** Fim do nome da tag: `<mad-tab` não pode casar `<mad-tab-panel` nem `<mad-tabs`. */
    private const END = '(?![-\w])';

    public static function compile(string $value): string
    {
        if (strpos($value, 'visible-groups') === false && strpos($value, 'visible-users') === false) {
            return $value;
        }

        // Do último para o primeiro: o de dentro já embrulhado não atrapalha o de fora.
        for ($guard = 0; $guard < 500; $guard++) {
            $tag = self::lastRestricted($value);
            if ($tag === null) {
                break;
            }
            $value = self::wrap($value, $tag);
        }

        return $value;
    }

    /**
     * @return array{name: string, start: int, open: string, attrs: string, self: bool}|null
     */
    private static function lastRestricted(string $value): ?array
    {
        if (!preg_match_all('/<(mad-[a-z0-9-]+)' . self::END . '(' . self::ATTRS . ')(\/?)>/i', $value, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return null;
        }
        for ($i = count($m) - 1; $i >= 0; $i--) {
            $attrs = $m[$i][2][0];
            if (preg_match('/(?:^|\s):?visible-(?:groups|users)\s*=/', $attrs)) {
                return [
                    'name'  => strtolower($m[$i][1][0]),
                    'start' => $m[$i][0][1],
                    'open'  => $m[$i][0][0],
                    'attrs' => $attrs,
                    'self'  => $m[$i][3][0] === '/',
                ];
            }
        }

        return null;
    }

    /** @param array{name: string, start: int, open: string, attrs: string, self: bool} $tag */
    private static function wrap(string $value, array $tag): string
    {
        $g    = self::rule($tag['attrs'], 'visible-groups');
        $u    = self::rule($tag['attrs'], 'visible-users');
        $open = (string) preg_replace('/\s+:?visible-(?:groups|users)\s*=\s*(?:"[^"]*"|\'[^\']*\')/', '', $tag['open']);

        $start = $tag['start'];
        $end   = $tag['self'] ? $start + strlen($tag['open']) : self::closeEnd($value, $tag['name'], $start + strlen($tag['open']));
        $block = $open . substr($value, $start + strlen($tag['open']), $end - $start - strlen($tag['open']));
        $names = self::fieldNames($tag['name'], $tag['attrs'], $tag['self'] ? '' : substr($block, strlen($open)));

        // Grade/kanban/agenda/gantt: o bloco de filtros (já içado para logo
        // antes do host pelo passo 0.85) some junto — sozinho, sem o host, não
        // tem o que filtrar.
        if (preg_match('/^mad-(grid|kanban|calendar|gantt)$/', $tag['name'], $hm)) {
            $before = substr($value, 0, $start);
            $re = '#<mad-' . $hm[1] . '-filters' . self::END . self::ATTRS . '(?:/\s*>|>.*?</mad-' . $hm[1] . '-filters\s*>)(?:<!--mad:e\d+@[^>]*?-->)?\s*(?:<!--mad:s\d+@[^>]*?-->)?$#s';
            if (preg_match($re, $before, $fm, PREG_OFFSET_CAPTURE)) {
                // Começo do filtro, com o marcador de origem dele (Modo Vibe), se houver.
                $fStart = $fm[0][1];
                if (preg_match('#<!--mad:s\d+@[^>]*?-->$#', substr($before, 0, $fStart), $sm)) {
                    $fStart -= strlen($sm[0]);
                }
                $block = substr($value, $fStart, $start - $fStart) . $block;
                $start = $fStart;
            }
        }

        // Ramo `if`: a regra fica anotada também para quem vê (vai no token e no
        // estado; o Salvar reavalia para o usuário logado).
        $n = self::php($names);
        $guard = $names === [] ? '' : ' \\Mad\\Form\\MadFormRegistry::guardFields(' . $n . ', ' . $g . ', ' . $u . ');';
        $wrapped = '<?php if (\\Mad\\Security\\VisibleFor::allows(' . $g . ', ' . $u . ')):' . $guard . ' ?>' . $block
            . '<?php else: \\Mad\\Form\\MadFormRegistry::denyFields(' . $n . ', ' . $g . ', ' . $u . '); endif; ?>';
        $value = substr($value, 0, $start) . $wrapped . substr($value, $end);

        // Aba: o título de mesmo `name` some junto com o painel.
        if ($tag['name'] === 'mad-tab-panel' && ($panel = self::attr($tag['attrs'], 'name')) !== null && $panel !== '') {
            $value = self::wrapTabTitle($value, $panel, $g, $u);
        }

        return $value;
    }

    private static function wrapTabTitle(string $value, string $panel, string $g, string $u): string
    {
        $re = '/<mad-tab' . self::END . '(' . self::ATTRS . ')(\/?)>/i';
        $offset = 0;
        while (preg_match($re, $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $openLen = strlen($m[0][0]);
            $offset = $start + $openLen;
            if (self::attr($m[1][0], 'name') !== $panel) {
                continue;
            }
            $end = $m[2][0] === '/' ? $start + $openLen : self::closeEnd($value, 'mad-tab', $start + $openLen);
            $title = substr($value, $start, $end - $start);

            return substr($value, 0, $start)
                . '<?php if (\\Mad\\Security\\VisibleFor::allows(' . $g . ', ' . $u . ')): ?>' . $title . '<?php endif; ?>'
                . substr($value, $end);
        }

        return $value;
    }

    /** Fim (exclusivo) do `</$name>` que fecha a tag aberta antes de `$from`, contando aninhamento. */
    private static function closeEnd(string $value, string $name, int $from): int
    {
        $re = '/<(\/?)' . preg_quote($name, '/') . self::END . '(' . self::ATTRS . ')(\/?)>/i';
        $depth = 1;
        $offset = $from;
        while (preg_match($re, $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            if ($m[1][0] === '/') {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
            } elseif ($m[3][0] !== '/') {
                $depth++;
            }
        }

        // Sem fechamento: só a tag de abertura (o resto segue como estava).
        return $from;
    }

    /**
     * Nomes dos campos do bloco (o da própria tag, se for campo, e os de
     * dentro), sem descer no conteúdo de lista de itens, detalhe e listagem.
     *
     * @return list<string>
     */
    private static function fieldNames(string $tagName, string $tagAttrs, string $inner): array
    {
        $names = [];
        $own = self::attr($tagAttrs, 'name');
        if ($own !== null && $own !== '' && self::isField($tagName)) {
            $names[] = $own;
        }
        if (preg_match(self::OWN_SCOPE, $tagName)) {
            return $names;
        }

        $re = '/<(mad-[a-z0-9-]+)' . self::END . '(' . self::ATTRS . ')(\/?)>/i';
        $offset = 0;
        while (preg_match($re, $inner, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name  = strtolower($m[1][0]);
            $attrs = $m[2][0];
            $offset = $m[0][1] + strlen($m[0][0]);
            $n = self::attr($attrs, 'name');
            if ($n !== null && $n !== '' && self::isField($name)) {
                $names[] = $n;
            }
            if (preg_match(self::OWN_SCOPE, $name) && $m[3][0] !== '/') {
                $offset = self::closeEnd($inner, $name, $offset);
            }
        }

        return array_values(array_unique($names));
    }

    private static function isField(string $tag): bool
    {
        return in_array($tag, self::ROWS_FIELD, true) || ! preg_match(self::NOT_FIELD, $tag);
    }

    /** Valor literal de um atributo (`name="x"`), ou null. */
    private static function attr(string $attrs, string $name): ?string
    {
        if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/', $attrs, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . "\\s*=\\s*'([^']*)'/", $attrs, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        return null;
    }

    /** Código PHP da lista: literal → array; `:attr` → a expressão (string ou array). */
    private static function rule(string $attrs, string $name): string
    {
        if (preg_match('/(?:^|\s):' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $attrs, $m)) {
            $expr = trim(($m[2] ?? '') !== '' ? $m[2] : $m[1]);

            return $expr === '' ? '[]' : '(' . $expr . ')';
        }

        return self::php(\Mad\Security\VisibleFor::parse(self::attr($attrs, $name) ?? ''));
    }

    /** @param list<string> $list */
    private static function php(array $list): string
    {
        return '[' . implode(', ', array_map(static fn (string $s): string => var_export($s, true), $list)) . ']';
    }
}
