<?php

namespace Mad\Rest;

use PDO;
use Pdo\Mysql;
use Pdo\Pgsql;
use Pdo\Sqlite;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Somente leitura imposto PELO BANCO — a defesa de verdade da chave somente
 * leitura do Driver REST. O filtro de texto ({@see RestDriverReadOnlyGuard}) só
 * dá a mensagem amigável; aqui é o engine que recusa a escrita, inclusive a
 * disfarçada (CTE com DELETE, EXPLAIN ANALYZE, função que grava, SELECT INTO).
 *
 * Mesma regra das conexões Direta e por Túnel SSH do Banco de dados do Studio
 * (lá, `ReadOnlySession`). O que cada engine precisa — cada item fechou um
 * contorno reproduzido em banco real (PostgreSQL 17, MySQL 8.4, MariaDB 11):
 *
 *   pgsql   BEGIN TRANSACTION READ ONLY + uma consulta (fixa o snapshot) antes do
 *           SQL do usuário. Depois do primeiro snapshot o PostgreSQL não deixa a
 *           transação voltar a leitura-escrita ("must be set before any query"),
 *           e dentro de um bloco explícito DO/CALL não conseguem dar COMMIT. A
 *           instrução vai pelo protocolo estendido, que o servidor limita a UM
 *           comando; com prepare emulado (o padrão do app hospedado, atrás do
 *           PgBouncer) o PDO usaria o protocolo simples e
 *           `SELECT 'a\'; COMMIT; DELETE ...; --'` gravava. Sem statement
 *           nomeado (PQexecParams): funciona no PgBouncer em modo transação.
 *           Nada é gravado na SESSÃO (sem SET SESSION).
 *
 *   mysql   SET SESSION TRANSACTION READ ONLY + START TRANSACTION READ ONLY.
 *           (MariaDB igual.) Só o START não basta: DDL (TRUNCATE, DROP, ALTER,
 *           CREATE) faz commit implícito ANTES de executar, a transação volta
 *           ao padrão da SESSÃO e o comando executa. A conexão tem de abrir sem
 *           CLIENT_MULTI_STATEMENTS, para o servidor recusar `a; b` num envio —
 *           isso só se escolhe ao conectar, então a trava CONFERE antes de usar.
 *
 *   sqlite  arquivo aberto com SQLITE_OPEN_READONLY (nenhum PRAGMA reabre) +
 *           PRAGMA query_only.
 *
 * Por causa do MySQL (opção de abertura + estado de sessão) e do SQLite (modo
 * de abertura), a chave somente leitura NÃO usa a conexão do app: o controller
 * abre uma conexão própria com {@see connectionConfig()} e a descarta no fim.
 * De quebra, nada do que o SQL do usuário deixar na sessão (variável, trava,
 * `USE outro_banco`) alcança as requisições do app.
 *
 * Tudo aqui FALHA FECHADO: engine desconhecido, servidor que não entende o
 * comando ou trava que não se confirma ⇒ exceção, e o SQL do usuário não roda.
 */
final class RestDriverReadOnlySession
{
    private const UNAVAILABLE = 'Não foi possível abrir a conexão em modo somente-leitura';

    /**
     * Configuração (formato do Laravel) da conexão PRÓPRIA do modo somente
     * leitura, derivada da conexão do app. `null` = não existe segunda conexão
     * possível (SQLite em memória ou por URI): quem chama usa a conexão do app,
     * e a trava do engine fica só com o PRAGMA query_only.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>|null
     */
    public static function connectionConfig(array $config): ?array
    {
        $driver = strtolower((string) ($config['driver'] ?? ''));
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // A nossa opção vem primeiro: vence a do app, se ele tiver ligado.
            $config['options'] = [Mysql::ATTR_MULTI_STATEMENTS => false] + $options;

            return $config;
        }

        if ($driver === 'sqlite') {
            if (self::hasNoSecondHandle((string) ($config['database'] ?? ''))) {
                return null;
            }
            $config['options'] = [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY] + $options;
            // PRAGMAs que o conector aplicaria ao abrir e que ALTERAM o arquivo
            // (um handle somente leitura responde com erro e a conexão nem sobe).
            unset($config['journal_mode'], $config['synchronous'], $config['pragmas']);

            return $config;
        }

        return $config;
    }

    /**
     * Executa $fn — UMA instrução do usuário, preparada com {@see prepare()} —
     * dentro de uma transação que o engine trata como somente leitura, e desfaz
     * a transação no fim. Violação da trava vira a mensagem do modo somente leitura.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function run(PDO $pdo, callable $fn): mixed
    {
        $driver = self::driver($pdo);

        // Antes de tocar na sessão: conexão que aceita `a; b` num envio nem é usada.
        if ($driver === 'mysql') {
            self::assertOneCommandPerSend($pdo);
        }

        try {
            match ($driver) {
                'pgsql' => self::enterPgsql($pdo),
                'mysql' => self::enterMysql($pdo),
                'sqlite' => self::enterSqlite($pdo),
                default => throw new RuntimeException(self::UNAVAILABLE.": o driver '{$driver}' não tem esse modo."),
            };
        } catch (Throwable $e) {
            self::leave($pdo, $driver);
            throw $e instanceof PDOException
                ? new RuntimeException(self::UNAVAILABLE.': '.$e->getMessage(), 0, $e)
                : $e;
        }

        try {
            return $fn();
        } catch (PDOException $e) {
            throw self::translate($e, $driver);
        } finally {
            self::leave($pdo, $driver);
        }
    }

    /**
     * prepare() do SQL do usuário. No PostgreSQL força o protocolo ESTENDIDO
     * (o que faz o servidor recusar mais de um comando), sem statement nomeado.
     */
    public static function prepare(PDO $pdo, string $sql): PDOStatement
    {
        return self::driver($pdo) === 'pgsql'
            ? $pdo->prepare($sql, [PDO::ATTR_EMULATE_PREPARES => false, Pgsql::ATTR_DISABLE_PREPARES => true])
            : $pdo->prepare($sql);
    }

    // ── trava por engine ────────────────────────────────────────────────────

    private static function enterPgsql(PDO $pdo): void
    {
        $pdo->exec('BEGIN TRANSACTION READ ONLY');

        // Fixa o snapshot (a partir daqui a transação não volta a leitura-escrita
        // nem com SET TRANSACTION / set_config()) e CONFERE o modo.
        $mode = $pdo->query("SELECT current_setting('transaction_read_only')")->fetchColumn();
        if ($mode !== 'on') {
            throw new RuntimeException(self::UNAVAILABLE.': o servidor não confirmou a transação somente-leitura.');
        }
    }

    /**
     * O PDO não deixa LER a opção de vários comandos; então pergunta ao
     * servidor: dois comandos num envio TÊM de dar erro de sintaxe. (exec() vai
     * sempre pelo protocolo de texto, com ou sem prepare emulado.)
     */
    private static function assertOneCommandPerSend(PDO $pdo): void
    {
        try {
            $pdo->exec('DO 0; DO 0');
        } catch (PDOException) {
            return;
        }

        throw new RuntimeException(self::UNAVAILABLE.': a conexão aceita vários comandos por envio.');
    }

    private static function enterMysql(PDO $pdo): void
    {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION READ ONLY');
    }

    private static function enterSqlite(PDO $pdo): void
    {
        // O SQLite não tem como PERGUNTAR se o arquivo abriu somente leitura. A
        // trava do arquivo vem de connectionConfig(); esta é a do próprio
        // engine, e dá para conferir — o filtro não deixa passar o PRAGMA que a
        // desligaria.
        $pdo->exec('PRAGMA query_only = ON');
        if ((int) $pdo->query('PRAGMA query_only')->fetchColumn() !== 1) {
            throw new RuntimeException(self::UNAVAILABLE.': o SQLite não confirmou o PRAGMA query_only.');
        }
    }

    /** Desfaz a transação e devolve a sessão como estava. Nunca esconde o erro real. */
    private static function leave(PDO $pdo, string $driver): void
    {
        $statements = match ($driver) {
            'pgsql' => ['ROLLBACK'],
            // A conexão é descartada por quem a abriu; devolver a sessão a
            // leitura-escrita protege quem reaproveitar o PDO mesmo assim.
            'mysql' => ['ROLLBACK', 'SET SESSION TRANSACTION READ WRITE'],
            'sqlite' => ['PRAGMA query_only = OFF'],
            default => [],
        };

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable) {
                // limpeza nunca esconde o erro real (nem o resultado)
            }
        }
    }

    /** Erro do engine que significa "isto escreve" ⇒ mensagem do modo somente leitura. */
    private static function translate(PDOException $e, string $driver): Throwable
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);

        if ($driver === 'pgsql' && $state === '42601' && str_contains($e->getMessage(), 'multiple commands')) {
            return new RuntimeException(RestDriverReadOnlyGuard::MULTI_BLOCKED, 0, $e);
        }

        $refused = $state === '25006'                                   // read-only SQL transaction (pgsql, mysql, mariadb)
            || ($driver === 'mysql' && $code === 1792)                  // ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION
            || ($driver === 'sqlite' && $code === 8)                    // SQLITE_READONLY
            || ($driver === 'pgsql' && in_array($state, [
                '25001',                                                // tentou reabrir a transação como leitura-escrita
                '2D000',                                                // COMMIT/ROLLBACK de dentro de DO/CALL
            ], true));

        if ($refused) {
            return new RuntimeException(RestDriverReadOnlyGuard::WRITE_BLOCKED, 0, $e);
        }

        // Só APRESENTAÇÃO: erros do banco para função indisponível/sem permissão/
        // proibida (que apareciam crus em inglês em tentativas de contorno) saem
        // no mesmo padrão em português. Nenhum deles escreveu. Erro comum de
        // leitura (tabela/coluna, sintaxe) NÃO entra aqui — continua com o texto
        // do banco.
        $refusedByDb = ($driver === 'pgsql' && in_array($state, [
            '42501',   // insufficient_privilege (ex.: pg_read_file sem permissão)
            '2F003',   // prohibited_sql_statement_attempted (dblink sem credencial)
            '42725',   // ambiguous_function
            '42883',   // undefined_function (nome montado/disfarçado que não resolve)
            '38000',   // external_routine_exception
            '38001',   // containing_sql_not_permitted
        ], true))
            || ($driver === 'mysql' && in_array($code, [1227, 1305, 1370], true)); // privilégio/rotina inexistente/EXECUTE negados

        return $refusedByDb
            ? new RuntimeException(RestDriverReadOnlyGuard::DB_REFUSED, 0, $e)
            : $e;
    }

    /**
     * Banco SQLite que não dá para abrir de novo: em memória (`:memory:`,
     * `?mode=memory` — só existe na conexão que o criou) ou por URI `file:` (o
     * modo de abertura vem na própria URI, e o PDO não a lê com o arquivo
     * forçado a somente leitura).
     */
    private static function hasNoSecondHandle(string $database): bool
    {
        return $database === ''
            || str_contains($database, ':memory:')
            || str_contains($database, 'mode=memory')
            || str_starts_with($database, 'file:');
    }

    private static function driver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
}
