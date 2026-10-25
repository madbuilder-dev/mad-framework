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
 *     'defaultSchema' => ?string,            // só pgsql
 *     'tables' => [[
 *        'name','type'=>'table'|'view',
 *        'schema' => string,                 // só pgsql
 *        'rowCount'=>?int,'rowCountApprox'=>bool,
 *        'columns'=>[['name','type','isPrimary','isForeign','allowNull','references'=>?['table','column']]],
 *        'indexes'=>[['name','columns'=>[],'unique']],
 *     ]],
 *   ]
 *
 * PostgreSQL: lista as tabelas de TODOS os schemas do usuário (fora
 * `information_schema` e `pg_*`: pg_catalog, pg_toast, pg_temp_N). A do schema
 * padrão da conexão (`current_schema()`) sai com o nome puro, como sempre; a de
 * outro schema sai qualificada (`bi.grupo`) — inclusive em `references.table`.
 * O nome qualificado é a chave da tabela em todo o resto (estrutura, edição
 * inline). App com driver anterior manda só o schema padrão, sem `schema`: o
 * front mostra como antes.
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
    /**
     * FKs do PostgreSQL pelo catálogo (`pg_constraint`), coluna a coluna, com o
     * schema dos dois lados. O `information_schema` não serve: casa restrição
     * por nome (duas tabelas podem ter uma `fk_grupo` cada), perde a FK que
     * aponta para outro schema e esconde a de tabela que o usuário não é dono.
     * O chamador completa com o WHERE (`ns.nspname`, `c.relname`).
     */
    private const PG_FOREIGN_KEYS_SQL = "SELECT ns.nspname AS sch, c.relname AS tbl, a.attname AS col,
               rns.nspname AS ref_sch, rc.relname AS ref_table, ra.attname AS ref_col
        FROM (SELECT conrelid, confrelid, conkey, confkey, generate_subscripts(conkey, 1) AS i
              FROM pg_constraint WHERE contype = 'f') con
        JOIN pg_class c ON c.oid = con.conrelid
        JOIN pg_namespace ns ON ns.oid = c.relnamespace
        JOIN pg_class rc ON rc.oid = con.confrelid
        JOIN pg_namespace rns ON rns.oid = rc.relnamespace
        JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[con.i]
        JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = con.confkey[con.i]";

    /**
     * PKs do PostgreSQL pelo mesmo catálogo da FK: o `table_constraints` do
     * `information_schema` esconde a PK de quem só tem SELECT na tabela, e a
     * edição inline depende dela. O chamador completa com o WHERE.
     */
    private const PG_PRIMARY_KEYS_SQL = "SELECT ns.nspname AS sch, c.relname AS tbl, a.attname AS col
        FROM (SELECT conrelid, conkey, generate_subscripts(conkey, 1) AS i
              FROM pg_constraint WHERE contype = 'p') con
        JOIN pg_class c ON c.oid = con.conrelid
        JOIN pg_namespace ns ON ns.oid = c.relnamespace
        JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[con.i]";

    /** @return array<string,mixed> */
    public function introspect(PDO $pdo, bool $withCounts = true): array
    {
        $driver = $this->driver($pdo);
        $default = $driver === 'pgsql' ? $this->pgDefaultSchema($pdo) : null;
        $list = $this->listTables($pdo);

        // Pré-carga em LOTE: null = não disponível nesse engine / query falhou
        // → cada tabela cai no caminho por-tabela.
        $bulkCols = $this->bulkColumnRows($pdo, $driver);
        $bulkFks = $this->bulkForeignKeys($pdo, $driver, $default);
        $bulkIdx = $this->bulkIndexes($pdo, $driver);
        $bulkPks = $driver === 'pgsql' ? $this->bulkPgPrimaryKeys($pdo) : null;
        $counts = $withCounts ? $this->rowCounts($pdo, $driver, $list) : [];

        $tables = [];

        foreach ($list as $t) {
            $name = $t['name'];
            $type = $t['type'];
            // PostgreSQL: lotes indexados por (schema, tabela) — `bi.grupo` e uma
            // `"bi.grupo"` do schema padrão não se misturam — e o caminho por
            // tabela já recebe o alvo resolvido. MySQL/SQLite: pelo nome.
            $key = $t['key'] ?? $name;
            $target = $t['target'] ?? null;
            // Só o PostgreSQL tem schema: MySQL/SQLite saem exatamente como antes.
            $schemaField = isset($t['schema']) ? ['schema' => $t['schema']] : [];
            try {
                $fks = $bulkFks !== null ? ($bulkFks[$key] ?? []) : $this->foreignKeys($pdo, $name, $target);
                $columns = $bulkCols !== null
                    ? $this->mapColumnRows($bulkCols[$key] ?? [], $driver, $fks, $bulkPks[$key] ?? [])
                    : $this->columns($pdo, $name, $target);
                $indexes = $bulkIdx !== null ? ($bulkIdx[$key] ?? []) : $this->indexes($pdo, $name, $target);
                $count = $type === 'table' ? ($counts[$key] ?? null) : null;

                $tables[] = [
                    'name' => $name,
                    'type' => $type,
                ] + $schemaField + [
                    'rowCount' => $count['value'] ?? null,
                    'rowCountApprox' => (bool) ($count['approx'] ?? false),
                    'columns' => $columns,
                    'indexes' => $indexes,
                ];
            } catch (\Throwable $e) {
                // Tabela problemática não derruba a introspecção inteira.
                $tables[] = ['name' => $name, 'type' => $type] + $schemaField + ['rowCount' => null, 'rowCountApprox' => false, 'columns' => [], 'indexes' => [], 'error' => $e->getMessage()];
            }
        }

        return $driver === 'pgsql'
            ? ['engine' => $driver, 'defaultSchema' => $default, 'tables' => $tables]
            : ['engine' => $driver, 'tables' => $tables];
    }

    /**
     * Estrutura de UMA tabela (colunas + índices). No PostgreSQL `$table` é o
     * nome da listagem: `bi.grupo` ou, no schema padrão, só `grupo`.
     */
    public function tableStructure(PDO $pdo, string $table): array
    {
        $target = $this->driver($pdo) === 'pgsql' ? $this->pgTarget($pdo, $table) : null;

        return [
            'name' => $table,
            'columns' => $this->columns($pdo, $table, $target),
            'indexes' => $this->indexes($pdo, $table, $target),
        ];
    }

    public function driver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Nome da tabela pronto para entrar no SQL (edição inline). PostgreSQL:
     * schema e tabela resolvidos no catálogo (ver pgTarget) + aspas por parte
     * (`"bi"."grupo"`, `"public"."grupo"`, `"public"."a.b"`). MySQL/SQLite: o
     * nome inteiro entre aspas, como sempre.
     */
    public function quoteTable(PDO $pdo, string $table): string
    {
        $driver = $this->driver($pdo);
        if ($driver !== 'pgsql') {
            return $this->quoteIdent($table, $driver);
        }

        [$schema, $name] = $this->pgTarget($pdo, $table);

        return $this->quoteIdent($schema, $driver) . '.' . $this->quoteIdent($name, $driver);
    }

    // ── Schemas (PostgreSQL) ──────────────────────────────────────────────

    /** Schema padrão da conexão (onde cai o nome sem prefixo); null sem search_path válido. */
    private function pgDefaultSchema(PDO $pdo): ?string
    {
        $schema = $pdo->query('SELECT current_schema()')->fetchColumn();

        return is_string($schema) && $schema !== '' ? $schema : null;
    }

    /**
     * Filtro SQL dos schemas do usuário: fora `information_schema` e tudo que
     * começa com `pg_` (pg_catalog, pg_toast, pg_temp_N, pg_toast_temp_N — o
     * PostgreSQL reserva o prefixo). `$col` é a coluna com o nome do schema.
     */
    private function pgUserSchemaSql(string $col): string
    {
        return "(lower({$col}) <> 'information_schema' AND substr(lower({$col}), 1, 3) <> 'pg_')";
    }

    /** `bi.grupo`, ou só `grupo` no schema padrão da conexão. */
    private function qualify(string $schema, string $table, ?string $default): string
    {
        return $schema === $default ? $table : "{$schema}.{$table}";
    }

    /** Chave interna dos lotes do PostgreSQL: (schema, tabela) sem ambiguidade. */
    private function pgKey(string $schema, string $table): string
    {
        return $schema . "\0" . $table;
    }

    /**
     * Nome da listagem → [schema, tabela], resolvido no CATÁLOGO, não pelo
     * ponto: `bi.grupo` é a tabela `grupo` do schema `bi`, ou a tabela `"bi.grupo"`
     * do schema padrão (nome com ponto). Uma só casa → essa; as duas → recusa
     * por ambiguidade; nenhuma → recusa. `public.grupo` explícito também vale.
     * O nome passa antes pela allowlist de sempre; schema de sistema nunca casa
     * (nem como padrão da conexão).
     *
     * @return array{0:string,1:string}
     */
    private function pgTarget(PDO $pdo, string $table): array
    {
        if (! preg_match('/^[A-Za-z0-9_$.]+$/', $table)) {
            throw new RuntimeException("Tabela inválida: {$table}");
        }

        $stmt = $pdo->prepare("SELECT n.nspname AS sch, c.relname AS tbl
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ('r','p','v','m','f') AND " . $this->pgUserSchemaSql('n.nspname') . "
              AND (n.nspname || '.' || c.relname = :ref OR (n.nspname = current_schema() AND c.relname = :bare))
            ORDER BY n.nspname, c.relname");
        $stmt->execute([':ref' => $table, ':bare' => $table]);
        $hits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($hits) > 1) {
            $options = array_map(fn ($r) => '"' . $r['sch'] . '"."' . $r['tbl'] . '"', $hits);
            throw new RuntimeException("Tabela ambígua: {$table} pode ser " . implode(' ou ', $options));
        }
        if ($hits === []) {
            throw new RuntimeException("Tabela inválida: {$table}");
        }

        return [(string) $hits[0]['sch'], (string) $hits[0]['tbl']];
    }

    // ── Tabelas + views ───────────────────────────────────────────────────
    /** @return array<int,array{name:string,type:string,schema?:string,key?:string,target?:array{0:string,1:string}}> */
    private function listTables(PDO $pdo): array
    {
        $driver = $this->driver($pdo);

        if ($driver === 'sqlite') {
            $sql = "SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name";
            return array_map(fn ($r) => ['name' => (string) $r['name'], 'type' => $r['type'] === 'view' ? 'view' : 'table'], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($driver === 'pgsql') {
            // Schema padrão primeiro (nome puro), depois os outros por schema.
            $default = $this->pgDefaultSchema($pdo);
            $sql = "SELECT table_schema AS sch, table_name AS name, table_type AS type FROM information_schema.tables
                    WHERE " . $this->pgUserSchemaSql('table_schema') . "
                    ORDER BY table_schema <> current_schema(), table_schema, table_name";
            return array_map(fn ($r) => [
                'name' => $this->qualify((string) $r['sch'], (string) $r['name'], $default),
                'type' => stripos((string) $r['type'], 'view') !== false ? 'view' : 'table',
                'schema' => (string) $r['sch'],
                // internos (não saem na resposta): chave dos lotes e alvo do caminho por tabela
                'key' => $this->pgKey((string) $r['sch'], (string) $r['name']),
                'target' => [(string) $r['sch'], (string) $r['name']],
            ], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        }

        // mysql / fallback
        $sql = "SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name";
        return array_map(fn ($r) => ['name' => (string) ($r['name'] ?? $r['NAME']), 'type' => stripos((string) ($r['type'] ?? $r['TYPE']), 'view') !== false ? 'view' : 'table'], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Colunas (com PK + FK) ─────────────────────────────────────────────
    /**
     * @param  array{0:string,1:string}|null  $target  [schema, tabela] já resolvidos (pgsql)
     * @return array<int,array<string,mixed>>
     */
    private function columns(PDO $pdo, string $table, ?array $target = null): array
    {
        $driver = $this->driver($pdo);

        if ($driver === 'pgsql') {
            [$schema, $name] = $target ??= $this->pgTarget($pdo, $table);
            $stmt = $pdo->prepare("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = :t AND table_schema = :s ORDER BY ordinal_position");
            $stmt->execute([':t' => $name, ':s' => $schema]);
            return $this->mapColumnRows($stmt->fetchAll(PDO::FETCH_ASSOC), $driver, $this->foreignKeys($pdo, $table, $target), $this->pgPrimaryKeys($pdo, $table, $target));
        }

        $fks = $this->foreignKeys($pdo, $table); // column => ['table','column']

        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . $this->quoteIdent($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC);
            return $this->mapColumnRows($rows, $driver, $fks);
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
    /**
     * @param  array{0:string,1:string}|null  $target  [schema, tabela] já resolvidos (pgsql)
     * @return array<string,array{table:string,column:string}>
     */
    private function foreignKeys(PDO $pdo, string $table, ?array $target = null): array
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
                [$schema, $name] = $target ?? $this->pgTarget($pdo, $table);
                $default = $this->pgDefaultSchema($pdo);
                $stmt = $pdo->prepare(self::PG_FOREIGN_KEYS_SQL . ' WHERE ns.nspname = :s AND c.relname = :t');
                $stmt->execute([':s' => $schema, ':t' => $name]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[$r['col']] = ['table' => $this->qualify((string) $r['ref_sch'], (string) $r['ref_table'], $default), 'column' => $r['ref_col']];
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

    /**
     * @param  array{0:string,1:string}|null  $target  [schema, tabela] já resolvidos
     * @return string[] PKs (pgsql)
     */
    private function pgPrimaryKeys(PDO $pdo, string $table, ?array $target = null): array
    {
        try {
            [$schema, $name] = $target ?? $this->pgTarget($pdo, $table);
            $stmt = $pdo->prepare(self::PG_PRIMARY_KEYS_SQL . ' WHERE ns.nspname = :s AND c.relname = :t');
            $stmt->execute([':t' => $name, ':s' => $schema]);
            return array_map(fn ($r) => (string) $r['col'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Índices ───────────────────────────────────────────────────────────
    /**
     * @param  array{0:string,1:string}|null  $target  [schema, tabela] já resolvidos (pgsql)
     * @return array<int,array<string,mixed>>
     */
    private function indexes(PDO $pdo, string $table, ?array $target = null): array
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
                        JOIN pg_namespace ns ON ns.oid = t.relnamespace
                        WHERE t.relname = :t AND ns.nspname = :s
                        ORDER BY i.relname";
                [$schema, $name] = $target ?? $this->pgTarget($pdo, $table);
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':t' => $name, ':s' => $schema]);
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
     * @return array<string,array<int,array<string,mixed>>>|null tabela (pgsql: schema+tabela, ver pgKey) => rows
     */
    private function bulkColumnRows(PDO $pdo, string $driver): ?array
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $sql = $driver === 'pgsql'
            ? "SELECT table_schema, table_name, column_name, data_type, is_nullable
               FROM information_schema.columns
               WHERE " . $this->pgUserSchemaSql('table_schema') . "
               ORDER BY table_schema, table_name, ordinal_position"
            : "SELECT table_name, column_name, column_type, is_nullable, column_key
               FROM information_schema.columns
               WHERE table_schema = DATABASE()
               ORDER BY table_name, ordinal_position";

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $tbl = $driver === 'pgsql'
                    ? $this->pgKey((string) $r['table_schema'], (string) $r['table_name'])
                    : (string) $r['table_name'];
                $out[$tbl][] = $r;
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * FKs de TODAS as tabelas.
     *
     * @return array<string,array<string,array{table:string,column:string}>>|null tabela (pgsql: schema+tabela) => col => ref
     */
    private function bulkForeignKeys(PDO $pdo, string $driver, ?string $default = null): ?array
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $sql = $driver === 'pgsql'
            ? self::PG_FOREIGN_KEYS_SQL . ' WHERE ' . $this->pgUserSchemaSql('ns.nspname')
            : "SELECT table_name AS tbl, column_name AS col, referenced_table_name AS ref_table, referenced_column_name AS ref_col
               FROM information_schema.key_column_usage
               WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL";

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                [$tbl, $refTable] = $driver === 'pgsql'
                    ? [$this->pgKey((string) $r['sch'], (string) $r['tbl']), $this->qualify((string) $r['ref_sch'], (string) $r['ref_table'], $default)]
                    : [(string) $r['tbl'], (string) $r['ref_table']];
                $out[$tbl][(string) $r['col']] = [
                    'table' => $refTable,
                    'column' => (string) ($r['ref_col'] ?: 'id'),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string,string[]>|null (schema, tabela) => PKs (pgsql) */
    private function bulkPgPrimaryKeys(PDO $pdo): ?array
    {
        $sql = self::PG_PRIMARY_KEYS_SQL . ' WHERE ' . $this->pgUserSchemaSql('ns.nspname');

        try {
            $out = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$this->pgKey((string) $r['sch'], (string) $r['tbl'])][] = (string) $r['col'];
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
     * @return array<string,array<int,array<string,mixed>>>|null tabela (pgsql: schema+tabela) => indexes
     */
    private function bulkIndexes(PDO $pdo, string $driver): ?array
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $sql = $driver === 'pgsql'
            ? "SELECT ns.nspname AS sch, t.relname AS tbl, i.relname AS name, ix.indisunique AS uniq, a.attname AS col
               FROM pg_class t
               JOIN pg_index ix ON t.oid = ix.indrelid
               JOIN pg_class i ON i.oid = ix.indexrelid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
               JOIN pg_namespace ns ON ns.oid = t.relnamespace
               WHERE " . $this->pgUserSchemaSql('ns.nspname') . "
               ORDER BY ns.nspname, t.relname, i.relname"
            : "SELECT table_name AS tbl, index_name AS name, non_unique AS non_uniq, column_name AS col
               FROM information_schema.statistics
               WHERE table_schema = DATABASE()
               ORDER BY table_name, index_name, seq_in_index";

        try {
            $byTable = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_LOWER);
                $tbl = $driver === 'pgsql'
                    ? $this->pgKey((string) $r['sch'], (string) $r['tbl'])
                    : (string) $r['tbl'];
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
            ? "SELECT ns.nspname AS sch, c.relname AS tbl, c.reltuples::bigint AS n
               FROM pg_class c
               JOIN pg_namespace ns ON ns.oid = c.relnamespace
               WHERE " . $this->pgUserSchemaSql('ns.nspname') . " AND c.relkind IN ('r','p')"
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
                $tbl = $driver === 'pgsql'
                    ? $this->pgKey((string) $r['sch'], (string) $r['tbl'])
                    : (string) $r['tbl'];
                $out[$tbl] = ['value' => $n !== null && $n >= 0 ? $n : null, 'approx' => true];
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
