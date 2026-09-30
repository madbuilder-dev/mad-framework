<?php

namespace Mad\Form;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Violação de UNIQUE do banco → as colunas envolvidas.
 *
 * Usado por MadForm::save() para trocar o erro cru do banco ("SQLSTATE[23000]
 * … Duplicate entry '999' for key 'clientes_cpf_unique'") pela mensagem da
 * rule `unique` no próprio campo. A rule do model já barra a maioria das
 * duplicatas; o banco é a última linha: corrida entre dois saves, model
 * escrito à mão sem `rules()`, índice composto que a rule não descreve.
 *
 * O Laravel já interpreta a mensagem do driver
 * (`UniqueConstraintViolationException::$columns` / `$index`):
 *   PostgreSQL → colunas ("Key (cpf)=(999) already exists") + índice
 *   SQLite     → colunas ("UNIQUE constraint failed: clientes.cpf")
 *   MySQL/Maria→ só o NOME do índice — as colunas saem do schema.
 */
final class MadUniqueViolation
{
    /** @return list<string> colunas do índice violado ([] = não identificado) */
    public static function columns(UniqueConstraintViolationException $e, ?Model $record = null): array
    {
        $columns = array_values(array_filter(array_map(
            static fn ($c) => trim((string) $c, " \t\"`'"),
            $e->columns,
        )));
        if ($columns !== []) {
            return $columns;
        }

        $index = (string) ($e->index ?? '');
        if ($index === '' || $record === null) {
            return [];
        }

        // Consulta só fora do PostgreSQL: lá o erro ABORTA a transação e
        // qualquer SELECT seguinte falha ("current transaction is aborted") —
        // e lá a mensagem já trouxe as colunas. No MySQL/MariaDB a transação
        // segue viva depois de um 1062.
        $connection = $record->getConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            try {
                foreach ($connection->getSchemaBuilder()->getIndexes($record->getTable()) as $idx) {
                    if (strcasecmp((string) ($idx['name'] ?? ''), $index) === 0) {
                        return array_values(array_map('strval', $idx['columns'] ?? []));
                    }
                }
            } catch (\Throwable) {
                // sem permissão no information_schema, driver exótico: heurística abaixo
            }
        }

        return self::fromIndexName($index, $record->getTable(), array_keys($record->getAttributes()));
    }

    /**
     * Colunas pelo nome do índice na convenção do Laravel
     * (`{tabela}_{col1}_{col2}_unique`), casando com os atributos do record.
     * Nome fora da convenção (`uq_documento`) → [].
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public static function fromIndexName(string $index, string $table, array $candidates): array
    {
        $name   = strtolower($index);
        $prefix = strtolower(str_replace(['-', '.'], '_', $table)) . '_';
        if (str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }
        if (str_ends_with($name, '_unique')) {
            $name = substr($name, 0, -strlen('_unique'));
        }

        // Maior primeiro: `unit_id` casa antes de `id` e sai do texto.
        $bySize = $candidates;
        usort($bySize, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        $rest  = '_' . $name . '_';
        $found = [];
        foreach ($bySize as $col) {
            $token = '_' . strtolower((string) $col) . '_';
            $pos   = strpos($rest, $token);
            if ($pos !== false) {
                $found[$pos] = (string) $col;
                $rest = substr_replace($rest, str_repeat('#', strlen($token) - 1) . '_', $pos, strlen($token));
            }
        }
        ksort($found);

        return array_values($found);
    }
}
