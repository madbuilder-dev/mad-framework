<?php

declare(strict_types=1);

namespace Mad\Ui\Search;

/**
 * Base com valores padrão: o app escreve só o `search()` (e o que quiser
 * trocar). `matching()` monta o filtro "contém o termo" em várias colunas.
 *
 *   class AtividadeBusca extends BaseRecordSearchProvider
 *   {
 *       public function label(): string { return 'Atividades'; }
 *
 *       public function search(string $term, int $limit): iterable
 *       {
 *           return $this->matching(Atividade::query(), ['codigo', 'titulo'], $term)
 *               ->limit($limit)->get()
 *               ->map(fn ($a) => new SearchResult($a->titulo, $a->codigo, screen: 'AtividadeForm', id: $a->id));
 *       }
 *   }
 */
abstract class BaseRecordSearchProvider implements RecordSearchProvider
{
    /** Nome da classe sem o sufixo ("AtividadeSearchProvider" → "atividade"). */
    public function key(): string
    {
        $base = preg_replace('/(SearchProvider|RecordSearch|Search|Provider|Busca)$/', '', self::basename(static::class)) ?: self::basename(static::class);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }

    public function label(): string
    {
        return self::basename(static::class);
    }

    public function icon(): string
    {
        return 'lucide:file-text';
    }

    public function program(): ?string
    {
        return null;
    }

    /**
     * `where (col1 contém termo OR col2 contém termo …)`, sem diferenciar
     * maiúsculas (ILIKE no PostgreSQL). `%` e `_` digitados valem como texto.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     * @param T $query
     * @param list<string> $columns
     * @return T
     */
    protected function matching($query, array $columns, string $term)
    {
        $term = trim($term);
        if ($term === '' || $columns === []) {
            return $query;
        }

        $conn = $query->getConnection();
        $op = $conn->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $value = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $grammar = $conn->getQueryGrammar();

        return $query->where(function ($w) use ($columns, $op, $value, $grammar) {
            foreach ($columns as $col) {
                $w->orWhereRaw($grammar->wrap($col) . " {$op} ? ESCAPE '!'", [$value]);
            }
        });
    }

    private static function basename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
