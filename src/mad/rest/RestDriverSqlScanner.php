<?php

namespace Mad\Rest;

use RuntimeException;

/**
 * Leitura do TEXTO de um SQL do Driver REST: separa o que o banco lê como
 * código do que é dado (literal, comentário) e conta as instruções.
 *
 * Duas coisas dependem disto:
 *   - a trava de "uma instrução por envio" da chave de ESCRITA
 *     ({@see assertSingleStatement()}), que precisa entender corpo de rotina:
 *     `CREATE TRIGGER|PROCEDURE|FUNCTION ... BEGIN a; b; END` é UMA instrução
 *     para o servidor, com `;` dentro;
 *   - o filtro do modo somente leitura ({@see RestDriverReadOnlyGuard}), que lê
 *     o mesmo texto mascarado.
 *
 * As regras de aspas e comentários são as do engine (as do MySQL não valem no
 * PostgreSQL e vice-versa): `\'` só escapa no MySQL e em `E'...'` do
 * PostgreSQL; `#` só comenta no MySQL; a crase só delimita fora do PostgreSQL;
 * `$tag$...$tag$` só existe no PostgreSQL; `--` no MySQL pede espaço depois.
 *
 * Isto NÃO é barreira de segurança: com a chave de escrita quem envia já pode
 * tudo, e com a chave somente leitura quem garante é o banco
 * ({@see RestDriverReadOnlySession}). Aqui o erro possível é de conveniência
 * (recusar ou deixar passar um texto que o servidor leria de outro jeito).
 */
final class RestDriverSqlScanner
{
    public const MULTI_BLOCKED = 'Múltiplas instruções SQL não são permitidas: envie um comando por vez.';

    public const DELIMITER_BLOCKED = 'DELIMITER é um comando do cliente mysql e não existe aqui: envie o CREATE ... BEGIN ... END inteiro, sem DELIMITER e sem o terminador trocado.';

    /** Objetos cujo CREATE leva um corpo com várias instruções. */
    private const ROUTINE_OBJECTS = ['trigger', 'procedure', 'function', 'event', 'package'];

    /** Até onde procurar o tipo do objeto depois do CREATE (OR REPLACE, DEFINER = ..., TEMP, AGGREGATE...). */
    private const ROUTINE_HEADER_WORDS = 12;

    /** MySQL/MariaDB: `END <palavra>` fecha o bloco aberto por essa palavra. */
    private const MYSQL_END_SUFFIXES = ['if', 'case', 'loop', 'while', 'repeat', 'for'];

    /**
     * Recusa mais de uma instrução no mesmo envio.
     *
     * @param  string  $driver  pgsql | mysql | sqlite (nome do driver PDO)
     */
    public static function assertSingleStatement(string $sql, string $driver): void
    {
        $driver = strtolower($driver);

        if (count(self::statements($sql, $driver)) <= 1) {
            return;
        }

        // Script colado do cliente `mysql`: a causa é o DELIMITER, e dizer só
        // "múltiplas instruções" não aponta a saída.
        if (self::isMysqlFamily($driver) && preg_match('/^\s*delimiter\b/i', self::mask($sql, $driver)) === 1) {
            throw new RuntimeException(self::DELIMITER_BLOCKED);
        }

        throw new RuntimeException(self::MULTI_BLOCKED);
    }

    /**
     * As instruções do texto, na ordem, sem as vazias (`;;`, só comentário).
     *
     * @return list<string>
     */
    public static function statements(string $sql, string $driver): array
    {
        $driver = strtolower($driver);
        $code = self::mask($sql, $driver);
        $mysql = self::isMysqlFamily($driver);

        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_$]*|[;()]/', $code, $found, PREG_OFFSET_CAPTURE) === false) {
            return [$sql]; // PCRE falhou: uma instrução só, o banco decide
        }
        $tokens = $found[0];
        $count = count($tokens);

        $statements = [];
        $start = 0;          // onde começa a instrução corrente
        $paren = 0;          // `;` dentro de parênteses nunca separa (regra com vários comandos do PostgreSQL)
        $block = 0;          // BEGIN/CASE... abertos dentro de um corpo de rotina
        $words = 0;          // palavras já lidas na instrução corrente
        $create = false;     // a instrução começa com CREATE
        $routine = false;    // ...e cria um objeto com corpo (ou é BEGIN NOT ATOMIC)

        $close = static function (int $end) use (&$statements, &$start, $sql, $code): void {
            if (trim(substr($code, $start, $end - $start)) !== '') {
                $statements[] = trim(substr($sql, $start, $end - $start));
            }
            $start = $end + 1;
        };

        for ($i = 0; $i < $count; $i++) {
            [$token, $offset] = $tokens[$i];

            if ($token === '(') {
                $paren++;

                continue;
            }
            if ($token === ')') {
                $paren = max(0, $paren - 1);

                continue;
            }
            if ($token === ';') {
                if ($paren === 0 && $block === 0) {
                    $close($offset);
                    $words = 0;
                    $create = $routine = false;
                }

                continue;
            }

            $word = strtolower($token);
            $words++;

            if ($words === 1) {
                $create = $word === 'create';
                // MariaDB: bloco anônimo `BEGIN NOT ATOMIC ... END`.
                if ($mysql && $word === 'begin'
                    && self::wordAt($tokens, $i + 1) === 'not' && self::wordAt($tokens, $i + 2) === 'atomic') {
                    $routine = true;
                }
            } elseif ($create && ! $routine && $words <= self::ROUTINE_HEADER_WORDS
                && in_array($word, self::ROUTINE_OBJECTS, true)) {
                $routine = true;

                continue; // o tipo do objeto não abre bloco
            }

            // Fora de um CREATE de rotina, BEGIN é só o início de uma transação.
            if (! $routine) {
                continue;
            }

            if ($word === 'end') {
                $block = max(0, $block - 1);
                // `END IF`, `END LOOP`...: a palavra seguinte faz parte do fechamento.
                if ($mysql && in_array(self::wordAt($tokens, $i + 1), self::MYSQL_END_SUFFIXES, true)) {
                    $i++;
                }

                continue;
            }

            if ($word === 'begin' || $word === 'case' || ($mysql && self::opensMysqlBlock($tokens, $i, $word))) {
                $block++;
            }
        }

        $close(strlen($sql));

        return $statements;
    }

    /**
     * Blocos de controle do MySQL/MariaDB que levam `;` por dentro sem BEGIN:
     * `IF ... THEN ...; END IF`, `LOOP`, `WHILE ... DO`, `REPEAT ... UNTIL`,
     * `FOR x IN ... DO` (MariaDB). O corpo de uma rotina pode ser um deles
     * direto (`FOR EACH ROW IF ... END IF`).
     *
     * Na dúvida conta como bloco: um bloco a mais só faz o resto do texto
     * seguir como UMA instrução (o servidor decide); um a menos recusaria uma
     * rotina legítima.
     *
     * @param  list<array{0:string,1:int}>  $tokens
     */
    private static function opensMysqlBlock(array $tokens, int $i, string $word): bool
    {
        return match ($word) {
            'loop', 'while' => true,
            // REPEAT('x', 3) é função; o bloco é seguido de uma instrução.
            'repeat' => ($tokens[$i + 1][0] ?? '') !== '(',
            // `FOR EACH ROW`, `FOR UPDATE`, `CURSOR FOR SELECT` não abrem nada.
            'for' => self::wordAt($tokens, $i + 2) === 'in',
            // IF(a, b, c) e `IF [NOT] EXISTS nome` não abrem; `IF cond THEN` sim —
            // e a condição nunca tem `;`, então o THEN vem antes do próximo.
            'if' => ! self::isExistsClause($tokens, $i) && self::thenBeforeSemicolon($tokens, $i),
            default => false,
        };
    }

    /**
     * `IF EXISTS nome` / `IF NOT EXISTS nome` de um DDL (e não a condição
     * `IF EXISTS (SELECT ...) THEN`, que abre bloco).
     *
     * @param  list<array{0:string,1:int}>  $tokens
     */
    private static function isExistsClause(array $tokens, int $i): bool
    {
        $j = self::wordAt($tokens, $i + 1) === 'not' ? $i + 2 : $i + 1;

        return self::wordAt($tokens, $j) === 'exists' && ($tokens[$j + 1][0] ?? '') !== '(';
    }

    /** @param  list<array{0:string,1:int}>  $tokens */
    private static function thenBeforeSemicolon(array $tokens, int $i): bool
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if ($tokens[$j][0] === ';') {
                return false;
            }
            if (strtolower($tokens[$j][0]) === 'then') {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0:string,1:int}>  $tokens */
    private static function wordAt(array $tokens, int $i): string
    {
        return strtolower($tokens[$i][0] ?? '');
    }

    /** Driver desconhecido lê como MySQL (o mais permissivo com `\` e `#`). */
    private static function isMysqlFamily(string $driver): bool
    {
        return $driver !== 'pgsql' && $driver !== 'sqlite';
    }

    // ── Máscara: o que sobra é só o que o engine lê como CÓDIGO ──────────────

    /**
     * Devolve o SQL do mesmo tamanho, com o conteúdo de literais e comentários
     * trocado por espaço.
     *
     * @param  bool|null  $backslashEverywhere  `\` escapa dentro de qualquer string. null = padrão do
     *                                          engine (MySQL sim; PostgreSQL só em E'...'; SQLite nunca)
     * @param  bool  $keepIdentifiers  mantém o nome dos identificadores entre aspas (só as aspas somem)
     * @param  bool  $unicodeEscape  (saída) o texto usa a forma `U&"..."` / `U&'...'` do PostgreSQL
     */
    public static function mask(
        string $sql,
        string $driver,
        ?bool $backslashEverywhere = null,
        bool $keepIdentifiers = false,
        bool &$unicodeEscape = false,
    ): string {
        $driver = strtolower($driver);
        $pg = $driver === 'pgsql';
        $mysql = self::isMysqlFamily($driver);
        $backslashEverywhere ??= $mysql;
        $len = strlen($sql);
        $out = $sql;

        $blank = static function (int $from, int $to) use (&$out, $len): void {
            for ($k = $from; $k <= $to && $k < $len; $k++) {
                if (! ctype_space($out[$k])) {
                    $out[$k] = ' ';
                }
            }
        };

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // PostgreSQL: identificador (U&"...") ou texto (U&'...') com escape
            // Unicode — `U&"dblink\005fexec"` é `dblink_exec`. A máscara não
            // decodifica; só avisa quem chamou (o modo somente leitura recusa).
            if ($pg && ($ch === 'u' || $ch === 'U') && $next === '&'
                && (($sql[$i + 2] ?? '') === '"' || ($sql[$i + 2] ?? '') === "'")
                && ($i === 0 || ! self::isIdentifierChar($sql[$i - 1]))) {
                $unicodeEscape = true;
            }

            // -- comentário de linha (no MySQL só com espaço/controle depois do 2º traço)
            if ($ch === '-' && $next === '-') {
                $after = $sql[$i + 2] ?? '';
                if (! $mysql || $after === '' || ctype_space($after) || ctype_cntrl($after)) {
                    $end = self::lineEnd($sql, $i);
                    $blank($i, $end);
                    $i = $end;

                    continue;
                }
            }
            // # comentário de linha (só MySQL; no PostgreSQL `#` é operador)
            if ($ch === '#' && $mysql) {
                $end = self::lineEnd($sql, $i);
                $blank($i, $end);
                $i = $end;

                continue;
            }
            // /* comentário de bloco */ (aninha só no PostgreSQL)
            if ($ch === '/' && $next === '*') {
                $end = self::blockCommentEnd($sql, $i, $pg);
                $blank($i, $end);
                $i = $end;

                continue;
            }
            // 'string'
            if ($ch === "'") {
                $end = self::quotedEnd($sql, $i, "'", $backslashEverywhere || ($pg && self::isEscapeStringPrefix($sql, $i)));
                $blank($i + 1, $end - 1);
                $i = $end;

                continue;
            }
            // "identificador" (PostgreSQL/SQLite), "string" (MySQL) e `identificador`
            // (MySQL/SQLite; no PostgreSQL a crase não delimita nada)
            if ($ch === '"' || ($ch === '`' && ! $pg)) {
                $isString = $ch === '"' && $mysql;
                $end = self::quotedEnd($sql, $i, $ch, $isString && $backslashEverywhere);
                if ($keepIdentifiers && ! $isString) {
                    $blank($i, $i);       // some só a aspa: o nome continua legível
                    $blank($end, $end);
                } else {
                    $blank($i, $end);
                }
                $i = $end;

                continue;
            }
            // $tag$ string $tag$ (só PostgreSQL, e só se o `$` não continua um identificador)
            if ($ch === '$' && $pg && ($i === 0 || ! self::isIdentifierChar($sql[$i - 1]))
                && preg_match('/\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/A', $sql, $m, 0, $i)) {
                $bodyStart = $i + strlen($m[0]);
                $close = strpos($sql, $m[0], $bodyStart);
                $end = $close === false ? $len - 1 : $close + strlen($m[0]) - 1;
                $blank($i, $end);
                $i = $end;

                continue;
            }
        }

        return $out;
    }

    private static function isIdentifierChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80;
    }

    /** A aspa em $i abre uma E'string' do PostgreSQL (a única em que `\` escapa por padrão)? */
    private static function isEscapeStringPrefix(string $sql, int $i): bool
    {
        return $i >= 1
            && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e')
            && ($i === 1 || ! self::isIdentifierChar($sql[$i - 2]));
    }

    /** Índice do último caractere da linha que começa em $i. */
    private static function lineEnd(string $sql, int $i): int
    {
        $nl = strpos($sql, "\n", $i);

        return $nl === false ? strlen($sql) - 1 : $nl;
    }

    /** Índice do `/` que fecha o comentário aberto em $i (ou o fim do texto). */
    private static function blockCommentEnd(string $sql, int $i, bool $nested): int
    {
        $len = strlen($sql);
        $depth = 1;
        for ($j = $i + 2; $j < $len - 1; $j++) {
            if ($nested && $sql[$j] === '/' && $sql[$j + 1] === '*') {
                $depth++;
                $j++;
            } elseif ($sql[$j] === '*' && $sql[$j + 1] === '/') {
                $depth--;
                $j++;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $len - 1; // não fechou: o resto é comentário
    }

    /** Índice da aspa que fecha o literal aberto em $i (ou o tamanho do texto, se não fecha). */
    private static function quotedEnd(string $sql, int $i, string $quote, bool $backslash): int
    {
        $len = strlen($sql);
        for ($j = $i + 1; $j < $len; $j++) {
            $c = $sql[$j];
            if ($backslash && $c === '\\') {
                $j++; // pula o caractere escapado

                continue;
            }
            if ($c === $quote) {
                if (($sql[$j + 1] ?? '') === $quote) { // aspa dobrada = aspa dentro do literal
                    $j++;

                    continue;
                }

                return $j;
            }
        }

        return $len; // não fechou: o resto é literal
    }
}
