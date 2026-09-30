<?php

namespace Mad\Chart;

/**
 * Formatadores padrão para <mad-db-chart>.
 *
 * Uso: MadChartFormatter::make('currency:R$:2')
 *      → retorna closure (mixed $v): string
 *
 * Formats numericos:
 *   integer
 *   numeric           numeric:DEC[:DECIMAL[:THOUSAND]]
 *   currency          currency:PREFIX[:DEC[:DECIMAL[:THOUSAND]]]
 *   percent           percent[:DEC]
 *   abbreviate        abbreviate[:DEC]      → 1,5K / 2,3M / 1,2B
 *
 * Formats data (input aceita Y-m-d, Y-m-d H:i:s, Y-m, Y, ou timestamp):
 *   date              → 15/04/2026
 *   date-short        → 15/04
 *   date-long         → 15 de abril de 2026
 *   datetime          → 15/04/2026 14:30
 *   time              → 14:30
 *   year              → 2026
 *   month             → Abril
 *   month-short       → Abr
 *   month-year        → Abril/2026
 *   month-year-short  → Abr/26
 *   weekday           → Segunda-feira
 *   weekday-short     → Seg
 *   quarter           → 2o tri
 *   quarter-year      → Q2/2026
 *
 * Suffix livre: append ":SUFIXO" em qualquer formato numerico.
 *   numeric:0::: kg  → 1.234 kg
 */
final class MadChartFormatter
{
    /** Meses PT-BR (1-based). */
    private const MESES = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];

    private const MESES_CURTO = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    /** Dias semana PT-BR (0=Dom). */
    private const DIAS = [
        0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira',
        3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado',
    ];

    private const DIAS_CURTO = [
        0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb',
    ];

    /**
     * Cria closure de formatacao a partir de string de formato.
     *
     * @param string $format Ex: 'currency:R$:2'
     * @return callable(mixed): string
     */
    public static function make(string $format): callable
    {
        $parts = explode(':', $format);
        $type  = strtolower(trim($parts[0]));

        return match ($type) {
            'integer', 'int' => static fn($v) => self::fmtInteger($v),

            'numeric', 'number' => static fn($v) => self::fmtNumeric(
                $v,
                (int)($parts[1] ?? 2),
                $parts[2] ?? ',',
                $parts[3] ?? '.',
                $parts[4] ?? ''
            ),

            'currency', 'money' => static fn($v) => self::fmtCurrency(
                $v,
                $parts[1] ?? 'R$ ',
                (int)($parts[2] ?? 2),
                $parts[3] ?? ',',
                $parts[4] ?? '.'
            ),

            'percent', 'pct' => static fn($v) => self::fmtPercent(
                $v,
                (int)($parts[1] ?? 1)
            ),

            'abbreviate', 'abbr', 'short' => static fn($v) => self::fmtAbbreviate(
                $v,
                (int)($parts[1] ?? 1)
            ),

            'date'             => static fn($v) => self::fmtDate($v, 'd/m/Y'),
            'date-short'       => static fn($v) => self::fmtDate($v, 'd/m'),
            'date-long'        => static fn($v) => self::fmtDateLong($v),
            'datetime'         => static fn($v) => self::fmtDate($v, 'd/m/Y H:i'),
            'time'             => static fn($v) => self::fmtDate($v, 'H:i'),
            'year'             => static fn($v) => self::fmtYear($v),
            'month'            => static fn($v) => self::fmtMonth($v, false),
            'month-short'      => static fn($v) => self::fmtMonth($v, true),
            'month-year'       => static fn($v) => self::fmtMonthYear($v, false),
            'month-year-short' => static fn($v) => self::fmtMonthYear($v, true),
            'weekday'          => static fn($v) => self::fmtWeekday($v, false),
            'weekday-short'    => static fn($v) => self::fmtWeekday($v, true),
            'quarter'          => static fn($v) => self::fmtQuarter($v, false),
            'quarter-year'     => static fn($v) => self::fmtQuarter($v, true),

            default => static fn($v) => (string) $v,
        };
    }

    // ── Numericos ───────────────────────────────────────────────────────────

    private static function fmtInteger(mixed $v): string
    {
        if ($v === null || $v === '') return '';
        return number_format((float) $v, 0, ',', '.');
    }

    private static function fmtNumeric(mixed $v, int $dec, string $decSep, string $thouSep, string $suffix): string
    {
        if ($v === null || $v === '') return '';
        $out = number_format((float) $v, $dec, $decSep, $thouSep);
        return $suffix !== '' ? "{$out} {$suffix}" : $out;
    }

    private static function fmtCurrency(mixed $v, string $prefix, int $dec, string $decSep, string $thouSep): string
    {
        if ($v === null || $v === '') return '';
        $prefix = rtrim($prefix) . ' ';
        return $prefix . number_format((float) $v, $dec, $decSep, $thouSep);
    }

    private static function fmtPercent(mixed $v, int $dec): string
    {
        if ($v === null || $v === '') return '';
        return number_format((float) $v, $dec, ',', '.') . '%';
    }

    private static function fmtAbbreviate(mixed $v, int $dec): string
    {
        if ($v === null || $v === '') return '';
        $n = (float) $v;
        $abs = abs($n);

        if ($abs >= 1_000_000_000) return self::trimZero(number_format($n / 1_000_000_000, $dec, ',', '.')) . 'B';
        if ($abs >= 1_000_000)     return self::trimZero(number_format($n / 1_000_000, $dec, ',', '.'))     . 'M';
        if ($abs >= 1_000)         return self::trimZero(number_format($n / 1_000, $dec, ',', '.'))         . 'K';

        return number_format($n, 0, ',', '.');
    }

    private static function trimZero(string $s): string
    {
        if (!str_contains($s, ',')) return $s;
        return rtrim(rtrim($s, '0'), ',');
    }

    // ── Datas ───────────────────────────────────────────────────────────────

    /**
     * Parse permissivo: aceita timestamp, Y-m-d, Y-m, Y, d/m/Y, datetime ISO etc.
     * Retorna array [Y, m, d, H, i, s] ou null.
     */
    private static function parse(mixed $v): ?array
    {
        if ($v === null || $v === '') return null;

        // numero puro: ano (4 digitos) ou timestamp
        if (is_numeric($v)) {
            $n = (int) $v;
            if ($n >= 1900 && $n <= 2999 && (string) $n === (string) $v) {
                return [$n, 1, 1, 0, 0, 0];
            }
            if ($n > 10000) {
                return self::tsToParts($n);
            }
        }

        $s = trim((string) $v);

        // Y-m (chave de agrupamento por mes)
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $s, $m)) {
            return [(int)$m[1], (int)$m[2], 1, 0, 0, 0];
        }

        // Y-m-d ou Y-m-d H:i:s
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $s, $m)) {
            return [(int)$m[1], (int)$m[2], (int)$m[3], (int)($m[4]??0), (int)($m[5]??0), (int)($m[6]??0)];
        }

        // d/m/Y
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
            return [(int)$m[3], (int)$m[2], (int)$m[1], 0, 0, 0];
        }

        // m/Y
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $s, $m)) {
            return [(int)$m[2], (int)$m[1], 1, 0, 0, 0];
        }

        // fallback: strtotime
        $ts = strtotime($s);
        if ($ts !== false) {
            return self::tsToParts($ts);
        }

        return null;
    }

    private static function tsToParts(int $ts): array
    {
        return [
            (int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts),
            (int) date('G', $ts), (int) date('i', $ts), (int) date('s', $ts),
        ];
    }

    private static function fmtDate(mixed $v, string $php): string
    {
        $p = self::parse($v);
        if (!$p) return (string) $v;
        $ts = mktime($p[3], $p[4], $p[5], $p[1], $p[2], $p[0]);
        return date($php, $ts);
    }

    private static function fmtDateLong(mixed $v): string
    {
        $p = self::parse($v);
        if (!$p) return (string) $v;
        $mes = strtolower(self::MESES[$p[1]] ?? '');
        return sprintf('%d de %s de %d', $p[2], $mes, $p[0]);
    }

    private static function fmtYear(mixed $v): string
    {
        $p = self::parse($v);
        return $p ? (string) $p[0] : (string) $v;
    }

    private static function fmtMonth(mixed $v, bool $short): string
    {
        $p = self::parse($v);
        if (!$p) return (string) $v;
        return $short ? (self::MESES_CURTO[$p[1]] ?? '') : (self::MESES[$p[1]] ?? '');
    }

    private static function fmtMonthYear(mixed $v, bool $short): string
    {
        $p = self::parse($v);
        if (!$p) return (string) $v;
        if ($short) {
            return (self::MESES_CURTO[$p[1]] ?? '') . '/' . substr((string) $p[0], -2);
        }
        return (self::MESES[$p[1]] ?? '') . '/' . $p[0];
    }

    private static function fmtWeekday(mixed $v, bool $short): string
    {
        $p = self::parse($v);
        if (!$p) return (string) $v;
        $ts = mktime(0, 0, 0, $p[1], $p[2], $p[0]);
        $dow = (int) date('w', $ts);
        return $short ? (self::DIAS_CURTO[$dow] ?? '') : (self::DIAS[$dow] ?? '');
    }

    private static function fmtQuarter(mixed $v, bool $withYear): string
    {
        $p = self::parse($v);
        if (!$p) return (string) $v;
        $q = (int) ceil($p[1] / 3);
        return $withYear ? "Q{$q}/{$p[0]}" : "{$q}º tri";
    }
}
