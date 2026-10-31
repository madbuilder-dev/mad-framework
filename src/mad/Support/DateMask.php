<?php

namespace Mad\Support;

use Mad\I18n\MadLang;

/**
 * Máscara de data da TELA pelo idioma do app: inglês mostra mês/dia/ano,
 * português e espanhol dia/mês/ano. É o padrão de `<mad-date-field>` e
 * `<mad-datetime-field>` sem `display-mask`, e a gravação lê o texto com a
 * mesma máscara que o campo exibiu (MadFormRegistry::transformField) — antes
 * a gravação lia sempre dia/mês e um campo em mês/dia trocava os dois.
 */
final class DateMask
{
    /** Máscara de data da tela (ex.: `dd/mm/yyyy`, `mm/dd/yyyy`). */
    public static function date(?string $locale = null): string
    {
        return self::monthFirst($locale) ? 'mm/dd/yyyy' : 'dd/mm/yyyy';
    }

    /** Máscara de data e hora da tela (ex.: `dd/mm/yyyy hh:ii`). */
    public static function datetime(?string $locale = null): string
    {
        return self::date($locale) . ' hh:ii';
    }

    /** O idioma escreve o mês antes do dia? (só o inglês, entre os do framework). */
    public static function monthFirst(?string $locale = null): bool
    {
        $locale = strtolower(str_replace('_', '-', $locale ?? MadLang::getLocale()));

        return explode('-', $locale)[0] === 'en';
    }

    /**
     * Máscara MAD → formato do `date()` do PHP. `mm` = mês e `ii`/`MM` =
     * minuto (convenção do seletor e do banco).
     */
    public static function toPhp(string $mask): string
    {
        return strtr($mask, [
            'yyyy' => 'Y', 'yy' => 'y',
            'mm' => 'm', 'MM' => 'i',
            'dd' => 'd',
            'hh' => 'H', 'HH' => 'H',
            'ii' => 'i',
            'ss' => 's', 'SS' => 's',
        ]);
    }
}
