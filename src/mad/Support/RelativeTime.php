<?php

namespace Mad\Support;

/**
 * Data relativa curta para quem lê um feed ("agora", "há 5 min", "há 3 h",
 * "ontem", "há 4 dias") — o `date-relative` do `<mad-comments>` (fw#484).
 *
 * Só para o passado recente: até `$days` dias de calendário. Mais antigo, ou
 * no futuro além de um minuto (relógio do banco adiantado), devolve null e
 * quem chama mostra a data completa.
 */
final class RelativeTime
{
    /** Dias de calendário que `date-relative` sem número cobre. */
    public const DEFAULT_DAYS = 7;

    public static function label(\DateTimeInterface $when, \DateTimeInterface $now, int $days = self::DEFAULT_DAYS): ?string
    {
        if ($days <= 0) {
            return null;
        }
        $seconds = $now->getTimestamp() - $when->getTimestamp();
        if ($seconds < -60) {
            return null;
        }
        if ($seconds < 60) {
            return self::t('relative_now');
        }
        if ($seconds < 3600) {
            return self::t('relative_minutes', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return self::t('relative_hours', intdiv($seconds, 3600));
        }

        // Dias de CALENDÁRIO no fuso de `$now`: ontem às 23h, lido hoje às 9h
        // do dia seguinte, é "ontem" — não "há 1 dia" nem "há 34 h".
        $tz = $now->getTimezone();
        $today = \DateTimeImmutable::createFromInterface($now)->setTimezone($tz)->setTime(0, 0);
        $day = \DateTimeImmutable::createFromInterface($when)->setTimezone($tz)->setTime(0, 0);
        $diff = (int) $day->diff($today)->days;
        if ($diff > $days) {
            return null;
        }

        return $diff <= 1 ? self::t('relative_yesterday') : self::t('relative_days', $diff);
    }

    /**
     * `date-relative` da tag → dias: pelado/`true` = {@see DEFAULT_DAYS},
     * número = esses dias, ausente/`false`/`0` = desligado.
     */
    public static function days(mixed $prop): int
    {
        if ($prop === null || $prop === false) {
            return 0;
        }
        if ($prop === true) {
            return self::DEFAULT_DAYS;
        }
        $v = strtolower(trim((string) $prop));
        if (is_numeric($v)) {
            return max(0, (int) $v);
        }

        return in_array($v, ['', 'true', 'yes', 'sim', 'date-relative'], true) ? self::DEFAULT_DAYS : 0;
    }

    private static function t(string $key, int $n = 0): string
    {
        return (string) mad_t('mad.tempo.' . $key, ['n' => $n]);
    }
}
