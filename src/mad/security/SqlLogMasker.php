<?php

namespace Mad\Security;

use Illuminate\Database\Events\QueryExecuted;

/**
 * SqlLogMasker — o que o log SQL opcional (`mad.trace.sql_log` → mad_log_sql)
 * pode gravar de uma instrução INSERT/UPDATE/DELETE.
 *
 * Até o 5.97.0 o writer gravava `QueryExecuted::toRawSql()` inteiro: trocar a
 * senha levava o hash para o log (`update "mad_iam_user" set "password" = '$2y$…'`)
 * e configurar o 2FA levava o SEGREDO em texto (`two_factor_secret`), o mesmo
 * para tokens do Copilot/MCP, sessões e chaves dos provedores de pagamento.
 *
 * Duas regras:
 *  1. instrução sobre tabela de {@see ProtectedData} (credenciais, tabelas
 *     privadas por pessoa, catálogos) NÃO é gravada — alvo da escrita ou
 *     relação citada depois de FROM/JOIN/INTO/UPDATE (`insert … select … from
 *     mad_iam_user`). Alvo que não dá para identificar: vale qualquer nome
 *     protegido citado no texto (conservador);
 *  2. nas demais, o VALOR de coluna de segredo sai como {@see SecretMasker::MASK}
 *     — por binding (`"api_key" = ?`, `in (?, ?)`, posição na lista do INSERT
 *     e binding nomeado) e por literal escrito no texto (`"token" = 'abc'`). O
 *     nome da coluna segue no log: a auditoria continua vendo QUE o campo mudou.
 *
 * Mesmo critério de coluna do log de requisições: {@see SecretMasker::isSecretKey()}
 * + {@see ProtectedData::isSecretColumn()}.
 */
final class SqlLogMasker
{
    /** Identificador: "aspas" (com "" escapado), `crase`, [colchetes] ou palavra. */
    private const IDENT = '(?:"(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)';

    /**
     * Texto a gravar em mad_log_sql, ou null quando a instrução não deve ir
     * para o log.
     */
    public static function rawSqlFor(QueryExecuted $q): ?string
    {
        $sql = (string) $q->sql;
        if (self::touchesProtectedData($sql)) {
            return null;
        }

        $bindings = self::maskBindings($sql, (array) $q->bindings);
        $sql = self::maskInlineLiterals($sql);

        return $q->connection->query()->getGrammar()
            ->substituteBindingsIntoRawSql($sql, $q->connection->prepareBindings($bindings));
    }

    /** A instrução escreve em (ou lê de) tabela protegida? */
    public static function touchesProtectedData(string $sql): bool
    {
        $relations = self::relations($sql);
        if ($relations === []) {
            foreach (ProtectedData::mentionedNames($sql) as $name) {
                if (ProtectedData::isProtectedTable(self::lastSegment($name))) {
                    return true;
                }
            }

            return false;
        }
        foreach ($relations as $rel) {
            if (ProtectedData::isProtectedTable($rel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relações citadas depois de FROM / JOIN / INTO / UPDATE (último segmento do
     * nome qualificado, sem aspas, minúsculas).
     *
     * @return list<string>
     */
    public static function relations(string $sql): array
    {
        $re = '/\b(?:from|join|into|update)\s+(' . self::IDENT . '(?:\s*\.\s*' . self::IDENT . ')*)/i';
        if (! preg_match_all($re, $sql, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $qualified) {
            $out[self::lastSegment($qualified)] = true;
        }

        return array_map('strval', array_keys($out));
    }

    public static function isSecretColumn(string $column): bool
    {
        $column = self::unquote($column);

        return $column !== '' && (SecretMasker::isSecretKey($column) || ProtectedData::isSecretColumn($column));
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    private static function maskBindings(string $sql, array $bindings): array
    {
        if ($bindings === []) {
            return $bindings;
        }

        // Binding nomeado (`:api_key`): o nome já diz o que é.
        foreach ($bindings as $key => $value) {
            if (is_string($key) && self::isSecretColumn(ltrim($key, ':'))) {
                $bindings[$key] = SecretMasker::MASK;
            }
        }

        $positions = self::placeholderOffsets($sql);
        $insertColumns = self::insertColumns($sql);
        $keys = array_keys($bindings);

        foreach ($positions as $index => $offset) {
            if (! array_key_exists($index, $keys) || ! is_int($keys[$index])) {
                continue;
            }
            $column = null;
            if ($insertColumns !== null && $offset > $insertColumns['values_at']) {
                $n = count($insertColumns['columns']);
                $column = $n > 0 ? $insertColumns['columns'][$index % $n] : null;
            }
            $column ??= self::columnBefore(substr($sql, 0, $offset));
            if ($column !== null && self::isSecretColumn($column)) {
                $bindings[$keys[$index]] = SecretMasker::MASK;
            }
        }

        return $bindings;
    }

    /**
     * Offsets dos `?` que o Grammar::substituteBindingsIntoRawSql trocaria — a
     * MESMA varredura (pula `\'`, `''`, `??` e o conteúdo de 'literal'), para o
     * índice do binding casar com o dele.
     *
     * @return list<int>
     */
    private static function placeholderOffsets(string $sql): array
    {
        $out = [];
        $inLiteral = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $pair = $sql[$i] . ($sql[$i + 1] ?? '');
            if (in_array($pair, ["\\'", "''", '??'], true)) {
                $i++;
            } elseif ($sql[$i] === "'") {
                $inLiteral = ! $inLiteral;
            } elseif ($sql[$i] === '?' && ! $inLiteral) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * `insert into t ("a", "b") values (?, ?), (?, ?)` → colunas na ordem + onde
     * começa o VALUES (os `?` depois dele mapeiam por posição, em ciclo).
     *
     * @return array{columns: list<string>, values_at: int}|null
     */
    private static function insertColumns(string $sql): ?array
    {
        $re = '/^\s*insert\s+(?:or\s+\w+\s+|ignore\s+)?into\s+' . self::IDENT . '(?:\s*\.\s*' . self::IDENT . ')*\s*\(((?:[^()"`\[]|' . self::IDENT . ')*)\)\s*values\b/i';
        if (! preg_match($re, $sql, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        preg_match_all('/' . self::IDENT . '/', $m[1][0], $cols);

        return [
            'columns' => array_map(fn ($c) => self::unquote($c), $cols[0]),
            'values_at' => $m[0][1] + strlen($m[0][0]),
        ];
    }

    /**
     * Coluna comparada/atribuída logo antes do `?`: `"col" = ?`, `t.col <> ?`,
     * `col like ?`, `col in (?, ?, ?`.
     */
    private static function columnBefore(string $prefix): ?string
    {
        $tail = substr($prefix, -400);
        $re = '/(' . self::IDENT . ')\s*(?:=|==|<>|!=|<=|>=|<|>|\bnot\s+like\b|\blike\b|\bilike\b|\bnot\s+in\s*\(|\bin\s*\()(?:\s*\?\s*,)*\s*$/i';
        if (! preg_match($re, $tail, $m)) {
            return null;
        }

        return self::unquote($m[1]);
    }

    /** `"token" = 'abc'` escrito no texto (sem binding) → `"token" = '••••'`. */
    private static function maskInlineLiterals(string $sql): string
    {
        $re = '/(' . self::IDENT . ')(\s*(?:=|<>|!=)\s*)\'((?:[^\'\\\\]|\'\'|\\\\.)*)\'/';

        return (string) preg_replace_callback($re, function (array $m) {
            if (! self::isSecretColumn($m[1])) {
                return $m[0];
            }

            return $m[1] . $m[2] . "'" . SecretMasker::MASK . "'";
        }, $sql);
    }

    private static function lastSegment(string $qualified): string
    {
        $parts = preg_split('/\s*\.\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim($qualified)) ?: [$qualified];

        return strtolower(self::unquote((string) end($parts)));
    }

    private static function unquote(string $ident): string
    {
        $ident = trim($ident);
        if (strlen($ident) >= 2) {
            $f = $ident[0];
            $l = $ident[strlen($ident) - 1];
            if (($f === '"' && $l === '"') || ($f === '`' && $l === '`') || ($f === '[' && $l === ']')) {
                $ident = str_replace('""', '"', substr($ident, 1, -1));
            }
        }
        $dot = strrpos($ident, '.');

        return strtolower($dot === false ? $ident : substr($ident, $dot + 1));
    }
}
