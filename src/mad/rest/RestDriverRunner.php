<?php

namespace Mad\Rest;

use Mad\Database\SchemaIntrospector;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Execução de SQL do Driver REST sobre um PDO do app do cliente. Mesma
 * normalização de resultado do builder (columns/rows/rowCount/execMs) p/
 * paridade com os transportes direto/SSH.
 *
 * `read_only` NÃO é mais decidido por allowlist de keyword (era furado:
 * data-modifying CTE `WITH … DELETE RETURNING`, `EXPLAIN ANALYZE <write>` e
 * stacked `SELECT 1; DELETE …` passavam pelo 1º-keyword). Agora a leitura é
 * imposta em DOIS níveis:
 *
 *   1. NO BANCO (defesa primária) — a operação roda dentro de uma sessão
 *      realmente somente-leitura (`withReadOnlySession`): `SET TRANSACTION
 *      READ ONLY` (pg/mysql) ou `PRAGMA query_only` (sqlite). O próprio engine
 *      recusa qualquer escrita, inclusive disfarçada.
 *   2. SHAPE GUARDS (defesa em profundidade, erro cedo/claro) — `;` fora de
 *      literal (stacked) e `EXPLAIN ANALYZE` (execução disfarçada) são barrados
 *      antes de tocar o banco.
 */
class RestDriverRunner
{
    public function __construct(private readonly SchemaIntrospector $introspector = new SchemaIntrospector()) {}

    public function run(PDO $pdo, string $sql, bool $readOnly): array
    {
        $this->assertSingleStatement($sql);

        if ($readOnly) {
            $this->assertNoExplainAnalyze($sql);
            return $this->withReadOnlySession($pdo, fn () => $this->doRun($pdo, $sql));
        }

        return $this->doRun($pdo, $sql);
    }

    private function doRun(PDO $pdo, string $sql): array
    {
        $start = microtime(true);
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $columns = [];
        $rows = [];
        $colCount = $stmt->columnCount();

        if ($colCount > 0) {
            for ($i = 0; $i < $colCount; $i++) {
                $meta = @$stmt->getColumnMeta($i) ?: [];
                $columns[] = ['name' => $meta['name'] ?? "col_{$i}", 'type' => isset($meta['native_type']) ? strtoupper((string) $meta['native_type']) : null];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $rowCount = count($rows);
        } else {
            $rowCount = $stmt->rowCount();
        }

        return ['columns' => $columns, 'rows' => $rows, 'rowCount' => $rowCount, 'execMs' => (int) round((microtime(true) - $start) * 1000)];
    }

    /**
     * Plano de execução. Respeita `read_only`: o ANALYZE (que EXECUTA a query no
     * Postgres) é recusado, e o EXPLAIN roda dentro da sessão read-only — então
     * `plan` nunca vira vetor de escrita.
     */
    public function explain(PDO $pdo, string $sql, bool $readOnly = false): array
    {
        $driver = $this->driver($pdo);
        $prefixed = $driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' . $sql : 'EXPLAIN ' . $sql;

        $this->assertSingleStatement($prefixed);

        $run = fn () => ['driver' => $driver, 'rows' => $pdo->query($prefixed)->fetchAll(PDO::FETCH_ASSOC)];

        if ($readOnly) {
            $this->assertNoExplainAnalyze($prefixed);
            return $this->withReadOnlySession($pdo, $run);
        }

        return $run();
    }

    /**
     * Edição inline (UPDATE por PK, transação). Mesma validação anti-injeção do
     * builder (colunas contra o schema da tabela). Bloqueado se read_only.
     *
     * @param  array<int,array{pk:array<string,mixed>,set:array<string,mixed>}>  $edits
     */
    public function updateRows(PDO $pdo, string $table, array $edits, bool $readOnly): array
    {
        if ($readOnly) {
            throw new RuntimeException('Conexão em modo somente-leitura: edição bloqueada.');
        }
        if (! preg_match('/^[A-Za-z0-9_$.]+$/', $table)) {
            throw new RuntimeException("Tabela inválida: {$table}");
        }

        $driver = $this->driver($pdo);
        $struct = $this->introspector->tableStructure($pdo, $table);
        $validCols = array_flip(array_map(fn ($c) => $c['name'], $struct['columns']));

        $updated = 0;
        $pdo->beginTransaction();
        try {
            foreach ($edits as $edit) {
                $set = $edit['set'] ?? [];
                $pk = $edit['pk'] ?? [];
                if (empty($set) || empty($pk)) {
                    continue;
                }
                foreach (array_merge(array_keys($set), array_keys($pk)) as $col) {
                    if (! isset($validCols[$col])) {
                        throw new RuntimeException("Coluna desconhecida em {$table}: {$col}");
                    }
                }

                $params = [];
                $setParts = [];
                foreach ($set as $col => $val) {
                    $setParts[] = $this->quoteIdent((string) $col, $driver) . ' = ?';
                    $params[] = $val;
                }
                $whereParts = [];
                foreach ($pk as $col => $val) {
                    $whereParts[] = $this->quoteIdent((string) $col, $driver) . ' = ?';
                    $params[] = $val;
                }

                $sql = 'UPDATE ' . $this->quoteIdent($table, $driver) . ' SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' AND ', $whereParts);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $updated += $stmt->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['updated' => $updated];
    }

    public function ping(PDO $pdo): array
    {
        $version = null;
        try {
            $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable $e) {
            // alguns drivers não expõem
        }
        return ['ok' => true, 'engine' => $this->driver($pdo), 'version' => $version];
    }

    // ── Read-only no nível do banco ──────────────────────────────────────────

    /**
     * Executa $fn numa sessão REALMENTE somente-leitura — a defesa que a
     * allowlist de keyword não dava: data-modifying CTE, EXPLAIN ANALYZE e
     * stacked queries são barrados pelo PRÓPRIO engine, não por parsing.
     *
     *   pgsql  → BEGIN; SET TRANSACTION READ ONLY; … ; ROLLBACK
     *   mysql  → START TRANSACTION READ ONLY; … ; ROLLBACK
     *   sqlite → PRAGMA query_only=ON; … ; PRAGMA query_only=OFF
     *
     * Engine desconhecido → fail-closed (recusa). Violação read-only do engine
     * vira mensagem amigável ('somente-leitura').
     */
    private function withReadOnlySession(PDO $pdo, callable $fn)
    {
        $driver = $this->driver($pdo);

        [$enter, $leave] = match ($driver) {
            'pgsql' => [['BEGIN', 'SET TRANSACTION READ ONLY'], ['ROLLBACK']],
            'mysql' => [['START TRANSACTION READ ONLY'], ['ROLLBACK']],
            'sqlite' => [['PRAGMA query_only = 1'], ['PRAGMA query_only = 0']],
            default => throw new RuntimeException("Modo somente-leitura não suportado para o driver '{$driver}'."),
        };

        foreach ($enter as $stmt) {
            $pdo->exec($stmt);
        }

        try {
            return $fn();
        } catch (PDOException $e) {
            if ($this->isReadOnlyViolation($e, $driver)) {
                throw new RuntimeException('Conexão em modo somente-leitura: operação de escrita bloqueada.', 0, $e);
            }
            throw $e;
        } finally {
            foreach ($leave as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (\Throwable $e) {
                    // best-effort: limpeza da sessão não pode mascarar o erro real
                }
            }
        }
    }

    /** Erro do engine é uma violação de transação somente-leitura? */
    private function isReadOnlyViolation(PDOException $e, string $driver): bool
    {
        $sqlState = $e->errorInfo[0] ?? (string) $e->getCode();
        if ($sqlState === '25006') {           // SQL-standard: read-only sql transaction (pg + mysql)
            return true;
        }
        if ($driver === 'sqlite' && (int) ($e->errorInfo[1] ?? 0) === 8) {  // SQLITE_READONLY
            return true;
        }
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'read-only') || str_contains($msg, 'readonly') || str_contains($msg, 'read only');
    }

    // ── Shape guards (defesa em profundidade) ────────────────────────────────

    /**
     * Garante UMA única instrução. Bloqueia "stacked queries" (ex.:
     * `SELECT 1; DELETE …`) — o vetor que furava a allowlist. Um `;` final
     * (só espaço/comentário depois) é tolerado; qualquer código após o `;` →
     * erro. Pula literais ('…', "…", `…`, $tag$…$tag$) e comentários (-- , /* *​/)
     * p/ não confundir `;` de DADOS com separador.
     */
    private function assertSingleStatement(string $sql): void
    {
        $len = strlen($sql);
        $terminated = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            // Comentários — permitidos mesmo após o terminador (trailing).
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;          // loop ++ avança 1
                continue;
            }
            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;     // +1; loop ++ → após */
                continue;
            }

            // Espaço — sempre ok.
            if (ctype_space($ch)) {
                continue;
            }

            // Separador de instrução.
            if ($ch === ';') {
                $terminated = true;
                continue;
            }

            // Conteúdo real após um `;` ⇒ múltiplas instruções.
            if ($terminated) {
                throw new RuntimeException('Múltiplas instruções SQL não são permitidas.');
            }

            // Literais — pula o conteúdo (onde `;` é dado, não separador).
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $i = $this->skipQuoted($sql, $i, $ch);
                continue;
            }
            if ($ch === '$') {
                $skip = $this->skipDollarQuoted($sql, $i);
                if ($skip !== null) {
                    $i = $skip;
                }
            }
        }
    }

    /** Retorna o índice da aspa de fechamento (loop ++ passa adiante). */
    private function skipQuoted(string $sql, int $i, string $q): int
    {
        $len = strlen($sql);
        $allowBackslash = $q !== '`';                      // backtick (ident mysql) não usa \ escape

        for ($j = $i + 1; $j < $len; $j++) {
            $c = $sql[$j];
            if ($allowBackslash && $c === '\\') {          // pula char escapado (mysql)
                $j++;
                continue;
            }
            if ($c === $q) {
                if (($sql[$j + 1] ?? '') === $q) {         // '' "" `` doblado = escape
                    $j++;
                    continue;
                }
                return $j;
            }
        }

        return $len;                                       // não fechou → consome o resto
    }

    /** $tag$…$tag$ (Postgres). Retorna índice do último char do fechamento, ou null. */
    private function skipDollarQuoted(string $sql, int $i): ?int
    {
        if (! preg_match('/\$([A-Za-z0-9_]*)\$/A', $sql, $m, 0, $i)) {
            return null;
        }
        $tag = $m[0];
        $bodyStart = $i + strlen($tag);
        $close = strpos($sql, $tag, $bodyStart);

        return $close === false ? strlen($sql) : $close + strlen($tag) - 1;
    }

    /**
     * Recusa EXPLAIN ANALYZE (e `EXPLAIN (ANALYZE …)`). No Postgres o ANALYZE
     * EXECUTA a instrução — então `EXPLAIN ANALYZE DELETE …` é escrita
     * disfarçada. A sessão read-only já barra no banco; isto dá erro claro/cedo.
     */
    private function assertNoExplainAnalyze(string $sql): void
    {
        $hasAnalyze = preg_match(
            '/^\s*EXPLAIN\b(.*?)\b(SELECT|INSERT|UPDATE|DELETE|MERGE|WITH|TABLE|VALUES|CREATE|ALTER|DROP)\b/is',
            $sql,
            $m
        ) && preg_match('/\bANALYZE\b/i', $m[1]);

        // EXPLAIN ANALYZE sem verbo reconhecível à frente.
        $bareAnalyze = preg_match('/^\s*EXPLAIN\s+(\([^)]*\)\s*)?ANALYZE\b/is', $sql);

        if ($hasAnalyze || $bareAnalyze) {
            throw new RuntimeException('EXPLAIN ANALYZE não é permitido em modo somente-leitura.');
        }
    }

    private function driver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    private function quoteIdent(string $ident, string $driver): string
    {
        if (! preg_match('/^[A-Za-z0-9_$.]+$/', $ident)) {
            throw new RuntimeException("Identificador inválido: {$ident}");
        }
        if ($driver === 'mysql') {
            return '`' . str_replace('`', '``', $ident) . '`';
        }
        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
