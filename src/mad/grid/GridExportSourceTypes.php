<?php
namespace Mad\Grid;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * De onde vem cada coluna SEM TIPO da grid exportada: de uma coluna numérica
 * do banco ou não?
 *
 * O PDO devolve `decimal` como string ("12.50") — exatamente como devolve um
 * varchar que o usuário preencheu com "4555.5555". Pelo valor os dois são
 * idênticos, e até a 5.94.0 o Excel transformava os dois em número: o
 * documento do cliente virava `4555,5555` (fórum #47). A listagem gerada emite
 * a coluna decimal sem `money`/`number`, então "texto sempre" também não
 * serve — ela deixaria de somar. Quem separa é a FONTE: o cast do model e,
 * sem ele, o tipo da coluna na tabela.
 *
 * Preenche `GridColumn::$sourceNumeric` (true/false). Fica null quando a
 * fonte não é descobrível (accessor, alias de query própria, template
 * composto, relação que não resolve) — aí o exportador decide pela coluna
 * inteira (GridExportCell::inferSourceNumeric).
 *
 * Uma consulta de schema por tabela por exportação. Nada de cache estático:
 * sob Octane o worker sobreviveria a uma migration e leria o tipo antigo.
 */
final class GridExportSourceTypes
{
    /** Casts do Eloquent que devolvem número (`decimal:2` normaliza para `decimal`). */
    private const NUMERIC_CASTS = ['int', 'integer', 'real', 'float', 'double', 'decimal'];

    /** `type_name` do Schema::getColumns() em pgsql / mysql / sqlite / sqlsrv. */
    private const NUMERIC_TYPE_RE = '/^(?:(?:tiny|small|medium|big)?int(?:eger)?\d*|(?:small|big)?serial\d*'
        . '|decimal|numeric|real|double(?: precision)?|float\d*|(?:small)?money|number)$/i';

    /** @var array<string, array<string, bool>|null> "conexão|tabela" => coluna => numérica */
    private array $schemaCache = [];

    /**
     * @param GridColumn[] $columns
     */
    public static function resolve(?string $modelClass, array $columns): void
    {
        if ($modelClass === null || !class_exists($modelClass)
            || !is_subclass_of($modelClass, Model::class)) {
            return;
        }

        $self = new self();
        foreach ($columns as $col) {
            if (self::needsHint($col)) {
                $col->sourceNumeric = $self->isNumericSource($modelClass, $col->field);
            }
        }
    }

    /**
     * As colunas da tabela do model: nome => é numérica? null = schema
     * ilegível. Uma consulta de schema por tabela por REQUISIÇÃO (memo do
     * DataScope, que não sobrevive ao worker) — quem pergunta é o render da
     * listagem (total do rodapé, ordenação de coluna calculada), várias vezes.
     *
     * @return array<string, bool>|null
     */
    public static function tableColumns(Model $model): ?array
    {
        $key = ($model->getConnectionName() ?? '') . '|' . $model->getTable();

        return \Mad\Database\DataScope::memo('grid.table_columns', $key, fn () => (new self())->columnTypes($model));
    }

    /** O cast do model devolve número? (`decimal:2` conta como `decimal`.) */
    public static function hasNumericCast(Model $model, string $column): bool
    {
        return $model->hasCast($column, self::NUMERIC_CASTS);
    }

    /** Só a coluna sem tipo declarado consulta a fonte — as outras já sabem o que são. */
    public static function needsHint(GridColumn $col): bool
    {
        return !$col->isMoney && !$col->isNumber && !$col->isDate && !$col->isBadge
            && !$col->isHtml && !$col->hasTransform && $col->builtinFormat === ''
            && $col->mediaFormat === '' && $col->evaluate === '' && $col->running === '';
    }

    /** true/false = fonte conhecida; null = não dá para afirmar. */
    private function isNumericSource(string $modelClass, string $field): ?bool
    {
        $path = GridRenderHelpers::stripBraces($field);
        // Template composto ("{a}/{b}") ou notação de ponto legada: sem fonte única.
        if ($path === '' || str_contains($path, '{') || str_contains($path, '}') || str_contains($path, '.')) {
            return null;
        }

        $segments = array_map('trim', explode('->', $path));
        $column   = array_pop($segments);
        $cursor   = $modelClass;
        foreach ($segments as $segment) {
            $rel = GridRenderHelpers::relationOn($cursor, $segment);
            if ($rel === null || $rel[1] === null) {
                return null;
            }
            $cursor = $rel[1];
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return null;
        }

        try {
            /** @var Model $model */
            $model = new $cursor();
        } catch (\Throwable) {
            return null;
        }

        if ($model->hasCast($column, self::NUMERIC_CASTS)) {
            return true;
        }
        if ($model->hasCast($column, ['string'])) {
            return false;
        }

        $types = $this->columnTypes($model);

        return $types[$column] ?? null;
    }

    /** @return array<string, bool>|null coluna => numérica; null = schema ilegível. */
    private function columnTypes(Model $model): ?array
    {
        $conn  = $model->getConnectionName();
        $table = $model->getTable();
        $key   = ($conn ?? '') . '|' . $table;

        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        try {
            $out = [];
            foreach (DB::connection($conn)->getSchemaBuilder()->getColumns($table) as $c) {
                $out[(string) $c['name']] = (bool) preg_match(self::NUMERIC_TYPE_RE, trim((string) ($c['type_name'] ?? '')));
            }
            $types = $out === [] ? null : $out;
        } catch (\Throwable) {
            $types = null;
        }

        return $this->schemaCache[$key] = $types;
    }
}
