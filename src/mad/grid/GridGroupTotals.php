<?php
namespace Mad\Grid;

/**
 * Os números da quebra (`group-by`) de um relatório inteiro, sem as linhas.
 *
 * Para cada grupo — identificado pelo caminho de chaves até ele (`[conta]`,
 * `[conta, mês]`, …) — guarda a contagem de linhas e um GridTotalsAccumulator;
 * mais o total geral. A conta é a mesma do `_flattenGroups()` + rodapé de hoje
 * (GridRenderHelpers::computeTotals sobre as linhas do grupo): o acumulador
 * reproduz linha a linha o computeRawTotals, então subtotal, média, mínimo,
 * máximo, último e o saldo do fim do grupo saem idênticos.
 *
 * Quem preenche é uma passada só sobre o resultado (GridGroupStream em modo de
 * coleta), lendo em lotes: a memória cresce com o número de GRUPOS, nunca com o
 * de linhas. `export()` o leva ao cache do app entre as janelas.
 */
final class GridGroupTotals
{
    /** @var GridColumn[] */
    private array $columns;
    /** @var array<string, int> caminho => linhas */
    private array $counts = [];
    /** @var array<string, GridTotalsAccumulator> caminho => totais */
    private array $accs = [];
    private GridTotalsAccumulator $grand;
    private int $rows = 0;
    private bool $hasTotals;

    /** @param GridColumn[] $columns as colunas da grade (as mesmas do render) */
    public function __construct(array $columns)
    {
        $this->columns   = array_values(array_filter($columns, fn ($c) => $c instanceof GridColumn));
        $this->grand     = new GridTotalsAccumulator($this->columns);
        $this->hasTotals = false;
        foreach ($this->columns as $col) {
            if (!empty($col->totalFunc)) {
                $this->hasTotals = true;
                break;
            }
        }
    }

    /** Chave estável de um caminho de grupo (o nível faz parte da chave). */
    public static function pathKey(array $path): string
    {
        return count($path) . "\x1E" . implode("\x1F", array_map('strval', $path));
    }

    /**
     * Uma linha, já com o saldo acumulado materializado, na ORDEM DE EXIBIÇÃO.
     *
     * @param string[] $path chave de cada nível de quebra desta linha
     */
    public function add(array $path, array $row): void
    {
        $this->rows++;
        $this->grand->add($row);

        $prefix = [];
        foreach (array_values($path) as $key) {
            $prefix[] = (string) $key;
            $k = self::pathKey($prefix);
            $this->counts[$k] = ($this->counts[$k] ?? 0) + 1;
            if ($this->hasTotals) {
                ($this->accs[$k] ??= new GridTotalsAccumulator($this->columns))->add($row);
            }
        }
    }

    /** Linhas do resultado inteiro. */
    public function rows(): int
    {
        return $this->rows;
    }

    /** Linhas de um grupo. */
    public function count(array $path): int
    {
        return $this->counts[self::pathKey($path)] ?? 0;
    }

    /**
     * Totais de um grupo, já no texto da tela (shape do computeTotals:
     * campo => texto). [] quando nenhuma coluna tem `total`.
     *
     * @return array<string, string>
     */
    public function totals(array $path): array
    {
        $acc = $this->accs[self::pathKey($path)] ?? null;

        return $acc === null ? $this->emptyTotals() : $acc->rendered();
    }

    /** @return array<int, int|float> índice da coluna => número (shape do computeRawTotals) */
    public function rawTotals(array $path): array
    {
        $acc = $this->accs[self::pathKey($path)] ?? null;

        return $acc === null ? (new GridTotalsAccumulator($this->columns))->raw() : $acc->raw();
    }

    /** @return array<string, string> o rodapé (TOTAL GERAL) no texto da tela */
    public function grandTotals(): array
    {
        return $this->grand->rendered();
    }

    /** @return array<int, int|float> */
    public function grandRawTotals(): array
    {
        return $this->grand->raw();
    }

    /**
     * O mapa só com números (sem os objetos de coluna), para o cache do app.
     * Volta com `fromExport()` sobre as mesmas colunas.
     */
    public function export(): array
    {
        return [
            'rows'   => $this->rows,
            'counts' => $this->counts,
            'accs'   => array_map(fn (GridTotalsAccumulator $a) => $a->state(), $this->accs),
            'grand'  => $this->grand->state(),
        ];
    }

    /** @param GridColumn[] $columns as mesmas colunas de quando o mapa foi feito */
    public static function fromExport(array $columns, array $data): self
    {
        $map         = new self($columns);
        $map->rows   = (int) ($data['rows'] ?? 0);
        $map->counts = (array) ($data['counts'] ?? []);
        $map->grand  = GridTotalsAccumulator::fromState($map->columns, (array) ($data['grand'] ?? []));
        foreach ((array) ($data['accs'] ?? []) as $k => $state) {
            $map->accs[$k] = GridTotalsAccumulator::fromState($map->columns, (array) $state);
        }

        return $map;
    }

    /** Quantos grupos (todos os níveis) o mapa guarda. */
    public function groups(): int
    {
        return count($this->counts);
    }

    /** @return array<string, string> */
    private function emptyTotals(): array
    {
        return (new GridTotalsAccumulator($this->columns))->rendered();
    }
}
