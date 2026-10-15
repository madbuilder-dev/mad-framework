<?php

namespace Mad\Routine;

use Mad\I18n\MadLang;

/**
 * Textos das rotinas para o usuário, pelo catálogo do framework
 * (`mad.routine.*`). Chave ausente no catálogo (app com um catálogo antigo,
 * idioma sem a chave) cai no texto em português: nunca aparece a chave crua.
 *
 * @internal
 */
final class RoutineText
{
    private const PT = [
        'not_installed'      => 'As rotinas ainda não foram instaladas neste banco. Publique o projeto de novo ou rode `php artisan mad:routine:install` para criar a tabela que guarda o que cada rotina gerou.',
        'origin_not_found'   => 'O registro de origem não foi encontrado.',
        'in_use'             => 'Um registro gerado por esta rotina já está em uso e não pode ser desfeito.',
        'too_many_loops'     => 'A rotina passou do limite de :max repetições num laço e foi interrompida. Nada foi gravado.',
        'too_many_entries'   => 'A rotina passou do limite de :max registros numa execução e foi interrompida. Nada foi gravado.',
        'split_parts'        => 'O rateio precisa de pelo menos uma parte.',
        'no_source'          => 'Esta rotina não tem origem para refazer ou desfazer.',
        'unknown'            => 'A rotina ":slug" não existe ou não pode ser executada por botão.',
        'unknown_mode'       => 'Modo de execução desconhecido: ":mode".',
        'save_first'         => 'Salve o registro antes de executar a rotina.',
        'failed_title'       => 'A rotina não foi executada',
        'failed'             => 'Não foi possível executar a rotina. Tente de novo ou avise o administrador.',
        'nothing'            => 'Nada a fazer.',
        'created_first_one'  => '1 registro criado',
        'created_first_many' => ':n registros criados',
        'updated_first_one'  => '1 registro atualizado',
        'updated_first_many' => ':n registros atualizados',
        'updated_one'        => '1 atualizado',
        'updated_many'       => ':n atualizados',
        'accumulated_first_one'  => '1 valor acumulado',
        'accumulated_first_many' => ':n valores acumulados',
        'accumulated_one'    => '1 acumulado',
        'accumulated_many'   => ':n acumulados',
        'reversed_first_one'  => '1 registro desfeito',
        'reversed_first_many' => ':n registros desfeitos',
        'reversed_one'       => '1 desfeito',
        'reversed_many'      => ':n desfeitos',
    ];

    /** @param array<string, scalar> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $full = 'mad.routine.' . $key;
        try {
            $text = MadLang::t($full, $replace);
        } catch (\Throwable) {
            $text = $full;
        }
        if ($text !== '' && $text !== $full) {
            return $text;
        }

        $text = self::PT[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }
}
