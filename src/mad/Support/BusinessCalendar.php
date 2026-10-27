<?php

declare(strict_types=1);

namespace Mad\Support;

/**
 * BusinessCalendar — calendário de trabalho próprio para o {@see BusinessDays}:
 * os dias da semana úteis e os feriados de quem o define (um projeto, um
 * contrato, uma equipe), em vez dos da unidade e do `mad.calendar.working_days`.
 *
 *   $cal = new BusinessCalendar('projeto:12', [1, 2, 3, 4, 5, 6], fn (int $ano) => $feriados[$ano] ?? []);
 *   BusinessDays::addBusinessDays($inicio, 10, $cal);   // sábado conta
 *   BusinessDays::isBusinessDay('2026-11-02', $cal);
 *
 * Vai em qualquer método do BusinessDays no lugar do `$unitId`.
 *
 *  - `key` identifica o calendário no cache de feriados: mesma chave, mesma lista
 *    por {@see BusinessDays::CACHE_TTL} segundos. Calendários diferentes precisam
 *    de chaves diferentes (ex.: 'projeto:12', 'calendario:3:unidade:5').
 *  - `workingDays`: 0 = domingo … 6 = sábado (o mesmo número do `working-days`
 *    do Gantt). Vazio ou só valores inválidos = segunda a sexta.
 *  - `holidays($ano)` devolve `['Y-m-d' => 'Nome']` (lista simples de datas
 *    também serve). Sem a função, o calendário não tem feriado. Para somar aos
 *    nacionais, componha com {@see \Mad\Support\Holidays\BrazilNationalHolidays}.
 *    Se a função falhar, a conta usa os feriados nacionais (com aviso no log).
 */
final class BusinessCalendar
{
    /** @var list<int> */
    public readonly array $workingDays;

    /**
     * @param iterable<int|string> $workingDays
     * @param (\Closure(int): array<int|string, mixed>)|null $holidays
     */
    public function __construct(
        public readonly string $key,
        iterable $workingDays = [1, 2, 3, 4, 5],
        private readonly ?\Closure $holidays = null,
    ) {
        $days = [];
        foreach ($workingDays as $d) {
            if (is_numeric($d) && (int) $d >= 0 && (int) $d <= 6) {
                $days[(int) $d] = true;
            }
        }
        $days = array_keys($days);
        sort($days);
        $this->workingDays = $days !== [] ? $days : [1, 2, 3, 4, 5];
    }

    /** Feriados do ano, como a função do calendário os devolve (o BusinessDays normaliza). */
    public function holidays(int $year): array
    {
        return $this->holidays !== null ? (array) ($this->holidays)($year) : [];
    }
}
