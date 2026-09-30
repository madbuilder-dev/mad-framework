<?php

namespace Mad\Support;

/**
 * CssUnits — ponto ÚNICO de normalização de valor autorado que vira CSS inline.
 *
 * ## Por que isto existe
 *
 * Todo componente MAD aceita largura/altura/gap como atributo, e o contrato
 * DOCUMENTADO é "número = px" (`width="140"`). Só que `140` não é `<length>` em
 * CSS — é número puro, e o browser trata a declaração como inválida. Antes deste
 * arquivo havia CINCO dialetos para resolver isso, e três formas de falhar:
 *
 *   FATAL      — o valor cai num `grid-template-columns`. Um track inválido
 *                invalida a declaração INTEIRA: o browser descarta o template
 *                todo, o `display:grid` cai numa coluna implícita e o componente
 *                inteiro empilha. Uma coluna mal escrita derrubava a tela.
 *                (era o caso do <mad-field-list> e do <mad-form-grid template>)
 *   SILENCIOSA — o valor cai num `width:`/`height:` solto. Só aquela declaração
 *                morre; o elemento fica no tamanho automático e a prop
 *                simplesmente não faz nada. Sem erro, sem log — o autor conclui
 *                que "o atributo não funciona".
 *   UNIDADE
 *   DUPLA      — o blade concatenava `px` sem checar (`height:{{ $h }}px`), então
 *                quem escrevia `height="300px"` gerava `300pxpx` e caía na
 *                silenciosa pelo caminho oposto.
 *
 * ## Por que três funções, e não uma
 *
 * O fallback correto depende de ONDE o valor vai:
 *   - num track de grid, devolver '' mataria a declaração inteira → `1fr`;
 *   - num `width:` solto, `1fr` é absurdo → devolve '' e o CALLER OMITE a
 *     declaração (não emitir nada é melhor que emitir algo que o browser ignora).
 * Era exatamente essa distinção que os cinco dialetos anteriores não expressavam.
 *
 * ## Efeito colateral de segurança
 *
 * Vários call sites concatenavam o valor autorado no atributo `style` SEM
 * escapar. A allow-list aqui é a mitigação: qualquer coisa com aspas, `;`, `<`
 * ou `>` não casa nenhum padrão e cai no fallback, então não existe mais caminho
 * de um atributo autorado fechar o `style="` e injetar markup.
 */
final class CssUnits
{
    /** Comprimento com unidade. `fr` só é válido em track — ver $trackUnits. */
    private const LENGTH_UNITS = 'px|%|em|rem|ch|ex|vw|vh|vmin|vmax|pt|pc|in|cm|mm|q';

    /** Palavras-chave de dimensionamento intrínseco. */
    private const KEYWORDS = 'auto|min-content|max-content|fit-content|inherit|initial|unset|revert';

    /**
     * Funções aceitas. O charset interno é restrito de propósito: sem aspas,
     * sem `;`, sem `<`/`>` — é o que impede fuga do atributo style.
     */
    private const FN_RE = '/^(minmax|calc|clamp|min|max|fit-content|repeat|var)\(\s*[a-z0-9\s.,%+*\/()_-]*\)$/i';

    /**
     * Comprimento solto: `width:`, `height:`, `gap:`, `max-width:`, custom
     * property de dimensão.
     *
     * `'140'` → `'140px'`; `'30%'`/`'2rem'`/`'auto'`/`'calc(100% - 8px)'` passam;
     * irreconhecível → $fallback (padrão `''`, que o caller usa como sinal para
     * NÃO emitir a declaração).
     */
    public static function length(string $v, string $fallback = ''): string
    {
        $v = trim($v);

        if ($v === '') {
            return $fallback;
        }

        // Contrato documentado: número puro = px.
        if (is_numeric($v)) {
            return $v . 'px';
        }

        if (preg_match('/^\d*\.?\d+(' . self::LENGTH_UNITS . ')$/i', $v)) {
            return $v;
        }

        if (preg_match('/^(' . self::KEYWORDS . ')$/i', $v)) {
            return $v;
        }

        if (preg_match(self::FN_RE, $v)) {
            return $v;
        }

        return $fallback;
    }

    /**
     * Fragmento `style` do wrapper `.mad-field`: `width:` + `max-width:` +
     * `--mad-label-gap`.
     *
     * Substitui a linha que estava DUPLICADA em 47 componentes de campo
     * (`$_dimStyle = ($width !== '' ? 'width:'.e($width).';' : '') . …`). Nenhum
     * deles normalizava, então `<mad-input-field width="200">` emitia
     * `style="width:200"` — declaração inválida, descartada, e a prop
     * simplesmente não fazia nada. Falha SILENCIOSA em 47 lugares ao mesmo tempo,
     * cada um com sua própria cópia da mesma linha.
     *
     * Declaração só é emitida quando o valor normaliza: melhor não emitir nada
     * que emitir algo que o browser ignora. Aceita mixed porque os props chegam
     * como int, string ou null dependendo de quem escreveu o blade.
     *
     * O 3º argumento (`$labelGap`) é a distância label→campo do PRÓPRIO campo e
     * sai como custom property `--mad-label-gap`, não como `gap:` direto — ver o
     * comentário no corpo.
     */
    public static function dim(mixed $width, mixed $maxWidth = null, mixed $labelGap = null): string
    {
        $out = '';

        $w = is_scalar($width) ? self::length((string) $width) : '';
        if ($w !== '') {
            $out .= 'width:' . $w . ';';
        }

        $mw = is_scalar($maxWidth) ? self::length((string) $maxWidth) : '';
        if ($mw !== '') {
            $out .= 'max-width:' . $mw . ';';
        }

        // --mad-label-gap: distância label→campo (e campo→dica) do PRÓPRIO campo.
        // Custom property, e não `gap:` direto, porque o wrapper é o mesmo elemento
        // que carrega width/max-width: um `gap` inline venceria o tema E o <mad-form>
        // sem jeito de "voltar ao padrão". Como var, a cascata resolve sozinha:
        // :root (tema) < <form style> < este wrapper.
        $lg = is_scalar($labelGap) ? self::length((string) $labelGap) : '';
        if ($lg !== '') {
            $out .= '--mad-label-gap:' . $lg . ';';
        }

        return $out;
    }

    /**
     * Cor autorada que vai para um atributo `style`.
     *
     * Aceita hex, `rgb()/rgba()`, `hsl()/hsla()`, `var(--token)` ou nome CSS
     * (`red`, `transparent`). Qualquer outra coisa — `;`, aspas, `url(`,
     * `expression(`, `<` — devolve `''`, e o caller OMITE a declaração. Mesma
     * allow-list do `color` do `<mad-btn>`.
     */
    public static function color(mixed $v): string
    {
        if (!is_scalar($v)) {
            return '';
        }

        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }

        return preg_match(
            '/^(#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([\d\s.,%\/]+\)|var\(--[a-z0-9-]+\)|[a-z]+)$/i',
            $v
        ) ? $v : '';
    }

    /**
     * Estilo do LABEL do campo: `label-color`, `label-size`, `label-weight`,
     * `label-italic`.
     *
     * Sai como custom properties (`--mad-label-*`) no wrapper `.mad-field`, e o
     * `.mad-label` do mad-ui.css lê cada uma com o valor padrão do framework como
     * fallback. Custom property, e não `color:` direto no label, pelo mesmo motivo
     * do `--mad-label-gap`: a cascata resolve sozinha — `<mad-form label-*>`
     * (style no `<form>`) vale para a tela toda, e o campo que define a sua
     * continua mandando, porque o wrapper dele é o ancestral mais próximo.
     */
    public static function labelStyle(mixed $color, mixed $size = null, mixed $weight = null, mixed $italic = null): string
    {
        return self::textVars('label', $color, $size, $weight, $italic);
    }

    /**
     * Destaque do CONTROLE do campo (o input/select/textarea): `input-bg`,
     * `input-color`, `input-weight`, `input-italic`.
     *
     * Mesmo mecanismo do labelStyle(): `--mad-input-*` no wrapper, lidas pelas
     * regras de `.mad-input`, `.mad-textarea`, `.mad-sel-control` etc. O select
     * vira `.mad-sel` no JS, então estilo inline no `<select>` nativo se perderia;
     * a var no wrapper sobrevive à troca.
     *
     * Fundo sem cor do texto: a tinta do tema troca no escuro (fica clara) e o
     * fundo escolhido não — um amarelo claro com texto quase branco some. Com
     * fundo hex opaco e sem `input-color`, a tinta vira a de maior contraste com
     * aquele fundo (a escura ou a clara do tema padrão), igual nos dois temas.
     */
    public static function inputStyle(mixed $bg, mixed $color = null, mixed $weight = null, mixed $italic = null): string
    {
        $out = '';

        $b = self::color($bg);
        if ($b !== '') {
            $out .= '--mad-input-bg:' . $b . ';';
        }

        if (self::color($color) === '' && $b !== '') {
            $color = self::contrastInk($b);
        }

        return $out . self::textVars('input', $color, null, $weight, $italic);
    }

    /** Tinta escura e clara do tema padrão (`--mad-text` do claro e do escuro). */
    private const INK_DARK  = '#18181b';
    private const INK_LIGHT = '#fafafa';

    /**
     * Tinta legível sobre um fundo hex OPACO: a de maior contraste WCAG entre
     * INK_DARK e INK_LIGHT. Fundo que não é hex (rgb(), var(), nome) ou que é
     * translúcido devolve `''` — sem saber a cor final, não adivinha.
     */
    public static function contrastInk(string $bg): string
    {
        if (!preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', trim($bg), $m)) {
            return '';
        }
        $h = $m[1];
        if (strlen($h) <= 4) {
            $h = implode('', array_map(static fn ($c) => $c . $c, str_split($h)));
        }
        if (strlen($h) === 8 && strtolower(substr($h, 6, 2)) !== 'ff') {
            return '';
        }

        $lum = static function (string $hex6): float {
            $ch = static function (int $v): float {
                $c = $v / 255;
                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };
            return 0.2126 * $ch(hexdec(substr($hex6, 0, 2)))
                 + 0.7152 * $ch(hexdec(substr($hex6, 2, 2)))
                 + 0.0722 * $ch(hexdec(substr($hex6, 4, 2)));
        };

        $l      = $lum(substr($h, 0, 6));
        $dark   = ($l + 0.05) / ($lum(ltrim(self::INK_DARK, '#')) + 0.05);
        $light  = ($lum(ltrim(self::INK_LIGHT, '#')) + 0.05) / ($l + 0.05);

        return $dark >= $light ? self::INK_DARK : self::INK_LIGHT;
    }

    /** `--mad-{prefix}-color/-size/-weight/-style` — compartilhado por label e input. */
    private static function textVars(string $prefix, mixed $color, mixed $size, mixed $weight, mixed $italic): string
    {
        $out = '';

        $c = self::color($color);
        if ($c !== '') {
            $out .= '--mad-' . $prefix . '-color:' . $c . ';';
        }

        $s = is_scalar($size) ? self::length((string) $size) : '';
        if ($s !== '') {
            $out .= '--mad-' . $prefix . '-size:' . $s . ';';
        }

        $w = is_scalar($weight) ? strtolower(trim((string) $weight)) : '';
        if ($w === 'normal' || $w === 'bold') {
            $out .= '--mad-' . $prefix . '-weight:' . $w . ';';
        }

        if (self::flag($italic)) {
            $out .= '--mad-' . $prefix . '-style:italic;';
        }

        return $out;
    }

    /**
     * Atributo booleano de template: sem valor chega como `true`
     * (`<mad-input-field label-italic>`); `"false"`/`"0"`/`""` desligam.
     */
    private static function flag(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (!is_scalar($v)) {
            return false;
        }

        $v = strtolower(trim((string) $v));

        return $v === 'italic' || filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * UM track de `grid-template-columns` / `grid-template-rows`.
     *
     * Diferença da length(): aceita `fr` e `minmax()`, e o fallback é `1fr` —
     * nunca `''`. Devolver vazio aqui reintroduziria justamente a falha FATAL
     * que motivou o arquivo.
     */
    public static function track(string $v): string
    {
        $v = trim($v);

        if ($v === '') {
            return '1fr';
        }

        if (preg_match('/^\d*\.?\d+fr$/i', $v)) {
            return $v;
        }

        return self::length($v, '1fr');
    }

    /**
     * Template COMPLETO de grid (`"140 1fr 80"` → `"140px 1fr 80px"`).
     *
     * Cada track é normalizado individualmente, então uma largura mal escrita
     * degrada só a própria coluna em vez de invalidar a declaração e empilhar a
     * tela inteira.
     *
     * O split respeita parênteses: `minmax(80px, 1fr)` e `repeat(2, 1fr)` têm
     * espaço DENTRO da função e não podem ser quebrados no meio.
     *
     * Entrada vazia → `''` (o caller decide o default dele).
     */
    public static function template(string $v): string
    {
        $v = trim($v);

        if ($v === '') {
            return '';
        }

        $out = [];
        foreach (self::splitTopLevel($v) as $token) {
            $out[] = self::track($token);
        }

        return implode(' ', $out);
    }

    /** Quebra por espaços que estão FORA de parênteses. */
    private static function splitTopLevel(string $v): array
    {
        $tokens = [];
        $buf    = '';
        $depth  = 0;

        foreach (str_split($v) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r")) {
                if ($buf !== '') {
                    $tokens[] = $buf;
                    $buf      = '';
                }
                continue;
            }

            $buf .= $ch;
        }

        if ($buf !== '') {
            $tokens[] = $buf;
        }

        return $tokens;
    }
}
