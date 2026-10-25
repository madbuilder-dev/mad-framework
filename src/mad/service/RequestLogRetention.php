<?php

namespace Mad\Service;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Retenção do log de requisições (Logs › Requisições, `mad_log_request`) —
 * fw#419. O log guarda login, IP e corpo dos envios; sem prazo, a tabela
 * crescia sem limite.
 *
 * Prazo em dias: o que o dono escolheu na tela (preferência
 * `request_log_days`) vence; sem escolha, `MAD_REQUEST_LOG_DAYS` do `.env`.
 * Vazio ou 0 = guarda tudo, como antes.
 *
 * A limpeza não varre a data: `logdate` é texto e não tem índice. Como o log
 * só cresce, o id acompanha a data — a fronteira (o maior id mais antigo que o
 * prazo) sai por busca binária pela chave primária, e o apagar vai em fatias
 * de id, sem carregar linhas. Agendada todo dia às 03:15 pelo framework
 * (`mad:request-log:purge`).
 */
final class RequestLogRetention
{
    public const PREFERENCE = 'request_log_days';
    public const TABLE      = 'mad_log_request';
    public const CONNECTION = 'log';

    /** Teto do prazo: dez anos. */
    public const MAX_DAYS = 3650;

    /** Prazo em vigor, em dias (0 = guarda tudo). */
    public static function days(): int
    {
        return self::normalize(self::preference() ?? config('mad.general.request_log_days', ''));
    }

    /** '' / 0 / texto = 0 (guarda tudo); acima do teto = o teto. */
    public static function normalize(mixed $raw): int
    {
        $raw = trim((string) $raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return 0;
        }

        return min((int) $raw, self::MAX_DAYS);
    }

    /** O prazo escolhido na tela (null ou '' = sem escolha: vale o `.env`). */
    public static function preference(): ?string
    {
        try {
            $value = class_exists(\App\Models\Sys\Preference::class)
                ? \App\Models\Sys\Preference::getPreference(self::PREFERENCE)
                : DB::connection('iam')->table('mad_sys_preference')->where('id', self::PREFERENCE)->value('preference');
        } catch (\Throwable) {
            return null;
        }

        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    /**
     * Apaga o que passou do prazo. Devolve quantas linhas saíram (no
     * `$dryRun`, quantas sairiam).
     */
    public static function purge(?int $days = null, int $batch = 5000, bool $dryRun = false): int
    {
        $days = $days ?? self::days();
        if ($days <= 0) {
            return 0;
        }

        $conn     = DB::connection(self::CONNECTION);
        $cutoff   = now()->subDays($days)->format('Y-m-d H:i:s');
        $boundary = self::boundaryId($conn, $cutoff);
        if ($boundary === null) {
            return 0;
        }

        $from = (int) $conn->table(self::TABLE)->min('id');
        if ($dryRun) {
            return $conn->table(self::TABLE)->whereBetween('id', [$from, $boundary])->count();
        }

        $batch   = max(100, $batch);
        $deleted = 0;
        while ($from <= $boundary) {
            $to       = min($from + $batch - 1, $boundary);
            $deleted += $conn->table(self::TABLE)->whereBetween('id', [$from, $to])->delete();
            $from     = $to + 1;
        }

        return $deleted;
    }

    /**
     * Maior id com `logdate` anterior ao corte (null = nada a apagar). Cada
     * passo lê UMA linha pela chave primária — o id pode ter buracos.
     */
    public static function boundaryId(ConnectionInterface $conn, string $cutoff): ?int
    {
        $lo = (int) $conn->table(self::TABLE)->min('id');
        $hi = (int) $conn->table(self::TABLE)->max('id');
        if ($hi <= 0 && $lo <= 0) {
            return null;
        }

        $best = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $row = $conn->table(self::TABLE)
                ->where('id', '>=', $mid)->where('id', '<=', $hi)
                ->orderBy('id')->first(['id', 'logdate']);
            if ($row === null) {
                $hi = $mid - 1;
                continue;
            }
            if ((string) ($row->logdate ?? '') < $cutoff) {
                $best = (int) $row->id;
                $lo   = $best + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best;
    }
}
