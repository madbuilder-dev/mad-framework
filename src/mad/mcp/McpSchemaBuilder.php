<?php

namespace Mad\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * McpSchemaBuilder
 *
 * Monta o JSON Schema de input de cada tool a partir do bloco `fields` do
 * manifest (Contrato secao 4). Mapeia os tipos do builder (EntityColumn::TYPES)
 * para o builder Illuminate\JsonSchema.
 */
final class McpSchemaBuilder
{
    /**
     * Schema do create_*: um campo por expose=true (exceto PK), required quando required=true.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    public function createSchema(JsonSchema $schema, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (! empty($f['pk'])) {
                continue; // PK e auto no create
            }
            $type = $this->fieldType($schema, $f);
            if (! empty($f['required'])) {
                $type = $type->required();
            }
            $out[(string) $f['name']] = $type;
        }

        return $out;
    }

    /**
     * Schema do update_*: id (PK) required + campos expose opcionais.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    public function updateSchema(JsonSchema $schema, array $fields, string $pk): array
    {
        $out = [
            $pk => $schema->integer()->description('ID do registro a atualizar.')->required(),
        ];
        foreach ($fields as $f) {
            if (! empty($f['pk'])) {
                continue;
            }
            $out[(string) $f['name']] = $this->fieldType($schema, $f);
        }

        return $out;
    }

    /**
     * Schema de read_* / del_* (apenas a PK).
     *
     * @return array<string,mixed>
     */
    public function idSchema(JsonSchema $schema, string $pk, bool $confirm = false): array
    {
        $out = [
            $pk => $schema->integer()->description('ID do registro.')->required(),
        ];
        if ($confirm) {
            $out['confirm'] = $schema->boolean()
                ->description('Deve ser true para confirmar a operacao destrutiva.')
                ->required();
        }

        return $out;
    }

    /**
     * Schema generico do list_* (Contrato 3.1).
     *
     * @param array<int,string> $exposedColumns
     * @return array<string,mixed>
     */
    public function listSchema(JsonSchema $schema, array $exposedColumns): array
    {
        $colHint = $exposedColumns === [] ? '' : ' Colunas: ' . implode(', ', $exposedColumns) . '.';

        return [
            'filtros' => $schema->object()
                ->description('Filtros campo=>valor (igualdade). Apenas colunas expostas.' . $colHint),
            'ordenacao' => $schema->string()
                ->description('Coluna de ordenacao, opcional "asc"/"desc". Ex: "nome desc". SEM ordenacao a ordem e arbitraria — para "ultimos/mais recentes" ordene DESC pela data/id.'),
            'colunas' => $schema->array()
                ->items($schema->string())
                ->description('Subconjunto de colunas a retornar (todas expostas por padrao).'),
            'limite' => $schema->integer()
                ->description('Maximo de registros (1-500, default 50).')
                ->min(1)->max(500)->default(50),
        ];
    }

    /**
     * Schema de uma tool custom a partir de params declarados (ex: ["cliente_id?"]).
     *
     * @param array<int,string> $params
     * @return array<string,mixed>
     */
    public function paramsSchema(JsonSchema $schema, array $params): array
    {
        $out = [];
        foreach ($params as $param) {
            $name     = (string) $param;
            $optional = str_ends_with($name, '?');
            $name     = rtrim($name, '?');
            $type     = $schema->string()->description("Parametro {$name}.");
            if (! $optional) {
                $type = $type->required();
            }
            $out[$name] = $type;
        }

        return $out;
    }

    /**
     * Type Illuminate de um campo (sem required).
     *
     * @param array<string,mixed> $field
     */
    public function fieldType(JsonSchema $schema, array $field)
    {
        $type = strtolower((string) ($field['type'] ?? 'varchar'));
        $desc = (string) ($field['desc'] ?? ($field['name'] ?? ''));

        // enum explicito
        if (! empty($field['enum']) && is_array($field['enum'])) {
            return $schema->string()->description($desc)->enum(array_values($field['enum']));
        }

        $base = strtok($type, '('); // "decimal(12,2)" -> "decimal"

        $t = match ($base) {
            'integer', 'bigint', 'int', 'smallint', 'tinyint' => $schema->integer()->description($desc),
            'double', 'float', 'decimal', 'numeric', 'real'    => $schema->number()->description($desc),
            'boolean', 'bool'                                  => $schema->boolean()->description($desc),
            'date'                                             => $schema->string()->description($desc)->format('date'),
            'datetime', 'timestamp'                            => $schema->string()->description($desc)->format('date-time'),
            default                                            => $this->stringType($schema, $desc, $type),
        };

        return $t;
    }

    private function stringType(JsonSchema $schema, string $desc, string $type)
    {
        $t = $schema->string()->description($desc);
        // varchar(120) / char(20) -> max length
        if (preg_match('/\((\d+)/', $type, $m)) {
            $t = $t->max((int) $m[1]);
        }

        return $t;
    }
}
