<?php

namespace Mad\Form;

/**
 * OutsideOption — a opção do valor que o campo JÁ TEM e que a lista não mostra.
 *
 * Um campo de tabela (DB Combo, DB Select, DB Unique Search, DB Radio) monta as
 * opções com o que QUEM ESTÁ LOGADO enxerga: os cadastros da unidade e da
 * empresa dele, os usuários que ele vê, o filtro do próprio campo, o que não
 * foi excluído. O registro aberto pode apontar para fora dessa lista — o
 * responsável que o administrador definiu é de outra unidade, o setor foi
 * desativado, o cadastro foi excluído.
 *
 * Sem uma opção para esse valor o campo abria em "Selecione...", ia VAZIO na
 * requisição e o Salvar apagava o que estava gravado, sem ninguém ter mexido
 * no campo e sem aviso (fw#169).
 *
 * ## A regra
 *
 * 1. O valor que o campo tem e a lista não mostra continua no campo, numa
 *    opção própria, selecionada: o Salvar que não mexe no campo devolve o
 *    mesmo valor. Vale para qualquer motivo de ele estar fora da lista —
 *    outra unidade, outra empresa, usuário que a pessoa não enxerga, filtro
 *    do campo, registro inativo, excluído ou que não existe mais, e a lista
 *    que não carregou.
 * 2. O rótulo é NEUTRO e o mesmo para todos os motivos: o campo não lê nada
 *    do registro que está fora da lista (nem para saber se ele existe), então
 *    não há nome, e-mail ou "esse número existe" para vazar a quem não pode
 *    ver.
 * 3. A opção só existe enquanto é o valor do campo: não é oferecida em outro
 *    registro nem no cadastro novo. Quem edita pode trocar por um item da
 *    lista ou limpar o campo, como sempre.
 * 4. Campo sem valor não ganha opção: `''`, `null` e o `0` que `empty-as="zero"`
 *    (ou uma coluna NOT NULL DEFAULT 0) grava no lugar do vazio.
 *
 * QUEM confere se um valor pode ser gravado continua sendo a regra de
 * validação do Model (`Rule::exists`): esta classe só cuida de o campo não
 * perder o que já estava lá.
 *
 * No navegador a opção leva `data-mad-synthetic`, a mesma marca da opção que o
 * MAD Select cria para um valor sem `<option>` (mad-ui.js, `_ensureOption`):
 * se o rótulo de verdade chegar depois (`addOption`), ele entra no lugar do
 * neutro.
 */
final class OutsideOption
{
    /** Atributos da `<option>` / do rádio que guarda o valor fora da lista. */
    public const ATTRS = 'data-mad-outside data-mad-synthetic';

    /**
     * O campo tem um valor que as opções desenhadas não têm?
     *
     * `$options` é a lista do campo (chave => rótulo). A comparação é a mesma
     * que marca o `selected` da opção: por string, exata.
     *
     * @param array<int|string, mixed> $options
     */
    public static function applies(mixed $value, array $options): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $value = (string) $value;
        if ($value === '' || $value === '0') {
            return false;
        }

        foreach ($options as $key => $_) {
            if ((string) $key === $value) {
                return false;
            }
        }

        return true;
    }

    /** Chave do rótulo no catálogo do framework (`Mad\I18n\MadLang`). */
    public const TEXT_KEY = 'mad.field.outside_option';

    /**
     * O rótulo quando o catálogo do idioma não traz a chave (catálogo de uma
     * versão anterior, ou trocado pelo app).
     */
    private const TEXT = [
        'pt'    => 'Registro atual (fora da sua lista)',
        'pt-PT' => 'Registo atual (fora da sua lista)',
        'en'    => 'Current record (not in your list)',
        'es'    => 'Registro actual (fuera de su lista)',
    ];

    /** O rótulo neutro da opção, no idioma do app. */
    public static function label(): string
    {
        try {
            $text = \Mad\I18n\MadLang::t(self::TEXT_KEY);
            if ($text !== '' && $text !== self::TEXT_KEY) {
                return $text;
            }
            $locale = \Mad\I18n\MadLang::getLocale();
        } catch (\Throwable) {
            $locale = 'pt';
        }

        // Idioma sem texto próprio: inglês, como o catálogo (MadLang::FALLBACK).
        return self::TEXT[$locale] ?? self::TEXT[explode('-', $locale)[0]] ?? self::TEXT['en'];
    }
}
