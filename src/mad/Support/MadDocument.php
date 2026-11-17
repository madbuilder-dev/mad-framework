<?php

namespace Mad\Support;

use Mad\Sheet\SheetColumn;

/**
 * "Validar documento" dos campos (fórum #341).
 *
 * Opção do campo (`validate-document`), desligada por padrão. Ligada, o Salvar
 * recusa o documento com formato ou dígito verificador errado
 * (MadForm::validate) e o navegador avisa ao sair do campo (mad-ui.js,
 * `_madDocProblem`, que espelha estas contas).
 *
 * Tipos:
 *   - pela máscara (`validate-document` nua, 5.323): `cpf`, `cnpj`, `cpfcnpj`;
 *   - explícitos (`validate-document="tipo"`, 5.324): os três acima e
 *     `cns` (Cartão SUS), `renavam`, `vin` (chassi), `placa` e `pix` (chave Pix).
 *
 * Só confere formato e dígito — nada é consultado fora (Receita, Detran, DICT).
 * CPF/CNPJ usam a conta que o framework já tinha (SheetColumn::cpfCheckDigitsOk,
 * MadCnpj::isValid) e recusam a sequência repetida (000.000.000-00). A placa usa
 * o padrão da máscara `placa` (MadMask).
 *
 * Vazio é válido aqui: quem exige o preenchimento é o `required`.
 */
final class MadDocument
{
    /** Máscaras de documento: a opção nua (`validate-document`) pega o tipo delas. */
    public const MASK_KINDS = ['cpf', 'cnpj', 'cpfcnpj'];

    /** Todos os tipos aceitos em `validate-document="tipo"`. */
    public const KINDS = ['cpf', 'cnpj', 'cpfcnpj', 'cns', 'renavam', 'vin', 'placa', 'pix'];

    /** Pesos do dígito verificador do chassi (posição 9 = índice 8, peso 0). */
    private const VIN_WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Valor de cada letra no dígito do chassi (I, O e Q não existem). */
    private const VIN_LETTERS = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    /** O tipo aceito (`cpf`, `cns`, `pix`…), ou '' quando não é um deles. */
    public static function kind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return in_array($kind, self::KINDS, true) ? $kind : '';
    }

    /**
     * O tipo que o campo confere: `validate-document="cns"` (explícito) vale
     * sozinho; a opção nua/verdadeira pega o tipo da máscara (cpf, cnpj,
     * cpfcnpj); desligada ou tipo desconhecido = '' (nada muda).
     */
    public static function resolve(mixed $flag, string $mask): string
    {
        if (is_string($flag)) {
            if (($explicit = self::kind($flag)) !== '') {
                return $explicit;
            }
            // Só "true"/"1"/"yes"/"on" ligam pela máscara; um tipo que o
            // framework não conhece (ex.: um tipo de versão mais nova) desliga.
            if (!in_array(strtolower(trim($flag)), ['true', '1', 'yes', 'on'], true)) {
                return '';
            }
        }
        if (!self::enabled($flag)) {
            return '';
        }
        $fromMask = strtolower(trim($mask));

        return in_array($fromMask, self::MASK_KINDS, true) ? $fromMask : '';
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
     * null quando o valor é válido (ou vazio); senão a chave da mensagem
     * (`mad.form.bad_cpf`, `bad_cnpj`, `bad_cpf_cnpj`, `bad_cns`, `bad_renavam`,
     * `bad_vin`, `bad_placa`, `bad_pix`). A mensagem nunca leva o valor.
     */
    public static function problem(string $value, string $kind): ?string
    {
        $kind = self::kind($kind);
        if ($kind === '' || trim($value) === '') {
            return null;
        }

        switch ($kind) {
            case 'cns':
                return self::cnsOk($value) ? null : 'mad.form.bad_cns';
            case 'renavam':
                return self::renavamOk($value) ? null : 'mad.form.bad_renavam';
            case 'vin':
                return self::vinOk($value) ? null : 'mad.form.bad_vin';
            case 'placa':
                return MadMask::plateMatches(strtoupper(trim($value)), 'placa') ? null : 'mad.form.bad_placa';
            case 'pix':
                return self::pixOk($value) ? null : 'mad.form.bad_pix';
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

    /**
     * Cartão Nacional de Saúde (15 dígitos), regra do DATASUS: definitivo
     * (começa com 1 ou 2) = PIS de 11 dígitos + "000"/"001" + dígito; provisório
     * (7, 8 ou 9) = soma dos dígitos × (15 − posição) divisível por 11.
     */
    public static function cnsOk(string $value): bool
    {
        $d = preg_replace('/[\s.\-]/', '', $value);
        if (!is_string($d) || !preg_match('/^\d{15}$/', $d)) {
            return false;
        }
        $first = $d[0];
        if ($first === '1' || $first === '2') {
            $pis = substr($d, 0, 11);
            $sum = 0;
            for ($i = 0; $i < 11; $i++) {
                $sum += (int) $pis[$i] * (15 - $i);
            }
            $dv = 11 - ($sum % 11);
            if ($dv === 11) {
                $dv = 0;
            }
            if ($dv === 10) {
                $sum += 2;
                $dv = 11 - ($sum % 11);

                return $d === $pis . '001' . $dv;
            }

            return $d === $pis . '000' . $dv;
        }
        if ($first === '7' || $first === '8' || $first === '9') {
            $sum = 0;
            for ($i = 0; $i < 15; $i++) {
                $sum += (int) $d[$i] * (15 - $i);
            }

            return $sum % 11 === 0;
        }

        return false;
    }

    /**
     * RENAVAM: 11 dígitos (o antigo, de 9, vale com dois zeros à esquerda).
     * Dígito = (Σ dígito × peso 3,2,9,8,7,6,5,4,3,2 × 10) mod 11; 10 vira 0.
     */
    public static function renavamOk(string $value): bool
    {
        $d = preg_replace('/[\s.\-]/', '', $value);
        if (!is_string($d) || !preg_match('/^(\d{9}|\d{11})$/', $d)) {
            return false;
        }
        $d = str_pad($d, 11, '0', STR_PAD_LEFT);
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        $weights = [3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $d[$i] * $weights[$i];
        }
        $dv = ($sum * 10) % 11;

        return ($dv === 10 ? 0 : $dv) === (int) $d[10];
    }

    /**
     * Chassi (VIN, ISO 3779): 17 caracteres, letras e números, sem I, O e Q.
     * O dígito da posição 9 só é obrigatório nos chassis de onde a norma o
     * exige — América do Norte (começa com 1 a 5) e China (L); o chassi
     * brasileiro (9…) e o de outras origens muitas vezes não o seguem, e
     * exigi-lo recusaria chassi real.
     */
    public static function vinOk(string $value): bool
    {
        $v = strtoupper((string) preg_replace('/[\s\-]/', '', $value));
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $v)) {
            return false;
        }
        if (!preg_match('/^[1-5L]/', $v)) {
            return true;
        }

        return self::vinCheckDigit($v) === $v[8];
    }

    /** Dígito da posição 9 do chassi ('0'–'9' ou 'X'). */
    public static function vinCheckDigit(string $vin): string
    {
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $c = $vin[$i];
            $n = ctype_digit($c) ? (int) $c : (self::VIN_LETTERS[$c] ?? 0);
            $sum += $n * self::VIN_WEIGHTS[$i];
        }
        $r = $sum % 11;

        return $r === 10 ? 'X' : (string) $r;
    }

    /**
     * Chave Pix pelo formato (nada é consultado no DICT): e-mail (até 77
     * caracteres), celular com +55 e DDD, chave aleatória (UUID), CPF ou CNPJ
     * com dígito válido.
     */
    public static function pixOk(string $value): bool
    {
        $v = trim($value);
        if (str_contains($v, '@')) {
            return strlen($v) <= 77 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
        }
        if (str_starts_with($v, '+')) {
            // Celular: +55, DDD e número; espaço, hífen e parênteses são só digitação.
            return preg_match('/^\+55[1-9]{2}\d{8,9}$/', (string) preg_replace('/[\s\-()]/', '', $v)) === 1;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            return true;
        }
        $alnum = MadCnpj::sanitize($v);
        if (preg_match('/[^0-9A-Za-z.\-\/\s]/', $v)) {
            return false;
        }
        if (ctype_digit($alnum) && strlen($alnum) === 11) {
            return SheetColumn::cpfCheckDigitsOk($alnum);
        }

        return strlen($alnum) === 14 && MadCnpj::isValid($alnum);
    }
}
