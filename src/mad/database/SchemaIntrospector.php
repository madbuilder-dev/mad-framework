<?php

namespace Mad\Database;

use PDO;
use RuntimeException;

/**
 * Introspecção multi-engine (SQLite / MySQL / PostgreSQL) sobre um PDO já
 * conectado. Saída no MESMO shape que o introspector do builder (Database
 * Manager) consome — paridade entre os transportes direto/SSH/REST:
 *   [
 *     'engine' => 'sqlite'|'mysql'|'pgsql',
 *     'tables' => [[
 *        'name','type'=>'table'|'view','rowCount'=>?int,'rowCountApprox'=>bool,
 *        'columns'=>[['name','type','isPrimary','isForeign','allowNull','references'=>?['table','column']]],
 *        'indexes'=>[['name','columns'=>[],'unique']],
 *     ]],
 *   ]
 *
 * ⚠️ CUSTO: em banco grande (centenas de tabelas, tabelas de 10^8 linhas) a
 * introspecção NÃO pode fazer `COUNT(*)` por tabela nem uma query por tabela:
 *   - `rowCount` vem da ESTATÍSTICA do engine (`information_schema.tables.table_rows`
 *     no MySQL, `pg_class.reltuples` no PostgreSQL) — 1 query, zero table scan.
 *     `rowCountApprox` marca que o número é estimado (o front prefixa com `~`).
 *   - colunas / FKs / índices são carregados em LOTE (1 query por tipo para o
 *     schema inteiro), não 1 por tabela. Com 400 tabelas isso é ~4 queries em vez
 *     de ~1600 round-trips.
 * SQLite não tem catálogo de estatística garantido: usa `sqlite_stat1` quando o
 * ANALYZE já rodou, senão cai no `COUNT(*)` (arquivo local) — aí é exato.
 * Se a query em lote falhar (permissão, engine exótico), cada tabela cai no
 * caminho por-tabela antigo.
 *
 * Identificadores em PRAGMA/SHOW (que não aceitam bind) são validados por
 * allowlist de caractere + quote por driver — defesa contra injeção.
 */
class SchemaIntrospector
{
    /** @return array<string,mixed> */
    public function introspect(PDO $pdo, bool $withCounts = true): array
    {
        $driver = $this->driver($pdo);
        $list = $this->listTables($pdo);

        // Pré-carga em LOTE: null = não disponível nesse engine / query falhou
        // → cada tabela cai no caminho por-tabela.
        $bulkCols = $this->bulkColumnRows($pdo, $driver);
        $bulkFks = $this->bulkForeignKeys($pdo, $driver);
        $bulkIdx = $this->bulkIndexes($pdo, $driver);
        $bulkPks = $driver === 'pgsql' ? $this->bulkPgPrimaryKeys($pdo) : null;
        $counts = $withCounts ? $this->rowCounts($pdo, $driver, $list) : [];

        $tables = [];

        foreach ($list as $t) {
            $name = $t['name'];
            $type = $t['type'];
            try {
                $fks = $bulkFks !== null ? ($bulkFks[$name] ?? []) : $this->foreignKeys($pdo, $name);
                $columns = $bulkCols !== null
                    ? $this->mapColumnRows($bulkCols[$name] ?? [], $driver, $fks, $bulkPks[$name] ?? [])
                    : $this->columns($pdo, $name);
                $indexes = $bulkIdx !== null ? ($bulkIdx[$name] ?? []) : $this->indexes($pdo, $name);
                $count = $type === 'table' ? ($counts[$name] ?? null) : null;

                $tables[] = [
                    'name' => $name,
                    'type' => $type,
                    'rowCount' => $count['value'] ?? null,
                    'rowCountApprox' => (bool) ($count['approx'] ?? false),
                    'columns' => $columns,
                    'indexes' => $indexes,
                ];
            } catch (\Throwable $e) {
                // Tabela problemática não derruba a introspecção inteira.
                $tables[] = ['name' => $name, 'type' => $type, 'rowCount' => null, 'rowCountApprox' => false, 'columns' => [], 'indexes' => [], 'error' => $e->getMessage()];
            }
        }

        return ['engine' => $driver, 'tables' => $tables];
    }

    /** Estrutura de UMA tabela (colunas + índices). */
    public function tableStructure(PDO $pdo, string $table): array
    {
        return [
            'name' => $table,
            'columns' => $this->columns($pdo, $table),
            'indexes' => $this->indexes($pdo, $table),
        ];
    }

    public function driver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    // ── Tabelas + views ───────────────────────────────────────────────────
    /** @return array<int,array{name:string,type:string}> */
    private function listTables(PDO $pdo): array
    {
        $driver = $this->driver($pdo);

        if ($driver === 'sqlite') {
            $sql = "SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name";
            return array_map(fn ($r) => ['name' => (string) $r['name'], 'type' => $r['type'] === 'view' ? 'view' : 'table'], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($driver === 'pgsql') {
            $sql = "SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name";
            return array_map(fn ($r) => ['name' => (string) $r['name'], 'type' => stripos((string) $r['type'], 'view') !== false ? 'view' : 'table'], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        }

        // mysql / fallback
        $sql = "SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name";
        return array_map(fn ($r) => ['name' => (string) ($r['name'] ?? $r['NAME']), 'type' => stripos((string) ($r['type'] ?? $r['TYPE']), 'view') !== false ? 'view' : 'table'], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Colunas (com PK + FK) ─────────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    private function columns(PDO $pdo, string $table): array
    {
        $driver = $this->driver($pdo);
        $fks = $this->foreignKeys($pdo, $table); // column => ['table','column']

        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . $this->quoteIdent($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC);
            return $this->mapColumnRows($rows, $driver, $fks);
        }

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = :t AND table_schema = current_schema() ORDER BY ordinal_position");
            $stmt->execute([':t' => $table]);
            return $this->mapColumnRows($stmt->fetchAll(PDO::FETCH_ASSOC), $driver, $fks, $this->pgPrimaryKeys($pdo, $table));
        }

        // mysql / fallback
        $stmt = $pdo->prepare("SELECT column_name, column_type, is_nullable, column_key FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position");
        $stmt->execute([':t' => $table]);
        return $this->mapColumnRows($stmt->fetchAll(PDO::FETCH_ASSOC), $driver, $fks);
    }

    /**
     * Linhas cruas de catálogo → shape de coluna. Único ponto de mapeamento:
     * o caminho em lote e o por-tabela passam pelas MESMAS regras.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,array{table:string,column:string}>  $fks
     * @param  string[]  $pks  PKs (só pgsql; nos outros vem do próprio catálogo)
     * @return array<int,array<string,mixed>>
     */
    private function mapColumnRows(array $rows, string $driver, array $fks, array $pks = []): array
    {
        if ($driver === 'sqlite') {
            return array_map(fn ($r) => [
                'name' => $r['name'],
                'type' => $r['type'] !== '' ? $r['type'] : 'TEXT',
                'isPrimary' => (int) $r['pk'] > 0,
                'isForeign' => isset($fks[$r['name']]),
                'allowNull' => (int) $r['notnull'] === 0,
                'references' => $fks[$r['name']] ?? null,
            ], $rows);
        }

        if ($driver === 'pgsql') {
            return array_map(fn ($r) => [
                'name' => $r['column_name'],
                'type' => strtoupper((string) $r['data_type']),
                'isPrimary' => in_array($r['column_name'], $pks, true),
                'isForeign' => isset($fks[$r['column_name']]),
                'allowNull' => strtoupper((string) $r['is_nullable']) === 'YES',
                'references' => $fks[$r['column_name']] ?? null,
            ], $rows);
        }

        // mysql / fallback
        return array_map(function ($r) use ($fks) {
            // MySQL devolve chaves em lower OU upper conforme versão/SO.
            $r = array_change_key_case($r, CASE_LOWER);
            return [
                'name' => $r['column_name'],
                'type' => strtoupper((string) $r['column_type']),
                'isPrimary' => strtoupper((string) ($r['column_key'] ?? '')) === 'PRI',
                'isForeign' => isset($fks[$r['column_name']]),
                'allowNull' => strtoupper((string) ($r['is_nullable'] ?? 'YES')) === 'YES',
                'references' => $fks[$r['column_name']] ?? null,
            ];
        }, $rows);
    }

    // ── Foreign keys: column => ['table'=>, 'column'=>] ───────────────────
    /** @return array<string,array{table:string,column:string}> */
    private function foreignKeys(PDO $pdo, string $table): array
    {
        $driver = $this->driver($pdo);
        $out = [];

        try {
            if ($driver === 'sqlite') {
                $rows = $pdo->query('PRAGMA foreign_key_list(' . $this->quoteIdent($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $out[$r['from']] = ['table' => $r['table'], 'column' => $r['to'] ?: 'id'];
                }
                return $out;
            }

            if ($driver === 'pgsql') {
                $sql = "SELECT kcu.column_name AS col, ccu.table_name AS ref_table, ccu.column_name AS ref_col
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage kcu
                          ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                        JOIN information_schema.constraint_column_usage ccu
                          ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = :t AND tc.table_schema = current_schema()";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':t' => $table]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[$r['col']] = ['table' => $r['ref_table'], 'column' => $r['ref_col']];
                }
                return $out;
            }

            // mysql
            $sql = "SELECT column_name AS col, referenced_table_name AS ref_table, referenced_column_name AS ref_col
                    FROM information_schema.key_column_usage
                    WHERE table_schema = DATABASE() AND table_name = :t AND referenced_table_name IS NOT NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':t' => $table]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $out[$r['col']] = ['table' => $r['ref_table'], 'column' => $r['ref_col'] ?: 'id'];
            }
        } catch (\Throwable $e) {
            return $out;
        }

        return $out;
    }

    /** @return string[] PKs (pgsql) */
    private function pgPrimaryKeys(PDO $pdo, string $table): array
    {
        try {
            $sql = "SELECT kcu.column_name AS col
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                      ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                    WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = :t AND tc.table_schema = current_schema()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':t' => $table]);
            return array_map(fn ($r) => (string) $r['col'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Índices ───────────────────────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    private function indexes(PDO $pdo, string $table): array
    {
        $driver = $this->driver($pdo);

        try {
            if ($driver === 'sqlite') {
                $out = [];
                foreach ($pdo->query('PRAGMA index_list(' . $this->quoteIdent($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
                    $cols = $pdo->query('PRAGMA index_info(' . $this->quoteIdent($idx['name'], $driver) . ')')->fetchAll(PDO::FETCH_ASSOC);
                    $out[] = ['name' => $idx['name'], 'columns' => array_map(fn ($c) => $c['name'], $cols), 'unique' => (int) ($idx['unique'] ?? 0) === 1];
                }
                return $out;
            }

            if ($driver === 'pgsql') {
                $sql = "SELECT i.relname AS name, ix.indisunique AS uniq, a.attname AS col
                        FROM pg_class t
                        JOIN pg_index ix ON t.oid = ix.indrelid
                        JOIN pg_class i ON i.oid = ix.indexrelid
                        JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                        WHERE t.relname = :t AND t.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())
                        ORDER BY i.relname";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':t' => $table]);
                $byName = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byName[$r['name']] ??= ['name' => $r['name'], 'columns' => [], 'unique' => (bool) $r['uniq']];
                    $byName[$r['name']]['columns'][] = $r['col'];
                }
                return array_values($byName);
            }

            // mysql
            $rows = $pdo->query('SHOW INDEX FROM ' . $this->quoteIdent($table, $driver))->fetchAll(PDO::FETCH_ASSOC);
            $byName = [];
            foreach ($rows as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $name = $r['key_name'];
                $byName[$name] ??= ['name' => $name, 'columns' => [], 'unique' => (int) ($r['non_unique'] ?? 1) === 0];
                $byName[$name]['columns'][] = $r['column_name'];
            }
            return array_values($byName);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Pré-carga em lote (1 query por tipo, schema inteiro) ──────────────

    /**
     * Colunas de TODAS as tabelas. null = engine sem catálogo em lote (sqlite)
     * ou query falhou → caller cai no caminho por-tabela.
     *
     * @return array<string,array<int,array<string,mixed>>>|null table => rows
     */
    private function bulkColumnRows(PDO $pdo, string $driver): ?array
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $sql = $driver === 'pgsql'
            ? "SELECT table_name, column_name, data_type, is_nullable
               FROM information_schema.columns
               WHERE table_schema = current_schema()
               ORDER BY table_name, ordinal_position"
            : "SELECT table_name, column_name, column_type, is_nullable, column_key
               FROM information_schema.columns
               WHERE table_schema = DATABASE()
               ORDER BY table_name, ordinal_position";

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $out[(string) $r['table_name']][] = $r;
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * FKs de TODAS as tabelas.
     *
     * @return array<string,array<string,array{table:string,column:string}>>|null table => col => ref
     */
    private function bulkForeignKeys(PDO $pdo, string $driver): ?array
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $sql = $driver === 'pgsql'
            ? "SELECT tc.table_name AS tbl, kcu.column_name AS col, ccu.table_name AS ref_table, ccu.column_name AS ref_col
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
               JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
               WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()"
            : "SELECT table_name AS tbl, column_name AS col, referenced_table_name AS ref_table, referenced_column_name AS ref_col
               FROM information_schema.key_column_usage
               WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL";

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $out[(string) $r['tbl']][(string) $r['col']] = [
                    'table' => (string) $r['ref_table'],
                    'column' => (string) ($r['ref_col'] ?: 'id'),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string,string[]>|null table => PKs (pgsql) */
    private function bulkPgPrimaryKeys(PDO $pdo): ?array
    {
        $sql = "SELECT tc.table_name AS tbl, kcu.column_name AS col
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema()";

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['tbl']][] = (string) $r['col'];
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Índices de TODAS as tabelas. No MySQL usa `information_schema.statistics`
     * (ordenado por `seq_in_index`) em vez de um `SHOW INDEX` por tabela.
     *
     * @return array<string,array<int,array<string,mixed>>>|null table => indexes
     */
    private function bulkIndexes(PDO $pdo, string $driver): ?array
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $sql = $driver === 'pgsql'
            ? "SELECT t.relname AS tbl, i.relname AS name, ix.indisunique AS uniq, a.attname AS col
               FROM pg_class t
               JOIN pg_index ix ON t.oid = ix.indrelid
               JOIN pg_class i ON i.oid = ix.indexrelid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
               WHERE t.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())
               ORDER BY t.relname, i.relname"
            : "SELECT table_name AS tbl, index_name AS name, non_unique AS non_uniq, column_name AS col
               FROM information_schema.statistics
               WHERE table_schema = DATABASE()
               ORDER BY table_name, index_name, seq_in_index";

        try {
            $byTable = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $tbl = (string) $r['tbl'];
                $name = (string) $r['name'];
                $unique = $driver === 'pgsql'
                    ? filter_var($r['uniq'], FILTER_VALIDATE_BOOLEAN)
                    : (int) ($r['non_uniq'] ?? 1) === 0;
                $byTable[$tbl][$name] ??= ['name' => $name, 'columns' => [], 'unique' => $unique];
                $byTable[$tbl][$name]['columns'][] = (string) $r['col'];
            }
            return array_map(fn ($idx) => array_values($idx), $byTable);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Contagem de linhas ────────────────────────────────────────────────

    /**
     * Contagem por tabela SEM table scan: estatística do engine em 1 query.
     * `approx` diz se o número é estimado (MySQL/PostgreSQL sempre são).
     *
     * @param  array<int,array{name:string,type:string}>  $list
     * @return array<string,array{value:?int,approx:bool}>
     */
    private function rowCounts(PDO $pdo, string $driver, array $list): array
    {
        if ($driver === 'sqlite') {
            return $this->sqliteRowCounts($pdo, $list);
        }

        $sql = $driver === 'pgsql'
            ? "SELECT c.relname AS tbl, c.reltuples::bigint AS n
               FROM pg_class c
               JOIN pg_namespace ns ON ns.oid = c.relnamespace
               WHERE ns.nspname = current_schema() AND c.relkind IN ('r','p')"
            : "SELECT table_name AS tbl, table_rows AS n
               FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'";

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                // PG devolve -1 em tabela que nunca passou por ANALYZE; MySQL
                // devolve NULL em engine sem estatística. Nos dois casos: sem número.
                $n = $r['n'] === null ? null : (int) $r['n'];
                $out[(string) $r['tbl']] = ['value' => $n !== null && $n >= 0 ? $n : null, 'approx' => true];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * SQLite não tem catálogo de estatística nativo: usa `sqlite_stat1` quando
     * o ANALYZE já rodou (estimado), senão `COUNT(*)` por tabela (arquivo local,
     * exato).
     *
     * @param  array<int,array{name:string,type:string}>  $list
     * @return array<string,array{value:?int,approx:bool}>
     */
    private function sqliteRowCounts(PDO $pdo, array $list): array
    {
        $stats = [];
        try {
            foreach ($pdo->query('SELECT tbl, stat FROM sqlite_stat1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // `stat` = "<linhas> <média por coluna indexada>..." — só o 1º token interessa.
                $first = strtok((string) $r['stat'], ' ');
                if ($first !== false && ctype_digit($first)) {
                    $stats[(string) $r['tbl']] ??= (int) $first;
                }
            }
        } catch (\Throwable $e) {
            $stats = []; // sem ANALYZE: a tabela sqlite_stat1 nem existe
        }

        $out = [];
        foreach ($list as $t) {
            if ($t['type'] !== 'table') {
                continue;
            }
            if (isset($stats[$t['name']])) {
                $out[$t['name']] = ['value' => $stats[$t['name']], 'approx' => true];
                continue;
            }
            $out[$t['name']] = ['value' => $this->rowCount($pdo, $t['name']), 'approx' => false];
        }

        return $out;
    }

    private function rowCount(PDO $pdo, string $table): ?int
    {
        try {
            $driver = $this->driver($pdo);
            return (int) $pdo->query('SELECT COUNT(*) FROM ' . $this->quoteIdent($table, $driver))->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Quote de identificador por driver (nomes validados antes). */
    private function quoteIdent(string $ident, string $driver): string
    {
        if (! preg_match('/^[A-Za-z0-9_$.]+$/', $ident)) {
            throw new RuntimeException("Identificador inválido: {$ident}");
        }
        if ($driver === 'mysql') {
            return '`' . str_replace('`', '``', $ident) . '`';
        }
        // sqlite / pgsql / fallback — aspas duplas padrão SQL
        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
