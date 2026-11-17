<?php

namespace Mad\Support;

use Mad\Sheet\SheetColumn;

/**
 * "Validar documento" dos campos de CPF e CNPJ (fórum #341).
 *
 * Opção do campo (`validate-document`), desligada por padrão, que só vale nas
 * máscaras de documento: `cpf`, `cnpj` e `cpfcnpj` (os dois no mesmo campo).
 * Ligada, o Salvar recusa o documento com dígito verificador errado
 * (MadForm::validate) e o navegador avisa ao sair do campo (mad-ui.js,
 * `_madDocProblem`, que espelha esta conta).
 *
 * A conta não é nova: é a que o framework já usa na planilha
 * (SheetColumn::cpfCheckDigitsOk) e no CNPJ, numérico ou alfanumérico
 * (MadCnpj::isValid). As duas recusam a sequência repetida (000.000.000-00,
 * 11.111.111/1111-11), que passa no módulo 11 mas nunca é documento real.
 *
 * Vazio é válido aqui: quem exige o preenchimento é o `required`.
 */
final class MadDocument
{
    /** Máscaras de documento que aceitam a validação. */
    public const KINDS = ['cpf', 'cnpj', 'cpfcnpj'];

    /** O tipo de documento da máscara (`cpf`, `cnpj`, `cpfcnpj`), ou '' quando ela não é de documento. */
    public static function kind(string $mask): string
    {
        $kind = strtolower(trim($mask));

        return in_array($kind, self::KINDS, true) ? $kind : '';
    }

    /**
     * A opção `validate-document` está ligada? Nua ou verdadeira liga;
     * "false"/"0"/"" desliga (mesmo contrato das flags do <mad-field-list>).
     */
    public static function enabled(mixed $flag): bool
    {
        if (is_string($flag)) {
            return !in_array(strtolower(trim($flag)), ['', 'false', '0', 'no', 'off'], true);
        }

        return (bool) $flag;
    }

    /** O documento é válido para o tipo? Vazio é válido (quem exige é o `required`). */
    public static function isValid(string $value, string $kind): bool
    {
        return self::problem($value, $kind) === null;
    }

    /**
     * null quando o valor é válido (ou vazio); senão a chave da mensagem:
     * `mad.form.bad_cpf`, `mad.form.bad_cnpj` ou `mad.form.bad_cpf_cnpj`.
     */
    public static function problem(string $value, string $kind): ?string
    {
        $kind = self::kind($kind);
        if ($kind === '' || trim($value) === '') {
            return null;
        }

        $alnum   = MadCnpj::sanitize($value);
        $isDigit = $alnum !== '' && ctype_digit($alnum);

        if ($kind === 'cpf') {
            return $isDigit && SheetColumn::cpfCheckDigitsOk($alnum) ? null : 'mad.form.bad_cpf';
        }
        if ($kind === 'cnpj') {
            return MadCnpj::isValid($alnum) ? null : 'mad.form.bad_cnpj';
        }

        // cpfcnpj: 11 dígitos é CPF; 14 caracteres é CNPJ (pode ter letras).
        if ($isDigit && strlen($alnum) === 11) {
            return SheetColumn::cpfCheckDigitsOk($alnum) ? null : 'mad.form.bad_cpf';
        }
        if (strlen($alnum) === 14) {
            return MadCnpj::isValid($alnum) ? null : 'mad.form.bad_cnpj';
        }

        return 'mad.form.bad_cpf_cnpj';
    }
}
