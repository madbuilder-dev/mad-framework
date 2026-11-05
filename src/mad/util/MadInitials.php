<?php

namespace Mad\Util;

/**
 * MadInitials — iniciais de um nome para avatar sem foto (fw#571).
 *
 * Uma regra só para os quatro lugares que mostram iniciais: avatar do topo,
 * chat / caixa de mensagens, `<mad-avatar>` e o cartão do organograma. O
 * complemento entre parênteses não conta: "Juliana (vendedora e caixa)" é
 * "J", não "JC"; "Marcos Lima (dono)" é "ML".
 */
final class MadInitials
{
    /**
     * Primeira letra da primeira e da última palavra ("Ana Souza" → "AS").
     * Nome de uma palavra só dá as `$single` primeiras letras dela
     * ("Juliana" → "J" com 1, "JU" com 2). Vazio dá ''.
     */
    public static function of(string $name, int $single = 1): string
    {
        $name  = self::withoutParentheses($name);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '';
        }
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, max(1, $single)));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
    }

    /**
     * Tira o complemento entre parênteses do nome ("Marcos Lima (dono)" →
     * "Marcos Lima"); parêntese sem fechar vai até o fim. Se o nome é SÓ o
     * complemento ("(Caixa 1)"), fica o texto de dentro.
     */
    public static function withoutParentheses(string $name): string
    {
        $clean = trim((string) preg_replace('/\([^)]*(?:\)|$)/u', ' ', $name));

        return $clean !== '' ? $clean : trim(str_replace(['(', ')'], ' ', $name));
    }
}
