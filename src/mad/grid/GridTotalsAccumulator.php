<?php
namespace Mad\Grid;

/**
 * GridRenderHelpers::computeRawTotals() linha a linha — o total geral da
 * exportação em fluxo, sem guardar as linhas. Mesma semântica: linha SEM a
 * chave não conta, valor não numérico conta como 0, sum/avg/count/min/max/last
 * e `sum` em coluna `running` = último saldo. A soma segue a mesma ordem das
 * linhas, então o número sai idêntico ao do array_sum.
 */
final class GridTotalsAccumulator
{
    /** @var GridColumn[] */
    private array $columns;
    /** @var array<int, array{field:string, func:string, sum:float, n:int, min:?float, max:?float, last:float}> */
    private array $acc = [];

    /** @param GridColumn[] $columns */
    public function __construct(array $columns)
    {
        $this->columns = array_values($columns);
        foreach ($this->columns as $i => $col) {
            if (empty($col->totalFunc)) continue;
            $func = $col->totalFunc === 'sum' && $col->isRunning() ? 'last' : $col->totalFunc;
            $this->acc[$i] = ['field' => $col->field, 'func' => $func, 'sum' => 0.0, 'n' => 0, 'min' => null, 'max' => null, 'last' => 0.0];
        }
    }

    public function add(array $row): void
    {
        foreach ($this->acc as &$a) {
            if (!array_key_exists($a['field'], $row)) continue;
            $v = is_numeric($row[$a['field']]) ? (float) $row[$a['field']] : 0.0;
            $a['sum'] += $v;
            $a['n']++;
            $a['min']  = $a['min'] === null ? $v : min($a['min'], $v);
            $a['max']  = $a['max'] === null ? $v : max($a['max'], $v);
            $a['last'] = $v;
        }
        unset($a);
    }

    /** @return array<int, int|float> índice da coluna => número (shape do computeRawTotals) */
    public function raw(): array
    {
        $out = [];
        foreach ($this->acc as $i => $a) {
            $out[$i] = match ($a['func']) {
                'sum'   => $a['sum'],
                'avg'   => $a['n'] > 0 ? $a['sum'] / $a['n'] : 0,
                'count' => $a['n'],
                'min'   => $a['n'] > 0 ? $a['min'] : 0,
                'max'   => $a['n'] > 0 ? $a['max'] : 0,
                'last'  => $a['n'] > 0 ? $a['last'] : 0,
                default => 0,
            };
        }

        return $out;
    }

    /** @return array<string, string> field => texto (shape do computeTotals) */
    public function rendered(): array
    {
        $out = [];
        foreach ($this->raw() as $i => $v) {
            $out[$this->columns[$i]->field] = GridRenderHelpers::renderTotal($this->columns[$i], $v);
        }

        return $out;
    }
}
