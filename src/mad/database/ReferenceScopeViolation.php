<?php

namespace Mad\Database;

/**
 * Gravação de uma chave que aponta para um registro que quem grava não
 * enxerga: de outra empresa, de uma unidade que não é do usuário, ou que não
 * existe (fw#531).
 *
 * Lançada pelo `saving` dos models com escopo de linha (BelongsToTenant /
 * BelongsToUnit) — ver {@see ScopedReferences}. O MadForm::save() converte em
 * erro no campo da chave; um serviço que grava com `create()`/`save()` recebe
 * a exceção como qualquer outra exceção de domínio, com o texto pronto para a
 * tela ("A opção escolhida em Paciente não está na sua lista ou não existe
 * mais."). O texto não diz se o registro existe em outra empresa: para quem
 * grava, ele simplesmente não está disponível.
 */
class ReferenceScopeViolation extends \DomainException
{
    public function __construct(
        public readonly string $column,
        public readonly int|string $value,
        public readonly string $label,
    ) {
        parent::__construct(self::text($label));
    }

    /** "A opção escolhida em <rótulo> não está na sua lista ou não existe mais." */
    public static function text(string $label): string
    {
        $fallback = 'A opção escolhida em :field não está na sua lista ou não existe mais.';
        try {
            $text = function_exists('mad_t') ? (string) mad_t('mad.form.option_invalid_in', ['field' => $label]) : '';
        } catch (\Throwable) {
            $text = '';
        }
        if ($text === '' || $text === 'mad.form.option_invalid_in') {
            $text = str_replace(':field', $label, $fallback);
        }

        return $text;
    }
}
