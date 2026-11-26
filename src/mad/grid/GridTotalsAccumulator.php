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
            // Transformador do app (fórum #426): acumula também o número que a
            // célula mostra — o rendered() decide qual dos dois totais vale.
            if (GridCellTotals::candidate($col)) {
                $this->acc[$i]['cell'] = ['ok' => true, 'sum' => 0.0, 'n' => 0, 'min' => null, 'max' => null, 'last' => 0.0, 'dec' => 0, 'tho' => false];
            }
        }
    }

    public function add(array $row): void
    {
        foreach ($this->acc as $i => &$a) {
            if (!array_key_exists($a['field'], $row)) continue;
            $v = is_numeric($row[$a['field']]) ? (float) $row[$a['field']] : 0.0;
            $a['sum'] += $v;
            $a['n']++;
            $a['min']  = $a['min'] === null ? $v : min($a['min'], $v);
            $a['max']  = $a['max'] === null ? $v : max($a['max'], $v);
            $a['last'] = $v;
            if (isset($a['cell']) && $a['cell']['ok']) {
                $num = GridCellTotals::number($this->columns[$i]->renderValue($row[$a['field']], $row));
                if ($num === null) {
                    $a['cell']['ok'] = false;
                    continue;
                }
                $c = &$a['cell'];
                $c['sum'] += $num[0];
                $c['n']++;
                $c['min']  = $c['min'] === null ? $num[0] : min($c['min'], $num[0]);
                $c['max']  = $c['max'] === null ? $num[0] : max($c['max'], $num[0]);
                $c['last'] = $num[0];
                $c['dec']  = max($c['dec'], $num[1]);
                $c['tho']  = $c['tho'] || $num[2];
                unset($c);
            }
        }
        unset($a);
    }

    /**
     * Os acumuladores, só números — para guardar entre requisições (o relatório
     * por janelas guarda os totais de cada grupo no cache do app).
     *
     * @return array<int, array>
     */
    public function state(): array
    {
        return $this->acc;
    }

    /** Retoma um acumulador do `state()`, sobre as MESMAS colunas. */
    public static function fromState(array $columns, array $state): self
    {
        $acc = new self($columns);
        foreach ($state as $i => $a) {
            if (isset($acc->acc[$i]) && is_array($a)) {
                $acc->acc[$i] = $a + $acc->acc[$i];
            }
        }

        return $acc;
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
            $col  = $this->columns[$i];
            $cell = $this->acc[$i]['cell'] ?? null;
            if (is_array($cell) && !empty($cell['ok']) && $cell['n'] > 0) {
                $total = match ($this->acc[$i]['func']) {
                    'sum'   => $cell['sum'],
                    'avg'   => $cell['sum'] / $cell['n'],
                    'min'   => (float) $cell['min'],
                    'max'   => (float) $cell['max'],
                    'last'  => $cell['last'],
                    default => 0.0,
                };
                $decided = GridCellTotals::decide($col, (float) $total, (int) $cell['n'], (int) $cell['dec'], (bool) $cell['tho'], $v);
                if ($decided !== null) {
                    $out[$col->field] = empty($col->totalMask) ? $decided['text'] : str_replace('{value}', $decided['text'], $col->totalMask);
                    continue;
                }
            }
            $out[$col->field] = GridRenderHelpers::renderTotal($col, $v);
        }

        return $out;
    }
}
