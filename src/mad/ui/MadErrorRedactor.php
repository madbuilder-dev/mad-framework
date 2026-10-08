<?php

namespace Mad\Ui;

/**
 * MadErrorRedactor — o detalhe técnico de um erro SEM os segredos.
 *
 * Por que existe: a mensagem de uma `QueryException` do Laravel traz o SQL com
 * os valores já no lugar dos `?`. Num INSERT de usuário isso é o HASH DA SENHA
 * (e token, segredo de 2º fator, chave de API…). Quem gravava essa mensagem no
 * log — ou a mostrava ao desenvolvedor com `APP_DEBUG` ligado — copiava o
 * segredo para um arquivo que vive muito mais do que a requisição. O stack
 * trace tem o mesmo problema por outro caminho: `getTraceAsString()` escreve os
 * ARGUMENTOS de cada chamada, e a senha digitada no login é argumento de três
 * delas.
 *
 * Regras:
 *  • valor de coluna sensível (senha, token, segredo, chave de API, hash) nunca
 *    é escrito — vira `••••`. A coluna é achada pelo SQL (`insert … (colunas)
 *    values (?, ?)`, `set coluna = ?`, `where coluna = ?`) e o critério é o
 *    mesmo do log SQL (`Mad\Security\SqlLogMasker::isSecretColumn()`);
 *  • o que TEM CARA de hash de senha (bcrypt, argon2, crypt) vira `••••` em
 *    qualquer texto, mesmo sem coluna para dizer o que é;
 *  • a linha inteira que o PostgreSQL anexa a um erro ("Failing row contains
 *    (…)") é cortada: ela traz todas as colunas do registro;
 *  • o trace sai sem argumento nenhum (arquivo, linha e função).
 *
 * Nada aqui decide o que o USUÁRIO vê — isso é do `MadUserError`. Este texto é
 * para o log e para as telas do desenvolvedor.
 */
final class MadErrorRedactor
{
    /** A mesma máscara do log de requisições e do log SQL. */
    public const MASK = \Mad\Security\SecretMasker::MASK;

    /**
     * Trechos de nome de coluna que marcam segredo ALÉM do critério comum
     * (`SqlLogMasker::isSecretColumn()`: senha, token, segredo, chave de API,
     * 2º fator, sessão, cartão…).
     */
    private const EXTRA_SENSITIVE = ['hash', 'salt'];

    /** Tamanho máximo de um valor de texto escrito por inteiro. */
    private const MAX_VALUE = 120;

    /** Hash de senha em qualquer lugar do texto: bcrypt, argon2 e os `crypt()` clássicos. */
    private const HASH_PATTERNS = [
        '/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}/',
        '/\$argon2(?:id|i|d)\$[A-Za-z0-9+\/=,$.\-]{20,}/',
        '/\$(?:1|5|6|7|y|sha1|scrypt|pbkdf2(?:-[a-z0-9]+)?)\$[A-Za-z0-9.\/+=,$\-]{16,}/',
    ];

    /** Classe e mensagem da exceção, sem segredo. Uma linha. */
    public static function describe(\Throwable $e): string
    {
        return get_class($e) . ': ' . self::message($e);
    }

    /**
     * Mensagem da exceção sem segredo.
     *
     * Para erro de banco a mensagem é REMONTADA a partir das partes (texto do
     * driver + conexão + SQL com os valores mascarados), em vez de tentar
     * limpar a que o Laravel já montou — ali o valor está solto no meio do SQL
     * e não há como saber de qual coluna ele é.
     */
    public static function message(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            $driver = $e->getPrevious() instanceof \Throwable
                ? $e->getPrevious()->getMessage()
                : self::beforeConnectionDetails($e->getMessage());

            return self::text($driver)
                . ' (Connection: ' . (string) $e->getConnectionName()
                . ', SQL: ' . self::sql((string) $e->getSql(), (array) $e->getBindings()) . ')';
        }

        return self::text($e->getMessage());
    }

    /**
     * Texto livre (mensagem de driver, saída de comando) sem segredo.
     */
    public static function text(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // PostgreSQL: "DETAIL:  Failing row contains (1, admin, $2y$12$…, …)."
        $text = preg_replace('/Failing row contains \(.*?\)\.?(?=\s*(?:\(Connection:|\n|$))/s', 'Failing row contains (' . self::MASK . ').', $text) ?? $text;

        // SQL que JÁ veio com os valores no lugar dos `?` (mensagem de uma
        // QueryException copiada para dentro de outra exceção): não há como
        // saber de qual coluna é cada valor — fica só o começo da instrução.
        $text = preg_replace_callback('/(\(Connection: [^()]*?, SQL: )(.*)\)/s', static function (array $m): string {
            return $m[1] . self::statementHead($m[2]) . ')';
        }, $text) ?? $text;

        // PostgreSQL: "Key (token)=(abc) already exists." — só quando a coluna é sensível.
        $text = preg_replace_callback('/Key \(([^)]*)\)=\((.*?)\)(?= (?:already exists|is not present|is still referenced|conflicts))/s', static function (array $m): string {
            foreach (explode(',', $m[1]) as $column) {
                if (self::isSensitiveColumn($column)) {
                    return 'Key (' . $m[1] . ')=(' . self::MASK . ')';
                }
            }

            return $m[0];
        }, $text) ?? $text;

        // MySQL/MariaDB: "Duplicate entry 'abc' for key 'usuario.usuario_token_unique'".
        $text = preg_replace_callback("/Duplicate entry '(.*?)' for key '([^']*)'/s", static function (array $m): string {
            return self::isSensitiveColumn($m[2])
                ? "Duplicate entry '" . self::MASK . "' for key '" . $m[2] . "'"
                : $m[0];
        }, $text) ?? $text;

        foreach (self::HASH_PATTERNS as $pattern) {
            $text = preg_replace($pattern, self::MASK, $text) ?? $text;
        }

        // Credencial em DSN, URL e par chave=valor: "mysql://app:segredo@host", "password=segredo".
        $text = preg_replace('/([a-z][a-z0-9+.\-]*:\/\/[^\s:\/@]+):[^\s@\/]+@/i', '$1:' . self::MASK . '@', $text) ?? $text;
        $text = preg_replace(
            '/\b(password|passwd|pwd|senha|secret|client_secret|api[_-]?key|access[_-]?token|token)\b(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&)\]]+)/i',
            '$1$2' . self::MASK,
            $text
        ) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/\-]{12,}=*/', '$1 ' . self::MASK, $text) ?? $text;

        return $text;
    }

    /**
     * SQL com os valores no lugar dos `?`, e a máscara no lugar do valor de toda
     * coluna sensível.
     *
     * @param array<int|string,mixed> $bindings
     */
    public static function sql(string $sql, array $bindings): string
    {
        $bindings = array_values($bindings);
        if ($bindings === []) {
            return self::text($sql);
        }

        $insertColumns = self::insertColumns($sql);
        $valuesAt      = $insertColumns === null ? null : self::valuesKeywordPosition($sql);

        $out       = '';
        $index     = 0;      // qual binding
        $quote     = null;   // dentro de '…', "…" ou `…`
        $depth     = 0;      // parênteses depois do VALUES
        $tuplePos  = 0;      // posição dentro da tupla do VALUES
        $length    = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $out .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out  .= $char;
                continue;
            }

            if ($valuesAt !== null && $i >= $valuesAt) {
                if ($char === '(') {
                    $depth++;
                    if ($depth === 1) {
                        $tuplePos = 0;
                    }
                } elseif ($char === ')') {
                    $depth = max(0, $depth - 1);
                } elseif ($char === ',' && $depth === 1) {
                    $tuplePos++;
                }
            }

            if ($char !== '?' || ! array_key_exists($index, $bindings)) {
                $out .= $char;
                continue;
            }

            $column = ($valuesAt !== null && $i >= $valuesAt && $depth === 1)
                ? ($insertColumns[$tuplePos] ?? null)
                : self::columnBefore(substr($sql, 0, $i));

            $out .= self::value($bindings[$index], $column);
            $index++;
        }

        return self::text($out);
    }

    /**
     * Stack trace SEM argumentos: `#3 app/control/Iam/UserForm.php:227 App\Control\Iam\UserForm->onSave()`.
     *
     * `getTraceAsString()` escreve os argumentos (os 15 primeiros caracteres de
     * cada texto) — e a senha digitada é argumento de quem autentica.
     */
    public static function trace(\Throwable $e, int $limit = 40): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $frame) {
            if ($i >= $limit) {
                $lines[] = '#' . $i . ' …';
                break;
            }
            $where = isset($frame['file'])
                ? self::relativePath((string) $frame['file']) . ':' . (int) ($frame['line'] ?? 0)
                : '[internal]';
            $call  = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '') . '()';
            $lines[] = '#' . $i . ' ' . $where . ' ' . $call;
        }

        return implode("\n", $lines);
    }

    /** Caminho relativo à raiz do projeto (o log não precisa da árvore do servidor). */
    public static function relativePath(string $path): string
    {
        try {
            $base = function_exists('base_path') ? rtrim((string) base_path(), '/\\') : '';
        } catch (\Throwable) {
            $base = '';
        }
        if ($base !== '' && str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/\\');
        }

        return $path;
    }

    /**
     * O nome da coluna (ou do índice) é de dado que não se escreve?
     *
     * Mesmo critério do log SQL e do log de requisições, para os três logs
     * esconderem as mesmas colunas.
     */
    public static function isSensitiveColumn(?string $column): bool
    {
        $column = strtolower(trim((string) $column, " \t\"`'[]"));
        if ($column === '') {
            return false;
        }
        if (\Mad\Security\SqlLogMasker::isSecretColumn($column)) {
            return true;
        }
        foreach (self::EXTRA_SENSITIVE as $needle) {
            if (str_contains($column, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ── Internos ────────────────────────────────────────────────────────

    /** Valor de um binding como texto de log. */
    private static function value(mixed $value, ?string $column): string
    {
        if ($column !== null && self::isSensitiveColumn($column)) {
            return self::MASK;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return "'" . $value->format('Y-m-d H:i:s') . "'";
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return '[' . get_debug_type($value) . ']';
        }

        $text = (string) $value;
        // Binário (BLOB) não é texto de log.
        if ($text !== '' && ! mb_check_encoding($text, 'UTF-8')) {
            return '[binário ' . strlen($text) . ' bytes]';
        }
        if (mb_strlen($text) > self::MAX_VALUE) {
            $text = mb_substr($text, 0, self::MAX_VALUE) . '…';
        }

        return "'" . str_replace("'", "''", self::text($text)) . "'";
    }

    /**
     * Colunas de um `insert into t (a, b, c) values …` — null quando não é INSERT
     * com lista de colunas.
     *
     * @return list<string>|null
     */
    private static function insertColumns(string $sql): ?array
    {
        if (! preg_match('/^\s*(?:insert|replace)\b.*?\binto\s+[^\s(]+\s*\(([^)]*)\)\s*values\b/is', $sql, $m)) {
            return null;
        }

        return array_values(array_map(
            static fn (string $c): string => trim($c, " \t\n\r\"`'[]"),
            explode(',', $m[1]),
        ));
    }

    /** Posição logo depois do VALUES de um INSERT. */
    private static function valuesKeywordPosition(string $sql): ?int
    {
        if (preg_match('/\)\s*values\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            return $m[0][1] + strlen($m[0][0]);
        }

        return null;
    }

    /**
     * Coluna comparada/atribuída ao `?` que vem logo depois de $before:
     * `"password" = `, `t.token <> `, `lower("email") like `, `"token" in (?, `.
     */
    private static function columnBefore(string $before): ?string
    {
        $tail = substr($before, -200);
        $ident = '[`"\[]?([A-Za-z_][A-Za-z0-9_$]*)[`"\]]?';
        $ops   = '(?:=|<>|!=|<=|>=|<|>|\b(?:not\s+)?i?like\b|\b(?:not\s+)?in\s*\((?:\s*\?\s*,)*)';

        if (preg_match('/' . $ident . '\s*\)*\s*' . $ops . '\s*$/i', $tail, $m)) {
            return $m[1];
        }

        return null;
    }

    /** `insert into "t"`, `update "t"`, `delete from "t"`, `select …` — sem coluna e sem valor. */
    private static function statementHead(string $sql): string
    {
        $sql = trim($sql);
        if (preg_match('/^\s*((?:insert|replace)\b.*?\binto\s+[^\s(]+|update\s+[^\s(]+|delete\s+from\s+[^\s(]+)/is', $sql, $m)) {
            return $m[1] . ' …';
        }

        return (preg_match('/^\s*([a-z]+)/i', $sql, $m) ? $m[1] : '') . ' …';
    }

    /** O que vem antes do " (Connection: …" que o Laravel anexa. */
    private static function beforeConnectionDetails(string $message): string
    {
        $pos = strpos($message, ' (Connection: ');

        return $pos === false ? $message : substr($message, 0, $pos);
    }
}
