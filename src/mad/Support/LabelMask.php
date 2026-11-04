<?php

namespace Mad\Support;

/**
 * LabelMask — monta o RÓTULO de uma opção/registro a partir de uma máscara com
 * `{token}` (`display="{codigo} · {nome}"`) e arruma o que sobra quando um
 * token vem vazio. Regra ÚNICA para os rótulos de combo, busca, auto-fill e
 * chip de filtro.
 *
 * Antes cada lugar trocava o token por '' e deixava o separador: produto sem
 * código aparecia "· Camiseta QA Loja Nova" no combo.
 *
 * Regra, só quando algum token sai vazio (sem token vazio o texto é o de
 * sempre, byte a byte):
 *   - o separador colado ao token vazio some (" · ", " - ", " / ", " | ", ", ");
 *   - parênteses/colchetes que ficaram vazios somem ("Nome ({sigla})" → "Nome");
 *   - espaços repetidos viram um, sem sobra no começo nem no fim.
 *
 * Texto fixo que não é separador continua ("Cód. {codigo}" → "Cód.").
 *
 * NÃO é para máscara de COLUNA de grade (`<mad-col field="{a} {b}">`): lá o
 * texto da célula segue a máscara literal.
 */
final class LabelMask
{
    /** Marca do token vazio durante a arrumação (nunca aparece num valor real). */
    private const HOLE = "\x00";

    /** Caracteres de separador entre tokens (sem ":" — "Cód: {x}" é rótulo, não separador). */
    private const SEP = '\s·•\|\/\\\\\-–—,;';

    /**
     * Troca cada `{token}` por `$resolve(token)` e arruma os separadores dos
     * tokens que vierem vazios.
     *
     * @param  callable(string): (string|int|float|null)  $resolve  recebe o miolo do `{…}`
     * @param  string  $pattern  regex do token; o grupo 1 é o miolo
     */
    public static function render(string $mask, callable $resolve, string $pattern = '/\{(.*?)\}/'): string
    {
        $holes = false;
        $out = (string) preg_replace_callback($pattern, function ($m) use ($resolve, &$holes) {
            $v = $resolve($m[1]);
            $v = $v === null ? '' : (string) $v;
            if (trim($v) === '') {
                $holes = true;

                return self::HOLE;
            }

            return str_replace(self::HOLE, '', $v);
        }, $mask);

        return $holes ? self::tidy($out) : $out;
    }

    /** Arruma um texto com marcas de token vazio (HOLE). */
    private static function tidy(string $s): string
    {
        $h   = self::HOLE;
        $sep = self::SEP;

        // ( vazio ) / [ vazio ] / { vazio } → um buraco só.
        $s = (string) preg_replace('/[\(\[\{]\s*' . $h . '(?:[' . $sep . ']*' . $h . ')*\s*[\)\]\}]/u', $h, $s);
        // Buracos vizinhos (com separadores entre eles) viram um.
        $s = (string) preg_replace('/' . $h . '(?:[' . $sep . ']*' . $h . ')+/u', $h, $s);
        // No começo / no fim: o separador colado vai junto.
        $s = (string) preg_replace('/^\s*' . $h . '[' . $sep . ']*/u', '', $s);
        $s = (string) preg_replace('/[' . $sep . ']*' . $h . '\s*$/u', '', $s);
        // No meio: fica UM dos separadores (o da direita, ou o da esquerda se não houver).
        $s = (string) preg_replace_callback('/([' . $sep . ']*)' . $h . '([' . $sep . ']*)/u', function ($m) {
            return $m[2] !== '' ? $m[2] : $m[1];
        }, $s);
        $s = str_replace($h, '', $s);

        return trim((string) preg_replace('/\s{2,}/u', ' ', $s));
    }
}
