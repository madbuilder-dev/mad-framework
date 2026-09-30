<?php

namespace Mad\Database;

/**
 * OrderGuard
 *
 * Defesa anti SQL-injection para identificadores que o PDO não consegue bind
 * como `:par_N`. Fonte ÚNICA de verdade, desacoplada do motor SQL antigo.
 *
 *   - isSafeOrderBy()/validate()  → cláusulas ORDER BY / GROUP BY (grid, MadRecordService).
 *   - isSafeExpression()/validateExpression() → expressões de SELECT/GROUP BY raw
 *     (charts: selectRaw/groupByRaw com coluna ou função interpolada).
 *
 * Mantém o comportamento fail-closed (lança exceção) e o log
 * `[SQL-INJECTION-GUARD]` server-side.
 */
final class OrderGuard
{
    /**
     * Whitelist de uma cláusula ORDER BY / GROUP BY (somente identificadores).
     *
     * Aceita por termo (separado por vírgula):
     *   - inteiro (posição GROUP BY): 3
     *   - identificador: col, col_name
     *   - qualificado: table.col
     *   - alias entre aspas duplas: "alias"
     *   - agregado de 1 arg: SUM(col) | COUNT(*) | AVG("x") | MIN(t.c) | MAX(col)
     *   - direção opcional ao final: ASC | DESC (case-insensitive)
     */
    public static function isSafeOrderBy($clause): bool
    {
        $clause = trim((string) $clause);
        if ($clause === '')
        {
            return TRUE;
        }

        $ident  = '[A-Za-z_][A-Za-z0-9_]*';
        $colref = "(?:{$ident}(?:\\.{$ident})?)";
        $quoted = '"[^"\\\\;]+"';
        $func   = "(?:SUM|COUNT|AVG|MIN|MAX)\\s*\\(\\s*(?:\\*|{$colref}|{$quoted})\\s*\\)";
        $expr   = "(?:\\d+|{$quoted}|{$func}|{$colref})";
        $term   = "/^\\s*{$expr}(?:\\s+(?:ASC|DESC))?\\s*$/i";

        foreach (explode(',', $clause) as $part)
        {
            if (!preg_match($term, $part))
            {
                return FALSE;
            }
        }
        return TRUE;
    }

    /**
     * Valida ORDER BY / GROUP BY no sink. Fail-closed: loga o payload cru
     * (single-line, sem ecoar na exceção) e lança.
     *
     * @param string $clause  Cláusula a validar.
     * @param string $context Rótulo p/ log/exceção ('ORDER BY' | 'GROUP BY').
     * @throws \Exception Quando a cláusula não casa com a gramática permitida.
     */
    public static function validate($clause, $context): void
    {
        if (self::isSafeOrderBy($clause))
        {
            return;
        }

        @error_log('[SQL-INJECTION-GUARD] ' . $context . ' blocked: '
            . str_replace(array("\r", "\n"), ' ', (string) $clause));
        throw new \Exception("Invalid {$context} clause (blocked by SQL injection guard)");
    }

    /**
     * Whitelist de uma EXPRESSÃO de coluna usada em SELECT/GROUP BY raw (sink dos
     * charts: `selectRaw`/`groupByRaw` com identificador interpolado, que o PDO
     * não consegue bind). Mais permissiva que isSafeOrderBy() pois aceita funções
     * com múltiplos argumentos (ex.: `strftime('%Y-%m', created_at)`, `DATE(col)`),
     * mas continua fechada contra `;`, comentários e statement-breakers.
     *
     * Aceita (1 expressão, sem direção):
     *   - coluna: col | table.col
     *   - função: FUNC(arg[, arg...]) com arg ∈ { * | col | 'literal' | número }
     * Vazio NÃO é válido (SELECT/GROUP precisam de expressão real).
     */
    public static function isSafeExpression($expr): bool
    {
        $expr = trim((string) $expr);
        if ($expr === '')
        {
            return FALSE;
        }

        $ident  = '[A-Za-z_][A-Za-z0-9_]*';
        $colref = "(?:{$ident}(?:\\.{$ident})?)";
        $quoted = "'[^'\\\\;]*'";
        $num    = '\\d+(?:\\.\\d+)?';
        $arg    = "(?:\\*|{$colref}|{$quoted}|{$num})";
        $args   = "{$arg}(?:\\s*,\\s*{$arg})*";
        $func   = "{$ident}\\s*\\(\\s*(?:{$args})?\\s*\\)";

        return (bool) preg_match("/^(?:{$func}|{$colref})$/", $expr);
    }

    /**
     * Valida uma expressão de SELECT/GROUP BY no sink. Fail-closed: loga e lança.
     *
     * @param string $expr    Expressão a validar.
     * @param string $context Rótulo p/ log/exceção (ex.: 'chart field-group').
     * @throws \Exception Quando a expressão não casa com a gramática permitida.
     */
    public static function validateExpression($expr, $context): void
    {
        if (self::isSafeExpression($expr))
        {
            return;
        }

        @error_log('[SQL-INJECTION-GUARD] ' . $context . ' blocked: '
            . str_replace(array("\r", "\n"), ' ', (string) $expr));
        throw new \Exception("Invalid {$context} expression (blocked by SQL injection guard)");
    }
}
