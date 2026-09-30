<?php

namespace Mad\Mcp;

use Illuminate\Support\Facades\DB;
use Mad\Security\ProtectedData;
use PDO;

/**
 * McpTableGateway
 *
 * Acesso generico e PARAMETRIZADO a uma tabela cujo nome so e conhecido em
 * runtime (vem do manifest). Identificadores (tabela/colunas) sao validados
 * por formato e SEMPRE vem do manifest (whitelist confiavel); valores sao
 * bound (PDO prepared). Nunca concatena valor de usuario no SQL.
 *
 * Opera sobre o PDO NATIVO da conexao injetada via on() (DB::connection). Em
 * escritas, o chamador roda dentro de DB::connection($conn)->transaction(): como
 * getPdo() devolve o MESMO PDO da conexao, os statements crus participam da tx.
 *
 * DADO PROTEGIDO (5.96.20): nem um manifest que exponha a tabela de usuarios
 * (ou tokens, sessoes, chaves de pagamento) abre as credenciais para a IA —
 * tabela protegida e coluna de credencial (em coluna, filtro, ordenacao ou
 * escrita) sao recusadas aqui, no unico chokepoint, e o `SELECT *` nunca devolve
 * coluna de credencial. Ver \Mad\Security\ProtectedData.
 */
// NAO-final: McpScopedGateway estende este gateway p/ injetar o WHERE de
// row-scope no unico chokepoint (buildWhere). Ver McpScopedGateway.
class McpTableGateway
{
    private const OPS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'in', 'not in', 'is null', 'is not null'];

    /** Conexao alvo (nome em config/database.php), injetada pelo chokepoint. */
    protected ?string $connection = null;

    /** Define a conexao MAD em que o gateway opera. Fluent. */
    public function on(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    protected function pdo(): PDO
    {
        return DB::connection($this->connection)->getPdo();
    }

    private static function ident(string $name): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Identificador invalido: {$name}");
        }

        return $name;
    }

    /**
     * SELECT parametrizado.
     *
     * @param array<int,string>                 $columns colunas (whitelist do manifest)
     * @param array<int,array{0:string,1:string,2:mixed}> $filters  [col, op, valor]
     * @param array<int,string>                 $allowedOrder colunas validas p/ ORDER BY
     * @return array<int,array<string,mixed>>
     */
    public function select(
        string $table,
        array $columns,
        array $filters = [],
        ?string $order = null,
        array $allowedOrder = [],
        int $limit = 50,
        int $offset = 0
    ): array {
        $table = self::protectedIdent($table);
        foreach ($columns as $c) {
            ProtectedData::assertColumn((string) $c);
        }
        $cols  = $columns === [] ? '*' : implode(', ', array_map([self::class, 'ident'], $columns));

        $sql  = "SELECT {$cols} FROM {$table}";
        [$where, $binds] = $this->buildWhere($filters);
        $sql .= $where;

        $sql .= $this->buildOrder($order, $allowedOrder);

        $limit  = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $sql   .= " LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($binds);

        // `SELECT *` numa tabela do negocio com coluna de credencial: ela nao sai.
        return ProtectedData::stripSecretColumns($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Identificador de tabela validado + recusa de tabela protegida. */
    private static function protectedIdent(string $table): string
    {
        $table = self::ident($table);
        ProtectedData::assertTable($table);

        return $table;
    }

    /**
     * COUNT parametrizado.
     *
     * @param array<int,array{0:string,1:string,2:mixed}> $filters
     */
    public function count(string $table, array $filters = []): int
    {
        $table = self::protectedIdent($table);
        [$where, $binds] = $this->buildWhere($filters);

        $stmt = $this->pdo()->prepare("SELECT COUNT(*) AS c FROM {$table}{$where}");
        $stmt->execute($binds);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * SELECT por chave primaria. Retorna null se nao achar.
     *
     * @param array<int,string> $columns
     * @return array<string,mixed>|null
     */
    public function find(string $table, string $pk, mixed $id, array $columns = []): ?array
    {
        $rows = $this->select($table, $columns, [[$pk, '=', $id]], null, [], 1, 0);

        return $rows[0] ?? null;
    }

    /**
     * INSERT parametrizado. Retorna o id inserido (lastInsertId) quando aplicavel.
     *
     * @param array<string,mixed> $data coluna => valor (colunas do whitelist)
     */
    public function insert(string $table, array $data): string
    {
        $table = self::protectedIdent($table);
        foreach (array_keys($data) as $c) {
            ProtectedData::assertColumn((string) $c);
        }
        if ($data === []) {
            throw new \InvalidArgumentException('INSERT sem dados.');
        }

        $cols  = array_map([self::class, 'ident'], array_keys($data));
        $marks = [];
        $binds = [];
        $i = 0;
        foreach ($data as $value) {
            $p = ':p' . $i++;
            $marks[] = $p;
            $binds[$p] = $this->normalize($value);
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')';
        $this->pdo()->prepare($sql)->execute($binds);

        return $this->pdo()->lastInsertId();
    }

    /**
     * UPDATE por PK. Retorna linhas afetadas.
     *
     * @param array<string,mixed> $data
     */
    public function update(string $table, string $pk, mixed $id, array $data): int
    {
        $table = self::protectedIdent($table);
        foreach (array_keys($data) as $c) {
            ProtectedData::assertColumn((string) $c);
        }
        unset($data[$pk]);
        if ($data === []) {
            return 0;
        }

        $sets  = [];
        $binds = [];
        $i = 0;
        foreach ($data as $col => $value) {
            $col = self::ident((string) $col);
            $p = ':p' . $i++;
            $sets[]    = "{$col} = {$p}";
            $binds[$p] = $this->normalize($value);
        }
        $binds[':id'] = $id;

        $sql  = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . self::ident($pk) . ' = :id';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($binds);

        return $stmt->rowCount();
    }

    /** DELETE por PK. Retorna linhas afetadas. */
    public function delete(string $table, string $pk, mixed $id): int
    {
        $table = self::protectedIdent($table);
        $sql   = 'DELETE FROM ' . $table . ' WHERE ' . self::ident($pk) . ' = :id';
        $stmt  = $this->pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount();
    }

    /**
     * @param array<int,array{0:string,1:string,2:mixed}> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        if ($filters === []) {
            return ['', []];
        }

        $clauses = [];
        $binds   = [];
        $i = 0;
        foreach ($filters as $f) {
            $col = self::ident((string) ($f[0] ?? ''));
            // filtro em coluna de credencial = oraculo (adivinhar o hash por LIKE)
            ProtectedData::assertColumn($col);
            $op  = strtolower(trim((string) ($f[1] ?? '=')));
            $val = $f[2] ?? null;

            if (! in_array($op, self::OPS, true)) {
                throw new \InvalidArgumentException("Operador nao permitido: {$op}");
            }

            // IS NULL / IS NOT NULL: sem bind (PDO nao liga `= NULL`). Usado pelo
            // McpScopedGateway p/ reaplicar soft-delete no path raw do agente.
            if ($op === 'is null' || $op === 'is not null') {
                $clauses[] = "{$col} " . strtoupper($op);
                continue;
            }

            if ($op === 'in' || $op === 'not in') {
                $vals = is_array($val) ? array_values($val) : [$val];
                if ($vals === []) {
                    // IN vazio -> condicao falsa/verdadeira segura
                    $clauses[] = $op === 'in' ? '1=0' : '1=1';
                    continue;
                }
                $marks = [];
                foreach ($vals as $v) {
                    $p = ':p' . $i++;
                    $marks[]   = $p;
                    $binds[$p] = $this->normalize($v);
                }
                $clauses[] = "{$col} " . strtoupper($op) . ' (' . implode(', ', $marks) . ')';
                continue;
            }

            $p = ':p' . $i++;
            $opSql     = $op === 'like' ? 'LIKE' : strtoupper($op);
            $clauses[] = "{$col} {$opSql} {$p}";
            $binds[$p] = $this->normalize($val);
        }

        return [' WHERE ' . implode(' AND ', $clauses), $binds];
    }

    /** @param array<int,string> $allowed */
    private function buildOrder(?string $order, array $allowed): string
    {
        if (empty($order)) {
            return '';
        }

        // "coluna" ou "coluna asc|desc"
        $parts = preg_split('/\s+/', trim($order));
        $col   = $parts[0] ?? '';
        $dir   = strtolower($parts[1] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        if ($allowed !== [] && ! in_array($col, $allowed, true)) {
            return ''; // ignora order nao-whitelisted (degrada gracioso)
        }
        // ordenar por credencial tambem e oraculo (a posicao revela o valor)
        ProtectedData::assertColumn($col);

        return ' ORDER BY ' . self::ident($col) . ' ' . $dir;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        return $value;
    }
}
