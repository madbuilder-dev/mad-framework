<?php

namespace Mad\Pdv;

/**
 * Rateio de parcelas e datas de vencimento.
 *
 * Sem I/O de propósito: a fórmula é normativa (§7.6 da spec) e precisa ser
 * auditável e testável isolada. O cliente tem um espelho dela só para o
 * preview — quem calcula de verdade é o servidor, sempre (§7.5).
 *
 * ## Onde vai o resíduo
 *
 * R$ 100,00 em 3 não é 33,33 × 3: falta um centavo. A convenção adotada é a
 * da estrutura de ERP que originou esta feature — resíduo na ÚLTIMA parcela
 * por padrão (33,33 + 33,33 + 33,34), configurável para a primeira.
 *
 * O que NÃO é negociável é a invariante: `soma das parcelas === valor`. Ela
 * vale por construção, sem ajuste ad-hoc no fim — e é exatamente no "ajusta o
 * último" que erro de arredondamento se esconde.
 */
final class PdvInstallmentPlan
{
    /**
     * Divide `$amountC` centavos em `$n` parcelas.
     *
     * @param  int    $amountC valor a parcelar, em centavos
     * @param  int    $n       número de parcelas (>= 1)
     * @param  string $residuo 'ultima' (default) | 'primeira'
     * @return array<int, int> parcelas em centavos, na ordem
     */
    public static function split(int $amountC, int $n, string $residuo = 'ultima'): array
    {
        $n = max(1, $n);
        if ($amountC <= 0) {
            return array_fill(0, $n, 0);
        }

        $base   = intdiv($amountC, $n);
        $resto  = $amountC - $base * $n;
        $parts  = array_fill(0, $n, $base);
        $target = $residuo === 'primeira' ? 0 : $n - 1;

        $parts[$target] += $resto;

        return $parts;
    }

    /**
     * Vencimentos: a primeira em `$firstDays` a partir da venda, as demais
     * espaçadas de `$intervalDays`. Dias corridos — sem regra de dia útil.
     *
     * @return array<int, string> datas Y-m-d, na ordem
     */
    public static function dueDates(
        \DateTimeInterface $sale,
        int $n,
        int $firstDays = 30,
        int $intervalDays = 30,
    ): array {
        $n            = max(1, $n);
        $firstDays    = max(0, $firstDays);
        $intervalDays = max(0, $intervalDays);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = (new \DateTimeImmutable($sale->format('Y-m-d')))
                ->modify('+' . ($firstDays + $i * $intervalDays) . ' days');
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /**
     * Plano completo de uma entrada de pagamento.
     *
     * @param  int $amountC valor alocado na entrada, em centavos
     * @param  int $downC   entrada paga no ato (não vira título)
     * @return array<int, array{n: int, total: int, amountC: int, due: string}>
     */
    public static function build(
        int $amountC,
        int $downC,
        int $n,
        \DateTimeInterface $sale,
        int $firstDays = 30,
        int $intervalDays = 30,
        string $residuo = 'ultima',
    ): array {
        $financiado = max(0, $amountC - max(0, $downC));
        if ($financiado === 0) {
            return [];
        }

        $n      = max(1, $n);
        $values = self::split($financiado, $n, $residuo);
        $dues   = self::dueDates($sale, $n, $firstDays, $intervalDays);

        $out = [];
        foreach ($values as $i => $v) {
            $out[] = [
                'n'       => $i + 1,
                'total'   => $n,
                'amountC' => $v,
                'due'     => $dues[$i],
            ];
        }

        return $out;
    }
}
