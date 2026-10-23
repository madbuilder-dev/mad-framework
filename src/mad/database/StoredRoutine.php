<?php

namespace Mad\Database;

use ArrayObject;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * StoredRoutine — chama stored procedure e function do banco sem o app ter
 * que conhecer o dialeto de cada engine.
 *
 *   StoredRoutine::call('sp_fechar_mes', [$mes]);         // linhas da procedure
 *   StoredRoutine::value('fn_saldo', [$contaId]);         // escalar da function
 *   StoredRoutine::rows('fn_extrato', [$contaId], 'pg');  // function que devolve tabela (PG)
 *
 * O nome passa pela regex antes de entrar no SQL (é o único pedaço interpolado);
 * os argumentos vão sempre por binding. Argumento associativo vale pela ordem.
 * Conexão `null` = a default do app.
 *
 * MySQL/MariaDB: `CALL` devolve um result set por SELECT da procedure e mais um
 * de status. Quem não drena todos com `nextRowset()` deixa a conexão em
 * "Commands out of sync" e a próxima query morre — por isso `call` usa o PDO
 * direto em vez do `select()` do Laravel. PostgreSQL: `CALL` devolve a linha dos
 * parâmetros OUT/INOUT (que também precisam de argumento, ex.: `null`).
 */
final class StoredRoutine
{
    /** Identificador simples ou `schema.nome`, até 63 caracteres cada parte. */
    public const NAME_PATTERN = '/^(?:[A-Za-z_][A-Za-z0-9_]{0,62}\.)?[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    /** Binding `scoped` do cache dos modos de parâmetro (MySQL). */
    private const CACHE = 'mad.stored_routine.modes';

    /**
     * Executa uma stored procedure e devolve as linhas do primeiro result set.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function call(string $procedure, array $args = [], ?string $connection = null): array
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();
        $sql = self::sql('call', $procedure, count($args), $driver);

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return self::callMysql($db, $procedure, $args);
        }

        return self::toArrays($db->select($sql, array_values($args), false));
    }

    /**
     * Executa uma function escalar (`SELECT fn(?) AS value`) e devolve o valor.
     */
    public static function value(string $function, array $args = [], ?string $connection = null): mixed
    {
        $db = DB::connection($connection);
        $sql = self::sql('value', $function, count($args), $db->getDriverName());
        $rows = self::toArrays($db->select($sql, array_values($args), false));

        return $rows[0]['value'] ?? null;
    }

    /**
     * Executa uma function que devolve tabela (`SELECT * FROM fn(?)`, PostgreSQL).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $function, array $args = [], ?string $connection = null): array
    {
        $db = DB::connection($connection);
        $sql = self::sql('rows', $function, count($args), $db->getDriverName());

        return self::toArrays($db->select($sql, array_values($args), false));
    }

    /**
     * Monta o SQL da chamada para a engine. Público para teste.
     *
     * @param  'call'|'value'|'rows'  $mode
     */
    public static function sql(string $mode, string $name, int $argc, string $driver): string
    {
        $name = self::assertName($name);
        $placeholders = implode(', ', array_fill(0, $argc, '?'));
        $isMysql = $driver === 'mysql' || $driver === 'mariadb';

        return match ($mode) {
            'call' => match (true) {
                $isMysql, $driver === 'pgsql' => "CALL {$name}({$placeholders})",
                $driver === 'sqlite' => throw new RuntimeException(
                    'SQLite não tem stored procedures; use MySQL, MariaDB ou PostgreSQL.'
                ),
                default => throw new RuntimeException("Stored procedure não suportada no banco [{$driver}]."),
            },
            'value' => "SELECT {$name}({$placeholders}) AS value",
            'rows' => match (true) {
                $driver === 'pgsql' => "SELECT * FROM {$name}({$placeholders})",
                $isMysql => throw new RuntimeException(
                    'Function do MySQL/MariaDB não devolve tabela; crie uma procedure e use StoredRoutine::call().'
                ),
                $driver === 'sqlite' => throw new RuntimeException(
                    'SQLite não tem functions que devolvem tabela; use PostgreSQL.'
                ),
                default => throw new RuntimeException("Function que devolve tabela não suportada no banco [{$driver}]."),
            },
            default => throw new InvalidArgumentException("Modo de chamada desconhecido [{$mode}]."),
        };
    }

    /** Valida o nome da procedure/function (é o único trecho que entra no SQL sem binding). */
    public static function assertName(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                "Nome de procedure/function inválido [{$name}]: use letras, números e _ (até 63), opcionalmente schema.nome."
            );
        }

        return $name;
    }

    /**
     * Plano do CALL no MySQL/MariaDB a partir dos modos dos parâmetros. OUT e
     * INOUT não aceitam `?` (erro 1414): viram a variável `@mad_out_N` (N = posição),
     * o INOUT recebe o valor num `SET` antes, e um `SELECT` depois lê as variáveis
     * com o nome do parâmetro. OUT no fim da lista pode ser omitido nos args.
     * Público para teste.
     *
     * @param  array<int, array{mode: string, name: string}>  $params  na ordem da procedure
     * @return array{set: ?string, set_bindings: array, call: string, call_bindings: array, out: ?string}
     */
    public static function mysqlCallPlan(string $procedure, array $args, array $params): array
    {
        $name = self::assertName($procedure);
        $args = array_values($args);
        $count = count($args);
        while ($count < count($params) && strtoupper($params[$count]['mode']) === 'OUT') {
            $count++;
        }

        $placeholders = $callBindings = $sets = $setBindings = $outs = [];
        for ($i = 0; $i < $count; $i++) {
            $mode = strtoupper($params[$i]['mode'] ?? 'IN');
            if ($mode === 'IN') {
                $placeholders[] = '?';
                $callBindings[] = $args[$i] ?? null;

                continue;
            }
            $var = '@mad_out_' . ($i + 1);
            $placeholders[] = $var;
            if ($mode === 'INOUT') {
                $sets[] = "{$var} = ?";
                $setBindings[] = $args[$i] ?? null;
            }
            $outs[] = $var . ' AS `' . str_replace('`', '``', $params[$i]['name']) . '`';
        }

        return [
            'set' => $sets === [] ? null : 'SET ' . implode(', ', $sets),
            'set_bindings' => $setBindings,
            'call' => "CALL {$name}(" . implode(', ', $placeholders) . ')',
            'call_bindings' => $callBindings,
            'out' => $outs === [] ? null : 'SELECT ' . implode(', ', $outs),
        ];
    }

    /** Limpa o cache dos modos de parâmetro (ex.: depois de recriar a procedure). */
    public static function flushCache(): void
    {
        self::modes()->exchangeArray([]);
    }

    /**
     * Modos dos parâmetros por conexão+banco+nome. `scoped`: o Laravel joga fora
     * a cada request do Octane e a cada job da fila, então uma procedure
     * recriada entre duas requisições nunca é chamada com a assinatura velha.
     */
    private static function modes(): ArrayObject
    {
        $app = Container::getInstance();
        if (! $app->bound(self::CACHE)) {
            $app->scoped(self::CACHE, static fn () => new ArrayObject());
        }

        return $app->make(self::CACHE);
    }

    /**
     * CALL no MySQL/MariaDB pelo PDO: lê o 1º result set e drena os demais.
     * Com OUT/INOUT, sem result set da procedure a resposta é a linha dos
     * parâmetros de saída (mesma forma do PostgreSQL). Os modos ficam em cache
     * na requisição; se a procedure foi recriada no meio dela e o CALL falha
     * por isso (1318: outro número de argumentos; 1414: OUT sem variável),
     * relê e tenta uma vez de novo. Recriada com o mesmo número de parâmetros
     * na mesma requisição: chame flushCache().
     */
    private static function callMysql(Connection $db, string $procedure, array $args): array
    {
        $modes = self::modes();
        $key = $db->getName() . '|' . $db->getDatabaseName() . '|' . strtolower($procedure);
        $cached = isset($modes[$key]);
        if (! $cached) {
            $modes[$key] = self::mysqlParams($db, $procedure);
        }

        try {
            return self::runMysqlPlan($db, self::mysqlCallPlan($procedure, $args, $modes[$key]));
        } catch (QueryException $e) {
            if (! $cached || ! in_array($e->errorInfo[1] ?? null, [1414, 1318], true)) {
                throw $e;
            }
            $modes[$key] = self::mysqlParams($db, $procedure);

            return self::runMysqlPlan($db, self::mysqlCallPlan($procedure, $args, $modes[$key]));
        }
    }

    /** @return array<int, array{mode: string, name: string}> */
    private static function mysqlParams(Connection $db, string $procedure): array
    {
        [$schema, $name] = str_contains($procedure, '.') ? explode('.', $procedure, 2) : [null, $procedure];
        $rows = $db->select(
            'SELECT PARAMETER_MODE AS mode, PARAMETER_NAME AS name FROM information_schema.PARAMETERS '
            . "WHERE SPECIFIC_SCHEMA = COALESCE(?, DATABASE()) AND SPECIFIC_NAME = ? AND ROUTINE_TYPE = 'PROCEDURE' "
            . 'AND ORDINAL_POSITION > 0 ORDER BY ORDINAL_POSITION',
            [$schema, $name],
            false
        );

        return array_map(static fn ($r) => ['mode' => (string) $r->mode, 'name' => (string) $r->name], $rows);
    }

    private static function runMysqlPlan(Connection $db, array $plan): array
    {
        if ($plan['set'] !== null) {
            $db->statement($plan['set'], $plan['set_bindings']);
        }
        [$rows, $hadResultSet] = self::runMysqlCall($db, $plan['call'], $plan['call_bindings']);
        if ($plan['out'] === null || $hadResultSet) {
            return $rows;
        }

        return self::toArrays($db->select($plan['out'], [], false));
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: bool} linhas do 1º result set e se houve algum */
    private static function runMysqlCall(Connection $db, string $sql, array $bindings): array
    {
        $bindings = $db->prepareBindings($bindings);
        $start = microtime(true);

        try {
            $stmt = $db->getPdo()->prepare($sql);
            $db->bindValues($stmt, $bindings);
            $stmt->execute();

            $rows = [];
            $hadResultSet = false;
            do {
                if ($stmt->columnCount() > 0) {
                    $set = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (! $hadResultSet) {
                        $rows = $set;
                        $hadResultSet = true;
                    }
                }
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
        } catch (PDOException $e) {
            throw new QueryException($db->getName(), $sql, $bindings, $e);
        }

        $db->logQuery($sql, $bindings, round((microtime(true) - $start) * 1000, 2));

        return [$rows, $hadResultSet];
    }

    /** @return array<int, array<string, mixed>> */
    private static function toArrays(array $rows): array
    {
        return array_map(static fn ($row) => (array) $row, $rows);
    }
}
