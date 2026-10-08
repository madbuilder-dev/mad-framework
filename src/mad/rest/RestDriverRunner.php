<?php

namespace Mad\Rest;

use Mad\Database\SchemaIntrospector;
use PDO;
use RuntimeException;

/**
 * Execução de SQL do Driver REST sobre um PDO do app do cliente. Mesma
 * normalização de resultado do builder (columns/rows/rowCount/execMs) p/
 * paridade com os transportes direto/SSH.
 *
 * `read_only` (chave do pareamento, ou restrição pedida no corpo assinado) vale
 * em DUAS camadas, e a que garante é a 2ª:
 *
 *   1. FILTRO ({@see RestDriverReadOnlyGuard}) — lê o texto e recusa cedo, com
 *      mensagem clara, o que não é UMA instrução de leitura.
 *   2. NO BANCO ({@see RestDriverReadOnlySession}) — o SQL do usuário (run e
 *      plan) roda numa transação que o próprio engine trata como somente
 *      leitura e não consegue reabrir. Era só `START TRANSACTION READ ONLY` no
 *      MySQL/MariaDB: todo DDL faz commit implícito antes de executar, então
 *      TRUNCATE, DROP, ALTER e CREATE gravavam com a chave somente leitura. No
 *      PostgreSQL com prepare emulado (app hospedado) passava
 *      `SELECT 'a\'; COMMIT; DELETE ...; --'`.
 *
 * O PDO do modo somente leitura é uma conexão PRÓPRIA, aberta pelo controller
 * com {@see RestDriverReadOnlySession::connectionConfig()} — a do app aceita
 * vários comandos por envio no MySQL e é recusada pela trava.
 *
 * Com a chave de ESCRITA sobra a trava de "uma instrução por envio"
 * ({@see RestDriverSqlScanner}), que entende corpo de rotina: `CREATE TRIGGER|
 * PROCEDURE|FUNCTION ... BEGIN a; b; END` é uma instrução só.
 */
class RestDriverRunner
{
    public function __construct(private readonly SchemaIntrospector $introspector = new SchemaIntrospector()) {}

    public function run(PDO $pdo, string $sql, bool $readOnly): array
    {
        $driver = $this->driver($pdo);

        if ($readOnly) {
            RestDriverReadOnlyGuard::assertReadable($sql, $driver);

            return RestDriverReadOnlySession::run($pdo, fn () => $this->doRun($pdo, $sql, true));
        }

        RestDriverSqlScanner::assertSingleStatement($sql, $driver);

        return $this->doRun($pdo, $sql, false);
    }

    private function doRun(PDO $pdo, string $sql, bool $readOnly): array
    {
        $start = microtime(true);
        $stmt = $readOnly ? RestDriverReadOnlySession::prepare($pdo, $sql) : $pdo->prepare($sql);
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
     * Plano de execução. Respeita `read_only`: passa pelas MESMAS travas do
     * run() — `$sql` é do usuário, e `ANALYZE DELETE ...` aqui vira
     * `EXPLAIN ANALYZE DELETE ...`, que executa.
     */
    public function explain(PDO $pdo, string $sql, bool $readOnly = false): array
    {
        $driver = $this->driver($pdo);
        $prefixed = RestDriverReadOnlyGuard::explainStatement($sql, $driver);

        if ($readOnly) {
            RestDriverReadOnlyGuard::assertReadable($prefixed, $driver);

            return RestDriverReadOnlySession::run($pdo, function () use ($pdo, $prefixed, $driver) {
                $stmt = RestDriverReadOnlySession::prepare($pdo, $prefixed);
                $stmt->execute();

                return ['driver' => $driver, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            });
        }

        RestDriverSqlScanner::assertSingleStatement($prefixed, $driver);

        return ['driver' => $driver, 'rows' => $pdo->query($prefixed)->fetchAll(PDO::FETCH_ASSOC)];
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
