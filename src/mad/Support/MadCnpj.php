<?php

namespace Mad\Support;

/**
 * CNPJ — saneamento, validação e formatação, com suporte ao formato
 * ALFANUMÉRICO.
 *
 * A Receita Federal passou a emitir CNPJ alfanumérico em julho/2026 (IN RFB
 * 2.229/2024): das 14 posições, as 12 primeiras (raiz + ordem) aceitam
 * `[0-9A-Z]` e só os 2 dígitos verificadores continuam numéricos.
 * Ex.: `UK.PVM.E1E/8HI9-96`.
 *
 * Isso transformou `preg_replace('/\D/', '', $cnpj)` — o idioma usado em todo
 * lugar até aqui — num BUG SILENCIOSO: ele apaga as letras, o CNPJ de 14
 * posições vira um número curto e é recusado como inválido. Mesma coisa com
 * validação de DV que faz `(int) $char`: letra vira 0.
 *
 * Fonte ÚNICA do framework para essas três operações. Se você está escrevendo
 * `replace(/\D/)` num contexto de CNPJ, use isto em vez disso.
 *
 * O DV segue o mesmo módulo 11 de sempre; a única mudança é o valor de cada
 * caractere: `ASCII - 48` ('0'→0 … '9'→9, 'A'→17 … 'Z'→42).
 */
final class MadCnpj
{
    /** Remove máscara e uppercase, PRESERVANDO letras. Não valida. */
    public static function sanitize(?string $cnpj): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $cnpj));
    }

    /** Formato (12 alfanuméricos + 2 numéricos) e dígitos verificadores. */
    public static function isValid(?string $cnpj): bool
    {
        $clean = self::sanitize($cnpj);

        if (!preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $clean)) {
            return false;
        }

        // Repetições (00000000000000, AAAAAAAAAAAA00) passam no módulo 11 mas
        // nunca são CNPJ real.
        if (preg_match('/^(.)\1{11}/', substr($clean, 0, 12))) {
            return false;
        }

        return self::checkDigits(substr($clean, 0, 12)) === substr($clean, 12, 2);
    }

    /** Só o formato, sem validar DV — para máscara/UI. */
    public static function hasValidShape(?string $cnpj): bool
    {
        return (bool) preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', self::sanitize($cnpj));
    }

    /** Os 2 dígitos verificadores das 12 primeiras posições ('' se inválido). */
    public static function checkDigits(string $base12): string
    {
        $base12 = self::sanitize($base12);
        if (strlen($base12) !== 12) {
            return '';
        }

        $first  = self::modulo11($base12);
        $second = self::modulo11($base12 . $first);

        return $first . $second;
    }

    /** Formata para exibição: UK.PVM.E1E/8HI9-96 (devolve o cru se não tiver 14). */
    public static function format(?string $cnpj): string
    {
        $c = self::sanitize($cnpj);
        if (strlen($c) !== 14) {
            return $c;
        }

        return substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3)
            . '/' . substr($c, 8, 4) . '-' . substr($c, 12, 2);
    }

    /** Módulo 11 com pesos 2..9 da direita pra esquerda e valor = ASCII-48. */
    private static function modulo11(string $chars): int
    {
        $sum    = 0;
        $weight = 2;

        for ($i = strlen($chars) - 1; $i >= 0; $i--) {
            // ASCII-48: dígitos viram seu valor, letras viram 17..42.
            $sum += (ord($chars[$i]) - 48) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
