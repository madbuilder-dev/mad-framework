<?php

declare(strict_types=1);

namespace Mad\Support;

/**
 * HolidayProvider — de onde o {@see BusinessDays} tira os feriados.
 *
 * O app gerado define o seu criando `App\Services\HolidayProvider` (code_create
 * kind=php-service) que implementa esta interface: o framework acha a classe por
 * convenção, sem editar `config/mad.php`. Quem prefere configurar aponta
 * `mad.calendar.holiday_provider` (FQCN) ou liga a interface no container.
 *
 * Contrato:
 *  - devolve os feriados do ANO pedido, `['Y-m-d' => 'Nome']` (lista simples de
 *    datas também é aceita; o nome fica vazio);
 *  - `$unitId` é a unidade (filial) do registro: feriado estadual/municipal só
 *    entra quando vale para ela; `null` = só os que valem para todos;
 *  - feriado que cai em fim de semana pode vir na lista (não muda a conta).
 *
 * Para somar aos feriados nacionais, componha com
 * {@see \Mad\Support\Holidays\BrazilNationalHolidays}:
 *
 *   $lista = (new BrazilNationalHolidays())->holidays($year, $unitId);
 *   foreach (Feriado::whereYear('data', $year)->get() as $f) { ... }
 */
interface HolidayProvider
{
    /** @return array<string,string> 'Y-m-d' => nome do feriado */
    public function holidays(int $year, ?int $unitId = null): array;
}
