<?php
namespace Mad\Util;

/**
 * MadSqlCollector — Buffer de SQL executados na request atual.
 *
 * Alimentado pela bridge de APM (DB::listen em MadServiceProvider::bootTrace,
 * via recordTimed) e, quando um slog está configurado, por
 * SystemSqlLogService::write() (record).
 *
 * Lido por MadDumpModal::collectRequestMeta() para mostrar os SQLs
 * executados na modal de debug (mad_dump_modal / mdm).
 */
class MadSqlCollector
{
    /** @var array<int,array{sql:string,db:string,type:string,time:string,duration_ms:?float}> */
    private static array $queries = [];

    /** @var float|null */
    private static ?float $lastStart = null;

    /**
     * Queries já registradas pela bridge do capsule (recordTimed) e ainda não
     * "consumidas" pelo caminho slog. Os dois listeners disparam no MESMO
     * QueryExecuted, em ordem fixa (bridge registra no boot do capsule, antes
     * do attachQueryListener do Transaction) — quando o slog chama record()
     * logo após um recordTimed, é a MESMA query: consome o crédito e pula,
     * senão ela entraria dobrada no buffer e no APM.
     */
    private static int $bridgedPending = 0;

    private const MAX = 200;

    public static function record(string $sql, ?string $database = null): void
    {
        if (self::$bridgedPending > 0) {
            self::$bridgedPending--;
            return;
        }

        if (count(self::$queries) >= self::MAX) return;

        $now  = microtime(true);
        $dur  = self::$lastStart !== null ? round(($now - self::$lastStart) * 1000, 2) : null;
        self::$lastStart = $now;

        $type = strtoupper(strtok(ltrim($sql), " \t\n("));

        self::$queries[] = [
            'sql'         => $sql,
            'db'          => $database ?? '',
            'type'        => $type,
            'time'        => date('H:i:s'),
            'duration_ms' => $dur,
        ];

        // APM (MadTrace) — push de precisao (src/timing real) so quando a
        // request esta sendo coletada (apmActive). Fora de amostra: ~zero custo.
        if (class_exists('MadTrace') && \MadTrace::apmActive()) {
            \MadTrace::recordQuery($sql, $database);
        }
    }

    /**
     * Linha com duração JÁ medida (bridge DB::listen → QueryExecuted, que
     * dispara pós-exec). Não toca o gap-timing ($lastStart) do caminho slog
     * e NÃO re-chama MadTrace::recordQuery — a bridge já registrou no APM
     * com o tempo real; aqui só alimenta a modal de debug e o fallback de
     * collectQueries() das requests não-amostradas.
     */
    public static function recordTimed(string $sql, ?string $database, float $durationMs): void
    {
        self::$bridgedPending++;

        if (count(self::$queries) >= self::MAX) return;

        self::$queries[] = [
            'sql'         => $sql,
            'db'          => $database ?? '',
            'type'        => strtoupper(strtok(ltrim($sql), " \t\n(")),
            'time'        => date('H:i:s'),
            'duration_ms' => round($durationMs, 2),
        ];
    }

    /** @return array<int,array> */
    public static function all(): array
    {
        return self::$queries;
    }

    public static function count(): int
    {
        return count(self::$queries);
    }

    public static function clear(): void
    {
        self::$queries = [];
        self::$lastStart = null;
        self::$bridgedPending = 0;
    }

    public static function totalDuration(): float
    {
        $sum = 0.0;
        foreach (self::$queries as $q) $sum += $q['duration_ms'] ?? 0;
        return round($sum, 2);
    }
}
