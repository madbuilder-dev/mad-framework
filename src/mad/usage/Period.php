<?php

namespace Mad\Usage;

/**
 * Period — calculo da janela de inicio de um periodo de cota.
 *
 *   day    → meia-noite de hoje (local).
 *   month  → dia `reset_day` do mes corrente (ou do mes anterior se hoje ainda
 *            nao chegou no reset_day). reset_day clampado a [1..28] p/ evitar
 *            skew de fevereiro / mes curto.
 *   total  → null (sem limite inferior; soma tudo).
 *
 * Retorna string 'Y-m-d H:i:s' (mesmo formato gravado em created_at) p/
 * comparacao lexicografica direta no SQL.
 */
final class Period
{
    public static function windowStart(string $period, ?int $resetDay = null, ?int $now = null): ?string
    {
        $now = $now ?? time();

        if ($period === 'total') {
            return null;
        }

        if ($period === 'day') {
            return date('Y-m-d 00:00:00', $now);
        }

        // month
        $d = (int) ($resetDay ?: 1);
        if ($d < 1) {
            $d = 1;
        }
        if ($d > 28) {
            $d = 28;
        }

        $startThis = mktime(0, 0, 0, (int) date('n', $now), $d, (int) date('Y', $now));
        $start = ($now >= $startThis) ? $startThis : strtotime('-1 month', $startThis);

        return date('Y-m-d H:i:s', $start);
    }
}
