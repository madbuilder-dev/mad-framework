<?php
namespace Mad\Pdv;

/**
 * PdvTax — imposto por linha do `<mad-pdv>`, em CENTAVOS INTEIROS.
 *
 * Regras (as mesmas do gêmeo JS `MadPdvTax` em assets/mad-pdv.js — os dois
 * precisam bater centavo a centavo; o teste tests/js/pdv-tax.test.mjs e o
 * PHPUnit MadPdvTaxTest rodam os MESMOS vetores):
 *
 *   - todo valor é inteiro em centavos; float só na borda (fromDecimal/toDecimal);
 *   - alíquota em % com até 4 casas, guardada como inteiro × 10000 (8.25 → 82500);
 *   - arredondamento half-up (simétrico nos negativos);
 *   - desconto da VENDA é rateado nas linhas ANTES do imposto, proporcional ao
 *     valor de cada linha; o resíduo do rateio vai para a ÚLTIMA linha de peso > 0;
 *   - modo "por fora" (EUA/Canadá): imposto = base × alíquota, total = base + imposto;
 *   - modo "incluso" (UK/UE/AU): o preço já tem o imposto; líquido = base / (1 + alíquota)
 *     e imposto = base − líquido;
 *   - gorjeta nunca passa por aqui (não paga imposto);
 *   - linha com PARTES (`components`: combo/opção com alíquota própria — UK:
 *     bebida fria 0% + comida quente 20% no mesmo item): o desconto que coube à
 *     linha é rateado nas partes pelo valor de cada uma; o imposto é calculado
 *     por ALÍQUOTA dentro da linha (partes de mesma alíquota somam antes) e o
 *     imposto de cada alíquota é rateado nas partes dela. Linha sem partes, ou
 *     com todas as partes na mesma alíquota, dá exatamente o mesmo resultado.
 */
final class PdvTax
{
    /** Alíquota em % com até 4 casas → inteiro × 10000. */
    public const RATE_SCALE = 10000;

    /** "12.345" | 12.345 | null → 1235 (half-up; negativo arredonda para longe do zero). */
    public static function fromDecimal(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $s = is_float($value) ? sprintf('%.6F', $value) : trim((string) $value);
        $s = str_replace([',', ' '], '', $s);
        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $s, $m)) {
            throw new \InvalidArgumentException('Invalid amount: ' . $value);
        }
        $neg  = $m[1] === '-';
        $int  = $m[2] === '' ? '0' : $m[2];
        $dec  = str_pad($m[3] ?? '', 3, '0');
        $cents = (int) $int * 100 + (int) substr($dec, 0, 2);
        if ((int) $dec[2] >= 5) {
            $cents++;
        }
        return $neg ? -$cents : $cents;
    }

    /** 1235 → "12.35" (string, segura para coluna decimal). */
    public static function toDecimal(int $cents): string
    {
        $abs = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Centavos → float de 2 casas (formato dos totais do PDV). */
    public static function toFloat(int $cents): float
    {
        return (float) self::toDecimal($cents);
    }

    /** "8.25" | 8.25 | "20" → 82500. Negativo ou texto inválido lança. */
    public static function rateUnits(string|int|float|null $ratePct): int
    {
        if ($ratePct === null || $ratePct === '') {
            return 0;
        }
        $s = is_float($ratePct) ? sprintf('%.6F', $ratePct) : trim((string) $ratePct);
        if (!preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m)) {
            throw new \InvalidArgumentException('Invalid rate: ' . $ratePct);
        }
        $dec   = str_pad($m[2] ?? '', 5, '0');
        $units = (int) ($m[1] === '' ? '0' : $m[1]) * self::RATE_SCALE + (int) substr($dec, 0, 4);
        if ((int) $dec[4] >= 5) {
            $units++;
        }
        return $units;
    }

    /**
     * Alíquota canônica (string curta, sem zero à toa): 82500 → "8.25", 200000 → "20".
     * É a chave do agrupamento por alíquota no cupom e o `tax_rate` do payload.
     */
    public static function rateString(int $units): string
    {
        $int = intdiv($units, self::RATE_SCALE);
        $dec = rtrim(str_pad((string) ($units % self::RATE_SCALE), 4, '0', STR_PAD_LEFT), '0');
        return $dec === '' ? (string) $int : $int . '.' . $dec;
    }

    /**
     * Normaliza o que veio da coluna do produto (null, "", "8.250", 8.25, "8,25").
     * Inválido ou negativo vira "0" (isento) — nunca derruba a venda.
     */
    public static function normalizeRate(mixed $raw): string
    {
        if ($raw === null || $raw === '' || is_bool($raw) || is_array($raw) || is_object($raw)) {
            return '0';
        }
        $s = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
        try {
            return self::rateString(self::rateUnits($s));
        } catch (\Throwable $e) {
            return '0';
        }
    }

    /** centavos × alíquota% (half-up). Imposto sobre um valor líquido. */
    public static function mulRate(int $cents, string|int|float|null $ratePct): int
    {
        return self::divRound($cents * self::rateUnits($ratePct), 100 * self::RATE_SCALE);
    }

    /** Parte líquida de um valor com imposto incluso: bruto / (1 + alíquota%) (half-up). */
    public static function netFromGross(int $grossCents, string|int|float|null $ratePct): int
    {
        $den = 100 * self::RATE_SCALE;
        return self::divRound($grossCents * $den, $den + self::rateUnits($ratePct));
    }

    /**
     * Divide $total proporcionalmente aos pesos; o resíduo do arredondamento vai
     * para a ÚLTIMA parte com peso > 0. Chaves preservadas.
     *
     * @param array<int|string, int> $weights
     * @return array<int|string, int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $out    = array_fill_keys(array_keys($weights), 0);
        $sum    = 0;
        $lastKey = null;
        foreach ($weights as $k => $w) {
            if ($w > 0) {
                $sum += $w;
                $lastKey = $k;
            }
        }
        if ($sum === 0 || $lastKey === null) {
            return $out;
        }
        $given = 0;
        foreach ($weights as $k => $w) {
            if ($w <= 0) {
                continue;
            }
            if ($k === $lastKey) {
                $out[$k] = $total - $given;
                break;
            }
            $part    = intdiv($total * $w, $sum);
            $out[$k] = $part;
            $given  += $part;
        }
        return $out;
    }

    /** Divisão inteira com arredondamento half-up (simétrico nos negativos). */
    public static function divRound(int $num, int $den): int
    {
        if ($den === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $neg = ($num < 0) !== ($den < 0);
        $num = abs($num);
        $den = abs($den);
        $q   = intdiv($num, $den);
        if (($num % $den) * 2 >= $den) {
            $q++;
        }
        return $neg ? -$q : $q;
    }

    /**
     * Imposto por linha.
     *
     * @param array<int|string, array{amount:int, rate?:string|int|float|null, taxable?:bool}> $lines
     *        amount = valor da linha em centavos ANTES do desconto da venda (preço com
     *        opções × qtd, já sem o desconto do item). No modo incluso já contém o imposto.
     *        `components` (opcional) = lista de partes `{amount:int, rate?:…}` cuja soma é
     *        o amount da linha; parte sem rate usa a da linha. A saída da linha ganha
     *        `components` (o mesmo cálculo por parte) e `parts` (por alíquota, ordem de
     *        1ª aparição, com as isentas).
     * @param bool $inclusive     preços com imposto incluso (UK/UE/AU) ou imposto por fora (EUA/CA)
     * @param int  $discountCents desconto da venda a ratear nas linhas (limitado ao subtotal)
     * @return array{lines: array<int|string, array{amount:int, discount:int, net:int, tax:int, gross:int, rate:string}>,
     *               subtotal:int, discount:int, net:int, tax:int, total:int}
     */
    public static function lines(array $lines, bool $inclusive, int $discountCents = 0): array
    {
        $weights  = [];
        $subtotal = 0;
        foreach ($lines as $k => $l) {
            $amount = (int) $l['amount'];
            if ($amount < 0) {
                throw new \InvalidArgumentException('Line amount cannot be negative');
            }
            $weights[$k] = $amount;
            $subtotal   += $amount;
        }
        $discount = max(0, min($discountCents, $subtotal));
        $alloc    = self::allocate($discount, $weights);

        $out  = [];
        $tNet = $tTax = $tGross = 0;
        foreach ($lines as $k => $l) {
            $amount  = (int) $l['amount'];
            $rate    = (string) ($l['rate'] ?? '0');
            $taxable = ($l['taxable'] ?? true) && self::rateUnits($rate) > 0;
            $base    = $amount - ($alloc[$k] ?? 0);
            if (isset($l['components']) && is_array($l['components']) && $l['components'] !== []) {
                $out[$k] = self::componentLine($l, $amount, $alloc[$k] ?? 0, $rate, $inclusive);
                $tNet   += $out[$k]['net'];
                $tTax   += $out[$k]['tax'];
                $tGross += $out[$k]['gross'];
                continue;
            }
            if (!$taxable) {
                $net   = $base;
                $tax   = 0;
                $gross = $base;
                $rate  = '0';
            } elseif ($inclusive) {
                $gross = $base;
                $net   = self::netFromGross($base, $rate);
                $tax   = $gross - $net;
            } else {
                $net   = $base;
                $tax   = self::mulRate($base, $rate);
                $gross = $net + $tax;
            }
            $out[$k] = [
                'amount'   => $amount,
                'discount' => $alloc[$k] ?? 0,
                'net'      => $net,
                'tax'      => $tax,
                'gross'    => $gross,
                'rate'     => $rate,
            ];
            $tNet   += $net;
            $tTax   += $tax;
            $tGross += $gross;
        }

        return [
            'lines'    => $out,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'net'      => $tNet,
            'tax'      => $tTax,
            'total'    => $tGross,
        ];
    }

    /**
     * Resumo agrupado por alíquota (cupom e relatório), na ordem em que cada
     * alíquota aparece pela 1ª vez. Linha isenta fica de fora.
     *
     * @param array $result saída de lines()
     * @return array<string, array{rate:string, net:int, tax:int}>
     */
    public static function byRate(array $result): array
    {
        $g = [];
        foreach ($result['lines'] as $line) {
            foreach ($line['parts'] ?? [$line] as $l) {
                if ($l['tax'] === 0 && self::rateUnits($l['rate']) === 0) {
                    continue;
                }
                $key = (string) $l['rate'];
                $g[$key] ??= ['rate' => $key, 'net' => 0, 'tax' => 0];
                $g[$key]['net'] += $l['net'];
                $g[$key]['tax'] += $l['tax'];
            }
        }
        return $g;
    }

    /**
     * Linha com partes de alíquotas diferentes (ver o docblock da classe).
     *
     * @param array  $l        linha de entrada (com `components`)
     * @param int    $amount   valor da linha (= soma das partes)
     * @param int    $lineDisc desconto da venda que coube à linha
     * @param string $lineRate alíquota da linha (parte sem rate herda)
     */
    private static function componentLine(array $l, int $amount, int $lineDisc, string $lineRate, bool $inclusive): array
    {
        $taxableLine = (bool) ($l['taxable'] ?? true);
        $amounts = [];
        $rates   = [];
        $sum     = 0;
        foreach (array_values($l['components']) as $i => $c) {
            $a = (int) ($c['amount'] ?? 0);
            if ($a < 0) {
                throw new \InvalidArgumentException('Component amount cannot be negative');
            }
            $r = array_key_exists('rate', $c) && $c['rate'] !== null ? self::normalizeRate($c['rate']) : self::normalizeRate($lineRate);
            $amounts[$i] = $a;
            $rates[$i]   = ($taxableLine && self::rateUnits($r) > 0) ? $r : '0';
            $sum        += $a;
        }
        if ($sum !== $amount) {
            throw new \InvalidArgumentException('Components must add up to the line amount');
        }
        $cDisc = self::allocate($lineDisc, $amounts);

        $groups = [];   // rate => [índices], ordem de 1ª aparição
        foreach ($rates as $i => $r) {
            $groups[$r][] = $i;
        }
        $comps = [];
        $parts = [];
        foreach ($groups as $r => $idx) {
            $r      = (string) $r;
            $bases  = [];
            $baseG  = 0;
            foreach ($idx as $i) {
                $bases[$i] = $amounts[$i] - $cDisc[$i];
                $baseG    += $bases[$i];
            }
            if ($r === '0') {
                $taxG = 0;
            } elseif ($inclusive) {
                $taxG = $baseG - self::netFromGross($baseG, $r);
            } else {
                $taxG = self::mulRate($baseG, $r);
            }
            $taxes = self::allocate($taxG, $bases);
            foreach ($idx as $i) {
                $t   = $taxes[$i] ?? 0;
                $net = $inclusive ? $bases[$i] - $t : $bases[$i];
                $comps[$i] = [
                    'amount'   => $amounts[$i],
                    'discount' => $cDisc[$i],
                    'net'      => $net,
                    'tax'      => $t,
                    'gross'    => $net + $t,
                    'rate'     => $r,
                ];
            }
            $netG    = $inclusive ? $baseG - $taxG : $baseG;
            $parts[] = ['rate' => $r, 'net' => $netG, 'tax' => $taxG, 'gross' => $netG + $taxG];
        }
        ksort($comps);
        $net = $tax = 0;
        foreach ($parts as $p) {
            $net += $p['net'];
            $tax += $p['tax'];
        }
        return [
            'amount'     => $amount,
            'discount'   => $lineDisc,
            'net'        => $net,
            'tax'        => $tax,
            'gross'      => $net + $tax,
            'rate'       => ($taxableLine && self::rateUnits($lineRate) > 0) ? $lineRate : '0',
            'components' => array_values($comps),
            'parts'      => $parts,
        ];
    }

    /**
     * Gorjeta em % sobre a base (líquido SEM imposto, depois do desconto) — half-up.
     * Percentual inválido ou acima de 100 lança.
     */
    public static function tip(int $baseCents, string|int|float|null $pct): int
    {
        $units = self::rateUnits(is_string($pct) ? str_replace(',', '.', trim($pct)) : $pct);
        if ($units > 100 * self::RATE_SCALE) {
            throw new \InvalidArgumentException('Tip percent above 100');
        }
        return self::divRound(max(0, $baseCents) * $units, 100 * self::RATE_SCALE);
    }
}
