<?php
namespace Mad\Grid;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * O total do rodapé da listagem sobre o RESULTADO INTEIRO, calculado no banco.
 *
 * A tela só tem as linhas da página; somar essas linhas dava o total da
 * página, enquanto a exportação (que carrega tudo) trazia o total geral — os
 * dois números divergiam sem aviso. Aqui o total sai de UMA consulta agregada
 * sobre a mesma consulta da listagem (mesmos filtros, busca, filtro fixo,
 * unidade e escopos globais do Model), sem ORDER BY nem paginação.
 *
 * A conta é a mesma do GridRenderHelpers::computeRawTotals(), que continua
 * sendo a da exportação: valor nulo conta como 0, a média divide por TODAS as
 * linhas e a contagem conta linhas (não valores preenchidos).
 *
 * Só entra coluna que o banco calcula igual ao PHP: coluna numérica da tabela
 * do Model, ou coluna calculada cuja fórmula tem tradução (GridEvaluateSql).
 * Ficam de fora — e o chamador soma a página, dizendo isso na tela — caminho
 * de relação, texto, coluna com accessor/cast não numérico no Model, saldo
 * acumulado, `total="last"` e consulta com join, agrupamento ou SELECT próprio.
 */
final class GridTotalsQuery
{
    private const FUNCS = ['sum', 'avg', 'count', 'min', 'max'];

    private const IDENT_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param GridColumn[] $columns
     * @return array<int, int|float> índice da coluna em `$columns` => número
     *         (shape do computeRawTotals), só das colunas que o banco calcula
     */
    public static function totals(EloquentBuilder $q, array $columns): array
    {
        $model = $q->getModel();
        // toBase(): aplica os escopos globais do Model (lixeira, unidade,
        // empresa) e devolve uma cópia — a consulta da tela não é tocada.
        $base  = $q->toBase();
        if (!self::plainShape($base, $model)) {
            return [];
        }

        $plan    = [];
        $selects = ['COUNT(*) AS mad_n'];
        foreach ($columns as $i => $col) {
            if (!($col instanceof GridColumn) || empty($col->totalFunc) || $col->isRunning()) {
                continue;
            }
            $func = strtolower((string) $col->totalFunc);
            if (!in_array($func, self::FUNCS, true)) {
                continue;
            }

            $value = $col->evaluate !== ''
                ? self::evaluateSql($model, $col->evaluate)
                : self::columnSql($model, GridRenderHelpers::stripBraces($col->field), false, $func !== 'count');
            if ($value === null) {
                // Contar linhas não depende do valor da coluna calculada.
                if ($func !== 'count' || $col->evaluate === '') {
                    continue;
                }
            }

            $alias = 'mad_t' . count($plan);
            if ($func !== 'count') {
                $agg       = $func === 'min' ? 'MIN' : ($func === 'max' ? 'MAX' : 'SUM');
                $selects[] = $agg . '(' . $value . ') AS ' . $alias;
            }
            $plan[$i] = [$func, $alias];
        }
        if ($plan === []) {
            return [];
        }

        $base->reorder();
        $base->limit  = null;
        $base->offset = null;
        $row = $base->selectRaw(implode(', ', $selects))->get()->first();
        if ($row === null) {
            return [];
        }
        $row = (array) $row;
        $n   = (int) ($row['mad_n'] ?? 0);

        $out = [];
        foreach ($plan as $i => [$func, $alias]) {
            $v = isset($row[$alias]) && is_numeric($row[$alias]) ? (float) $row[$alias] : 0.0;
            $out[$i] = match ($func) {
                'sum'   => $v,
                'avg'   => $n > 0 ? $v / $n : 0,
                'count' => $n,
                default => $n > 0 ? $v : 0,   // min | max
            };
        }

        return $out;
    }

    /**
     * A fórmula de uma coluna calculada como SQL sobre a tabela do Model —
     * serve ao total e ao ORDER BY. null = não há tradução fiel.
     */
    public static function evaluateSql(Model $model, string $expr): ?string
    {
        try {
            return GridEvaluateSql::compile($expr, fn (string $name) => self::columnSql($model, $name, true, true));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * O valor de UMA coluna da tabela do Model como SQL, com nulo = 0.
     *
     * @param bool $float   converte para ponto flutuante (conta de fórmula)
     * @param bool $numeric exige coluna numérica (soma, média, mínimo, máximo)
     */
    public static function columnSql(Model $model, string $name, bool $float, bool $numeric): ?string
    {
        $name = trim($name);
        if (!preg_match(self::IDENT_RE, $name)) {
            return null;   // caminho de relação, máscara, notação de ponto
        }
        // O que a linha mostra é o que o Model devolve: com accessor ou cast
        // não numérico, o valor da tela não é o do banco.
        if ($model->hasGetMutator($name)
            || (method_exists($model, 'hasAttributeGetMutator') && $model->hasAttributeGetMutator($name))) {
            return null;
        }
        if ($model->hasCast($name) && !GridExportSourceTypes::hasNumericCast($model, $name)) {
            return null;
        }
        // Coluna que o Model esconde do toArray() não aparece na linha: a
        // fórmula ainda a lê pelo objeto, mas o total direto dela seria 0.
        if (!$float) {
            $visible = $model->getVisible();
            if (in_array($name, $model->getHidden(), true) || ($visible !== [] && !in_array($name, $visible, true))) {
                return null;
            }
        }

        $columns = GridExportSourceTypes::tableColumns($model);
        if ($columns === null || !array_key_exists($name, $columns) || ($numeric && !$columns[$name])) {
            return null;
        }

        $conn    = $model->getConnection();
        $wrapped = $conn->getQueryGrammar()->wrap($model->getTable() . '.' . $name);
        if (!$float) {
            return 'COALESCE(' . $wrapped . ', 0)';
        }

        $cast = match ($conn->getDriverName()) {
            'pgsql'            => 'CAST(' . $wrapped . ' AS double precision)',
            'mysql', 'mariadb' => '(' . $wrapped . ' + 0E0)',
            'sqlite'           => 'CAST(' . $wrapped . ' AS REAL)',
            'sqlsrv'           => 'CAST(' . $wrapped . ' AS float)',
            default            => null,
        };

        return $cast === null ? null : 'COALESCE(' . $cast . ', 0)';
    }

    /**
     * A consulta é um SELECT simples na tabela do Model? Com join, agrupamento,
     * DISTINCT, UNION ou colunas próprias, a linha que a tela mostra já não é
     * "uma linha da tabela" e o agregado direto contaria outra coisa.
     */
    private static function plainShape(QueryBuilder $base, Model $model): bool
    {
        if (!empty($base->joins) || !empty($base->groups) || !empty($base->havings)
            || !empty($base->unions) || $base->distinct !== false || $base->aggregate !== null) {
            return false;
        }
        if ($base->columns !== null && $base->columns !== ['*'] && $base->columns !== [$model->getTable() . '.*']) {
            return false;
        }

        return is_string($base->from) && $base->from === $model->getTable();
    }
}
