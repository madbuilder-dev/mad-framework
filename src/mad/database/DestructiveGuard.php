<?php

namespace Mad\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Migration destrutiva só com confirmação explícita (decisão do dono,
 * 24/11/2026).
 *
 * A migration que o MadBuilder serve ao app (Central de Comando › Consultar o
 * MadBuilder, e o staging do MadCloud) leva um guard em cada passo que apaga
 * tabela ou coluna, ou troca o tipo perdendo dado. Sem confirmação o passo não
 * roda: fica em `mad_destructive_pending` e o resto da migration segue. O
 * cabeçalho `// mad-destructive: {...}` lista os passos ANTES de rodar.
 *
 * Confirmação:
 *  - Central de Comando › Migrations: mostra o que será apagado, com as
 *    linhas de hoje, recomenda o backup e só apaga com "Apagar e migrar";
 *  - `php artisan migrate --allow-destructive`;
 *  - variável de ambiente MAD_ALLOW_DESTRUCTIVE=1 (lida pela própria migration).
 *
 * Confirmar depois de um migrate sem confirmação reexecuta a migration que
 * deixou o passo pendente (a migration incremental leva o delta inteiro e é
 * reexecutável): o passo roda e sai da lista.
 */
final class DestructiveGuard
{
    public const PENDING_TABLE = 'mad_destructive_pending';

    private const HEADER = '~^// mad-destructive: (\{.*\})\s*$~m';

    private static bool $allowed = false;

    public static function allow(bool $on = true): void
    {
        self::$allowed = $on;
    }

    /** Lido pelo guard da migration gerada. */
    public static function allowed(): bool
    {
        return self::$allowed;
    }

    /**
     * Roda $fn com a confirmação ligada e devolve o estado anterior no fim.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public static function confirmed(callable $fn): mixed
    {
        $before = self::$allowed;
        self::$allowed = true;
        try {
            return $fn();
        } finally {
            self::$allowed = $before;
        }
    }

    /**
     * O cabeçalho de uma migration gerada: conexão e passos destrutivos.
     *
     * @return array{conn: ?string, ops: list<array{op: string, table: string, column?: string, detail?: string}>}|null
     */
    public static function header(string $path): ?array
    {
        $head = @file_get_contents($path, false, null, 0, 16384);
        if (! is_string($head) || ! preg_match(self::HEADER, $head, $m)) {
            return null;
        }
        $data = json_decode($m[1], true);
        if (! is_array($data) || ! is_array($data['ops'] ?? null)) {
            return null;
        }
        $ops = [];
        foreach ($data['ops'] as $op) {
            if (is_array($op) && is_string($op['op'] ?? null) && is_string($op['table'] ?? null)) {
                $ops[] = array_intersect_key($op, array_flip(['op', 'table', 'column', 'detail']));
            }
        }

        return ['conn' => is_string($data['conn'] ?? null) ? $data['conn'] : null, 'ops' => $ops];
    }

    /**
     * O que vai ser apagado: os passos das migrations que ainda não rodaram
     * (cabeçalho) e os que já ficaram pendentes num migrate sem confirmação,
     * com as linhas de hoje. Passo cujo alvo não existe mais não entra (nada a
     * apagar).
     *
     * @param  string[]  $pendingFiles  caminhos completos das migrations pendentes
     * @return list<array{op: string, table: string, column: ?string, detail: ?string, conn: string, rows: ?int, migration: string, waiting: bool}>
     */
    public static function overview(array $pendingFiles, ?string $migrationsPath = null): array
    {
        $out = [];
        $seen = [];
        foreach ($pendingFiles as $path) {
            $h = self::header($path);
            if ($h === null) {
                continue;
            }
            $conn = $h['conn'] ?? (string) config('database.default');
            foreach ($h['ops'] as $op) {
                $item = self::item($conn, $op['op'], $op['table'], $op['column'] ?? null, $op['detail'] ?? null, basename($path, '.php'), false);
                if ($item !== null && ! isset($seen[$k = $conn.'|'.$op['op'].'|'.$op['table'].'|'.($op['column'] ?? '')])) {
                    $seen[$k] = true;
                    $out[] = $item;
                }
            }
        }
        foreach (self::waiting($migrationsPath) as $row) {
            $k = $row['conn'].'|'.$row['op'].'|'.$row['table'].'|'.($row['column'] ?? '');
            if (isset($seen[$k])) {
                continue;
            }
            $item = self::item($row['conn'], $row['op'], $row['table'], $row['column'], $row['detail'], $row['migration'], true);
            if ($item !== null) {
                $seen[$k] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Passos que um migrate sem confirmação deixou pendentes, em todas as
     * conexões que as migrations do app usam.
     *
     * @return list<array{conn: string, op: string, table: string, column: ?string, detail: ?string, migration: string}>
     */
    public static function waiting(?string $migrationsPath = null): array
    {
        $out = [];
        foreach (self::connections($migrationsPath) as $conn) {
            try {
                if (! Schema::connection($conn)->hasTable(self::PENDING_TABLE)) {
                    continue;
                }
                foreach (DB::connection($conn)->table(self::PENDING_TABLE)->orderBy('id')->get() as $r) {
                    $migration = (string) $r->migration;
                    $detail = null;
                    $file = rtrim($migrationsPath ?? database_path('migrations'), '/').'/'.$migration;
                    foreach ((self::header($file)['ops'] ?? []) as $op) {
                        if ($op['op'] === $r->op && $op['table'] === $r->tbl && ($op['column'] ?? '') === (string) $r->col) {
                            $detail = $op['detail'] ?? null;
                        }
                    }
                    $out[] = [
                        'conn' => $conn, 'op' => (string) $r->op, 'table' => (string) $r->tbl,
                        'column' => (string) $r->col === '' ? null : (string) $r->col,
                        'detail' => $detail, 'migration' => basename($migration, '.php'),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('[mad-destructive] lista de pendentes', ['conn' => $conn, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Confirma os passos pendentes: reexecuta, com a confirmação ligada, a
     * migration que os deixou. Revisão `_rNNN` superada por uma mais nova do
     * mesmo passo não roda — a lista dela é descartada (a mais nova anotou o
     * que ainda vale quando rodou).
     *
     * @return list<string> migrations reexecutadas
     */
    public static function applyPending(?string $migrationsPath = null): array
    {
        $dir = rtrim($migrationsPath ?? database_path('migrations'), '/');
        $names = array_map(static fn ($f) => basename($f, '.php'), glob($dir.'/*.php') ?: []);
        $ran = [];
        foreach (self::waiting($dir) as $row) {
            $name = $row['migration'];
            if (isset($ran[$name])) {
                continue;
            }
            $ran[$name] = true;
            $file = $dir.'/'.$name.'.php';
            if (! is_file($file) || self::superseded($name, $names)) {
                DB::connection($row['conn'])->table(self::PENDING_TABLE)->where('migration', $name.'.php')->delete();
                unset($ran[$name]);

                continue;
            }
            self::confirmed(static function () use ($file) {
                $migration = require $file;
                $migration->up();
            });
        }

        return array_keys($ran);
    }

    /** Linhas de hoje: da tabela, ou preenchidas na coluna. Null quando não dá para contar. */
    public static function rows(string $conn, string $table, ?string $column = null): ?int
    {
        try {
            $q = DB::connection($conn)->table($table);
            if ($column !== null) {
                $q->whereNotNull($column);
            }

            return (int) $q->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{op: string, table: string, column: ?string, detail: ?string, conn: string, rows: ?int, migration: string, waiting: bool}|null */
    private static function item(string $conn, string $op, string $table, ?string $column, ?string $detail, string $migration, bool $waiting): ?array
    {
        try {
            $schema = Schema::connection($conn);
            if (! $schema->hasTable($table) || ($column !== null && ! $schema->hasColumn($table, $column))) {
                return null; // nada a apagar
            }
        } catch (\Throwable) {
            return null;
        }

        return [
            'op' => $op, 'table' => $table, 'column' => $column, 'detail' => $detail, 'conn' => $conn,
            'rows' => self::rows($conn, $table, $column), 'migration' => $migration, 'waiting' => $waiting,
        ];
    }

    /** Conexões citadas nos cabeçalhos das migrations do app, mais a padrão. */
    private static function connections(?string $migrationsPath): array
    {
        $conns = [(string) config('database.default') => true];
        foreach (glob(rtrim($migrationsPath ?? database_path('migrations'), '/').'/*.php') ?: [] as $file) {
            $c = self::header($file)['conn'] ?? null;
            if (is_string($c) && $c !== '' && config("database.connections.{$c}") !== null) {
                $conns[$c] = true;
            }
        }

        return array_keys($conns);
    }

    /** `…_v1_to_v2_r001` com uma `…_v1_to_v2_r002` no disco. */
    private static function superseded(string $name, array $names): bool
    {
        if (! preg_match('/^(.*)_r(\d{3})$/', $name, $m)) {
            return false;
        }
        foreach ($names as $other) {
            if (preg_match('/^(.*)_r(\d{3})$/', $other, $o) && self::family($o[1]) === self::family($m[1]) && (int) $o[2] > (int) $m[2]) {
                return true;
            }
        }

        return false;
    }

    /** O passo sem a data/sequência do dia (`update_app_v1_to_v2`). */
    private static function family(string $base): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $base);
    }
}
