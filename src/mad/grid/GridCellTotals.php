<?php
namespace Mad\Grid;

use Mad\Support\ValueFormatter;

/**
 * Total do rodapé de coluna com TRANSFORMADOR DO APP que calcula o valor a
 * partir da linha (fórum #426).
 *
 * O total sempre foi a conta do valor CRU do campo, passada depois pelo
 * render da coluna (o transformador). Para transformador de FORMATO (Real,
 * moeda, número, um formatador próprio do app) é o certo: formatar a soma é
 * igual a somar o formatado. Para transformador que CALCULA — a coluna é o
 * `id` e o transformador conta os produtos devolvidos daquele registro — a
 * conta somava os ids e o transformador rodava sobre um "registro" com id =
 * soma dos ids: o rodapé mostrava 0 com as linhas mostrando 3 e 0.
 *
 * Regra: numa coluna com transformador do app e total (sum/avg/min/max/last),
 * cada célula mostra um número; se o transformador aplicado ao total cru NÃO
 * dá a mesma conta dos números das células, o transformador calcula — e o
 * total passa a ser a conta do que as células mostram. Formatador (mesma
 * conta, a menos do arredondamento de cada célula) segue exatamente como
 * antes. Célula que não mostra um número (texto, selo, HTML com mais de um
 * número) também segue como antes.
 *
 * O banco não sabe calcular o transformador: a tela soma as linhas
 * carregadas (a página, com o aviso "nesta página" quando há mais linhas) e a
 * exportação, que carrega tudo, soma tudo.
 */
final class GridCellTotals
{
    private const FUNCS = ['sum', 'avg', 'min', 'max', 'last'];

    /** Coluna em que o total pode ter de vir das células. */
    public static function candidate(GridColumn $col): bool
    {
        if (!$col->hasTransform || !is_callable($col->transform) || empty($col->totalFunc)) {
            return false;
        }

        return in_array(self::func($col), self::FUNCS, true);
    }

    /** A função do total, com `sum` de saldo acumulado = último saldo (como no computeRawTotals). */
    public static function func(GridColumn $col): string
    {
        $func = strtolower((string) $col->totalFunc);

        return $func === 'sum' && $col->isRunning() ? 'last' : $func;
    }

    /**
     * O número que a célula mostra, ou null quando ela não mostra UM número
     * (texto, selo, dois números). Vazio conta como 0, como o valor nulo.
     *
     * @return array{0: float, 1: int, 2: bool}|null [número, casas decimais, usou separador de milhar]
     */
    public static function number(string $cell): ?array
    {
        $text = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}");
        if ($text === '') {
            return [0.0, 0, false];
        }
        if (preg_match_all('/-?\d[\d.,]*/', $text, $m) !== 1) {
            return null;
        }
        $token = rtrim($m[0][0], '.,');
        $rest  = trim(str_replace($m[0][0], '', $text));
        // Prefixo/sufixo curto (R$, %, "un") ainda é um número na célula;
        // frase inteira não.
        if (mb_strlen($rest) > 6) {
            return null;
        }

        [$dec, $tho] = ValueFormatter::separators();
        $neg = str_starts_with($token, '-');
        $digits = ltrim($token, '-');

        $usedThousands = false;
        $decimals = 0;
        if ($tho !== '' && preg_match('/^\d{1,3}(' . preg_quote($tho, '/') . '\d{3})+(' . preg_quote($dec, '/') . '\d+)?$/', $digits)) {
            $usedThousands = true;
            $digits = str_replace($tho, '', $digits);
        }
        if (substr_count($digits, $dec) === 1) {
            $decimals = strlen(substr($digits, strpos($digits, $dec) + 1));
            $digits = str_replace($dec, '.', $digits);
        } elseif ($dec !== '.' && substr_count($digits, '.') === 1 && !$usedThousands) {
            // Número cru do PHP ("12.5") numa tela em pt-BR.
            $decimals = strlen(substr($digits, strpos($digits, '.') + 1));
        }
        if (!is_numeric($digits)) {
            return null;
        }

        return [($neg ? -1 : 1) * (float) $digits, $decimals, $usedThousands];
    }

    /** A conta da função sobre os números (mesma do computeRawTotals). */
    public static function aggregate(string $func, array $vals): float
    {
        $n = count($vals);

        return (float) match ($func) {
            'sum'   => array_sum($vals),
            'avg'   => $n > 0 ? array_sum($vals) / $n : 0,
            'min'   => $n > 0 ? min($vals) : 0,
            'max'   => $n > 0 ? max($vals) : 0,
            'last'  => $n > 0 ? end($vals) : 0,
            default => 0,
        };
    }

    /**
     * Total da coluna a partir das células das linhas, ou null quando o total
     * de sempre (transformador sobre a conta crua) é o certo.
     *
     * @param array<int, array> $rows linhas normalizadas (as do grid)
     * @return array{value: float, text: string}|null
     */
    public static function fromRows(GridColumn $col, array $rows, int|float $rawTotal): ?array
    {
        if (!self::candidate($col)) {
            return null;
        }
        $vals = [];
        $decimals = 0;
        $thousands = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($col->field, $row)) {
                continue;
            }
            $num = self::number($col->renderValue($row[$col->field], $row));
            if ($num === null) {
                return null;
            }
            $vals[] = $num[0];
            $decimals = max($decimals, $num[1]);
            $thousands = $thousands || $num[2];
        }

        return self::decide($col, self::aggregate(self::func($col), $vals), count($vals), $decimals, $thousands, $rawTotal);
    }

    /**
     * Compara o transformador aplicado ao total cru com a conta das células.
     * Mesma conta (a menos do arredondamento de cada célula) = formatador:
     * null, o total segue como sempre.
     *
     * @return array{value: float, text: string}|null
     */
    public static function decide(GridColumn $col, float $cellTotal, int $n, int $decimals, bool $thousands, int|float $rawTotal): ?array
    {
        if ($n === 0) {
            return null;
        }
        try {
            $viaRaw = self::number($col->renderValue(is_float($rawTotal) ? round($rawTotal, 10) : $rawTotal, []));
        } catch (\Throwable) {
            // Transformador que precisa da linha (lê `$object->id`) e quebra
            // sem ela: com certeza não é um formatador.
            $viaRaw = null;
        }
        if ($viaRaw !== null) {
            $tol = 0.005 * max(1, $n) + 1e-9 * max(1.0, abs($cellTotal));
            if (abs($viaRaw[0] - $cellTotal) <= $tol) {
                return null;
            }
        }

        return ['value' => $cellTotal, 'text' => self::format($cellTotal, $decimals, $thousands)];
    }

    /** O total no jeito das células: mesmas casas decimais e separador de milhar só se elas usam. */
    public static function format(float $v, int $decimals, bool $thousands): string
    {
        [$dec, $tho] = ValueFormatter::separators();
        $decimals = min($decimals, 6);

        return number_format(round($v, $decimals), $decimals, $dec, $thousands ? $tho : '');
    }
}
