<?php

namespace Mad\Ui;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Mad\Form\MadUniqueViolation;
use Mad\Form\MadValidator;

/**
 * MadDbErrorMessage — o que dizer ao USUÁRIO quando o banco recusa uma gravação.
 *
 * O banco responde em SQL ("SQLSTATE[23000] … UNIQUE constraint failed:
 * mad_iam_user.email (Connection: iam, SQL: insert into …)"). Quem usa o app
 * precisa de outra coisa: saber se dá para resolver sozinho e como. Aqui a
 * recusa é classificada e vira uma frase:
 *
 *   valor repetido        → "O campo E-mail já está sendo utilizado."
 *   obrigatório vazio     → "O campo Nome é obrigatório."
 *   registro em uso       → "Não foi possível excluir o registro. Ele pode estar em uso…"
 *   referência que sumiu  → "Um dos itens escolhidos não existe mais…"
 *   texto grande demais   → "O campo Observação tem mais texto do que o permitido."
 *   valor em outro formato→ "O valor do campo Data não está no formato esperado."
 *   banco ocupado         → "O sistema está ocupado no momento…"
 *   banco fora do ar      → "Não foi possível acessar o banco de dados agora…"
 *
 * O NOME DO CAMPO só aparece quando a tela tem um campo para a coluna (o rótulo
 * vem do formulário). Coluna que a tela não tem não é citada — nome de coluna é
 * detalhe interno — e a frase fica sem o campo. O que não se encaixa em nenhum
 * caso devolve `null`: quem chama mostra o aviso genérico.
 *
 * Nenhuma frase traz SQL, valor, nome de tabela ou de conexão.
 */
final class MadDbErrorMessage
{
    public const UNIQUE      = 'unique';
    public const REQUIRED    = 'required';
    public const IN_USE      = 'in_use';
    public const MISSING_REF = 'missing_ref';
    public const TOO_LONG    = 'too_long';
    public const INVALID     = 'invalid';
    public const BUSY        = 'busy';
    public const UNAVAILABLE = 'unavailable';

    /**
     * Frase para o usuário, ou null quando o erro não é uma recusa conhecida
     * do banco.
     *
     * @param array<string,string> $labels coluna => rótulo do campo na tela
     */
    public static function for(\Throwable $e, array $labels = []): ?string
    {
        $found = self::classify($e);
        if ($found === null) {
            return null;
        }

        $label = self::labelFor($found['columns'], $labels);

        return match ($found['kind']) {
            self::UNIQUE      => $label !== null
                ? MadValidator::ruleMessage('unique', $label)
                : self::catalog('mad.error.duplicate', 'Já existe um registro com estes dados.'),
            self::REQUIRED    => $label !== null
                ? MadValidator::ruleMessage('required', $label)
                : self::app('mad.db_required', 'Um campo obrigatório ficou sem valor. Confira o preenchimento e tente de novo.'),
            self::IN_USE      => self::catalog(
                'mad.error.delete_failed',
                'Não foi possível excluir o registro. Ele pode estar em uso em outro cadastro; se continuar, avise o administrador.'
            ),
            self::MISSING_REF => self::app('mad.db_missing_ref', 'Um dos itens escolhidos não existe mais. Atualize a tela e tente de novo.'),
            self::TOO_LONG    => $label !== null
                ? self::app('mad.db_too_long_field', 'O campo :attribute tem mais texto do que o permitido.', ['attribute' => $label])
                : self::app('mad.db_too_long', 'Um dos campos tem mais texto do que o permitido. Reduza o texto e tente de novo.'),
            self::INVALID     => $label !== null
                ? self::app('mad.db_invalid_field', 'O valor do campo :attribute não está no formato esperado.', ['attribute' => $label])
                : self::app('mad.db_invalid', 'Um dos valores informados não está no formato esperado. Confira os campos e tente de novo.'),
            self::BUSY        => self::app('mad.db_busy', 'O sistema está ocupado no momento. Aguarde alguns segundos e tente de novo.'),
            self::UNAVAILABLE => self::app('mad.db_unavailable', 'Não foi possível acessar o banco de dados agora. Tente de novo em instantes; se continuar, avise o administrador.'),
            default           => null,
        };
    }

    /** "Registro não encontrado." */
    public static function notFound(): string
    {
        return self::app('mad.record_not_found', 'Registro não encontrado.');
    }

    /**
     * Que recusa foi, e em quais colunas (quando o banco diz).
     *
     * @return array{kind:string,columns:list<string>}|null
     */
    public static function classify(\Throwable $e): ?array
    {
        $db = self::databaseException($e);
        if ($db === null) {
            return null;
        }

        $driver  = self::driverException($db);
        $state   = self::sqlState($driver, $db);
        $code    = self::driverCode($driver);
        $message = $driver->getMessage();
        $lower   = strtolower($message);
        $sql     = $db instanceof QueryException ? strtolower(ltrim((string) $db->getSql())) : '';

        // ── valor repetido ──────────────────────────────────────────────
        if ($db instanceof UniqueConstraintViolationException
            || $state === '23505' || $code === 1062 || $code === 2627 || $code === 2601
            || str_contains($lower, 'unique constraint failed')
            || str_contains($lower, 'is not unique')) {
            $columns = $db instanceof UniqueConstraintViolationException
                ? MadUniqueViolation::columns($db)
                : [];

            return ['kind' => self::UNIQUE, 'columns' => $columns ?: self::columnsFrom($message, [
                '/UNIQUE constraint failed: (.+)$/im',
                '/Key \(([^)]+)\)=/i',
            ])];
        }

        // ── obrigatório sem valor ───────────────────────────────────────
        if ($state === '23502' || $code === 1048 || $code === 1364 || $code === 515
            || str_contains($lower, 'not null constraint failed')) {
            return ['kind' => self::REQUIRED, 'columns' => self::columnsFrom($message, [
                '/NOT NULL constraint failed: (.+)$/im',
                '/null value in column "([^"]+)"/i',
                "/Column '([^']+)' cannot be null/i",
                "/Field '([^']+)' doesn't have a default value/i",
                "/NULL into column '([^']+)'/i",
            ])];
        }

        // ── chave estrangeira ───────────────────────────────────────────
        if ($state === '23503' || $code === 1451 || $code === 1452 || $code === 1216 || $code === 1217 || $code === 547
            || str_contains($lower, 'foreign key constraint')) {
            $inUse = $code === 1451 || $code === 1217
                || str_contains($lower, 'update or delete on table')
                || str_contains($lower, 'is still referenced')
                || str_contains($lower, 'the delete statement')
                || ($code !== 1452 && $code !== 1216 && str_starts_with($sql, 'delete'));

            return ['kind' => $inUse ? self::IN_USE : self::MISSING_REF, 'columns' => []];
        }

        // ── texto maior do que a coluna ─────────────────────────────────
        if ($state === '22001' || $code === 1406 || $code === 8152 || $code === 2628
            || str_contains($lower, 'value too long') || str_contains($lower, 'data too long')) {
            return ['kind' => self::TOO_LONG, 'columns' => self::columnsFrom($message, [
                "/Data too long for column '([^']+)'/i",
            ])];
        }

        // ── valor que a coluna não aceita ───────────────────────────────
        if (in_array($state, ['22003', '22007', '22008', '22018', '22P02', '23514'], true)
            || in_array($code, [1264, 1265, 1292, 1366, 3819, 4025], true)
            || str_contains($lower, 'check constraint failed')
            || str_contains($lower, 'datatype mismatch')) {
            return ['kind' => self::INVALID, 'columns' => self::columnsFrom($message, [
                "/for column '([^']+)'/i",
                "/for column `[^`]*`\\.`[^`]*`\\.`([^`]+)`/i",
            ])];
        }

        // ── banco ocupado: tentar de novo resolve ───────────────────────
        if ($db instanceof \Illuminate\Database\DeadlockException
            || in_array($state, ['40001', '40P01', '55P03'], true)
            || $code === 1205 || $code === 1213
            || str_contains($lower, 'database is locked')
            || str_contains($lower, 'database table is locked')
            || str_contains($lower, 'deadlock')
            || str_contains($lower, 'lock wait timeout')) {
            return ['kind' => self::BUSY, 'columns' => []];
        }

        // ── banco fora do ar ────────────────────────────────────────────
        if ($db instanceof \Illuminate\Database\LostConnectionException
            || str_starts_with($state, '08')
            || in_array($state, ['53300', '57P01', '57P02', '57P03'], true)
            || in_array($code, [1040, 1203, 2002, 2003, 2006, 2013], true)
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'server has gone away')
            || str_contains($lower, 'could not connect')
            || str_contains($lower, 'lost connection')
            || str_contains($lower, 'unable to open database file')
            || str_contains($lower, 'too many connections')
            || str_contains($lower, 'getaddrinfo')) {
            return ['kind' => self::UNAVAILABLE, 'columns' => []];
        }

        return null;
    }

    // ── Internos ────────────────────────────────────────────────────────

    /** A exceção de banco na cadeia (a mais externa: é a que tem o SQL). */
    private static function databaseException(\Throwable $e): ?\Throwable
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException) { // QueryException estende PDOException
                return $x;
            }
        }

        return null;
    }

    /** A exceção do DRIVER (a mais interna): a mensagem dela não tem o SQL. */
    private static function driverException(\Throwable $db): \Throwable
    {
        $driver = $db;
        for ($x = $db->getPrevious(); $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException) {
                $driver = $x;
            }
        }

        return $driver;
    }

    private static function sqlState(\Throwable $driver, \Throwable $db): string
    {
        foreach ([$driver, $db] as $x) {
            $info = $x instanceof \PDOException ? ($x->errorInfo ?? null) : null;
            if (is_array($info) && isset($info[0]) && is_string($info[0]) && strlen($info[0]) === 5) {
                return strtoupper($info[0]);
            }
        }
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/i', $driver->getMessage(), $m)) {
            return strtoupper($m[1]);
        }
        $code = (string) $driver->getCode();

        return strlen($code) === 5 ? strtoupper($code) : '';
    }

    private static function driverCode(\Throwable $driver): int
    {
        $info = $driver instanceof \PDOException ? ($driver->errorInfo ?? null) : null;
        if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
            return (int) $info[1];
        }
        // "SQLSTATE[HY000] [2002] Connection refused" / "…: 1062 Duplicate entry"
        if (preg_match('/SQLSTATE\[[0-9A-Z]{5}\](?:\s*\[(\d+)\]|[^:]*:\s*(\d+)\s)/i', $driver->getMessage(), $m)) {
            return (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0));
        }

        return 0;
    }

    /**
     * Colunas citadas na mensagem do driver, sem a tabela (`tabela.coluna`).
     *
     * @param  list<string> $patterns
     * @return list<string>
     */
    private static function columnsFrom(string $message, array $patterns): array
    {
        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $message, $m)) {
                continue;
            }
            $columns = [];
            foreach (explode(',', $m[1]) as $raw) {
                $raw = trim($raw, " \t\n\r\"`'[]");
                $dot = strrpos($raw, '.');
                $col = trim($dot === false ? $raw : substr($raw, $dot + 1), " \"`'[]");
                if ($col !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $col)) {
                    $columns[] = $col;
                }
            }
            if ($columns !== []) {
                return $columns;
            }
        }

        return [];
    }

    /**
     * Rótulo do campo da tela para a primeira coluna que a tela TEM. Coluna sem
     * campo não vira nome na frase.
     *
     * @param list<string>         $columns
     * @param array<string,string> $labels
     */
    private static function labelFor(array $columns, array $labels): ?string
    {
        foreach ($columns as $column) {
            $label = trim((string) ($labels[$column] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    /** Texto do catálogo do pacote (`mad_t`), com reserva. */
    private static function catalog(string $key, string $fallback): string
    {
        try {
            $value = mad_t($key);
        } catch (\Throwable) {
            return $fallback;
        }

        // Chave ausente no catálogo o mad_t devolve a própria chave.
        return ($value !== '' && $value !== $key) ? $value : $fallback;
    }

    /**
     * Texto do `lang/<idioma>/mad.php` do app, com reserva embutida: um app
     * publicado antes desta versão não tem a chave, e `__()` devolveria a
     * própria chave na tela.
     *
     * @param array<string,string> $replace
     */
    private static function app(string $key, string $fallback, array $replace = []): string
    {
        $value = $fallback;
        try {
            if (function_exists('__')) {
                $found = __($key);
                if (is_string($found) && $found !== '' && $found !== $key) {
                    $value = $found;
                }
            }
        } catch (\Throwable) {
            // sem tradutor (teste de unidade, boot incompleto): fica a reserva
        }
        foreach ($replace as $name => $text) {
            $value = str_replace(':' . $name, $text, $value);
        }

        return $value;
    }
}
