<?php

declare(strict_types=1);

namespace Mad\Support\Holidays;

use Mad\Support\HolidayProvider;

/**
 * Feriados nacionais do Brasil — provedor padrão do {@see \Mad\Support\BusinessDays}
 * quando o app não define o seu.
 *
 * Fixos (Lei 662/1949 e seguintes, Consciência Negra pela Lei 14.759/2023, a
 * partir de 2024) e móveis calculados pela Páscoa: Carnaval (segunda e terça),
 * Sexta-feira Santa e Corpus Christi. Carnaval e Corpus Christi são ponto
 * facultativo na lei, mas o comércio e os escritórios param — é o que um prazo
 * em dias úteis espera. Quem não quer um deles compõe e tira da lista.
 *
 * Não depende da extensão `calendar` (easter_days): a Páscoa sai do algoritmo
 * de Meeus/Jones/Butcher, válido para o calendário gregoriano.
 */
class BrazilNationalHolidays implements HolidayProvider
{
    /** @var array<int, array<string,string>> */
    private static array $cache = [];

    public function holidays(int $year, ?int $unitId = null): array
    {
        return self::$cache[$year] ??= self::compute($year);
    }

    /** Domingo de Páscoa do ano, 'Y-m-d'. */
    public static function easter(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @return array<string,string> */
    private static function compute(int $year): array
    {
        $fixed = [
            '01-01' => 'Confraternização Universal',
            '04-21' => 'Tiradentes',
            '05-01' => 'Dia do Trabalho',
            '09-07' => 'Independência do Brasil',
            '10-12' => 'Nossa Senhora Aparecida',
            '11-02' => 'Finados',
            '11-15' => 'Proclamação da República',
            '12-25' => 'Natal',
        ];
        if ($year >= 2024) {
            $fixed['11-20'] = 'Dia Nacional de Zumbi e da Consciência Negra';
        }

        $list = [];
        foreach ($fixed as $md => $name) {
            $list[$year . '-' . $md] = $name;
        }

        $easter = new \DateTimeImmutable(self::easter($year));
        $moving = [
            '-48 days' => 'Carnaval',
            '-47 days' => 'Carnaval',
            '-2 days'  => 'Sexta-feira Santa',
            '+60 days' => 'Corpus Christi',
        ];
        foreach ($moving as $offset => $name) {
            $list[$easter->modify($offset)->format('Y-m-d')] = $name;
        }

        ksort($list);

        return $list;
    }
}
