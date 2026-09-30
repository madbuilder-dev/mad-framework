<?php

namespace Mad\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * JsonSchemaBuilder — converte um spec JSON-Schema (array, espelho do
 * block-schemas.ts) na estrutura `array<string, Type>` que o
 * Laravel\Ai\Contracts\Tool::schema() deve retornar.
 *
 * O McpSchemaBuilder do MCP e flat (sem ->items()); este suporta arrays de
 * objetos aninhados (kpis.items[], line.series[].points[]) usando o
 * JsonSchemaTypeFactory que o laravel/ai injeta. `required` por propriedade e
 * aplicado via Type::required() (o ObjectSchema deriva o required[] disso).
 */
final class JsonSchemaBuilder
{
    /**
     * Mapa name=>Type para o nivel raiz (retorno de Tool::schema()).
     *
     * @param array<string, mixed> $objectSpec  spec do tipo object
     * @return array<string, Type>
     */
    public static function properties(JsonSchema $factory, array $objectSpec): array
    {
        $properties = $objectSpec['properties'] ?? [];
        $required   = $objectSpec['required'] ?? [];

        $out = [];
        foreach ($properties as $name => $spec) {
            $type = self::type($factory, (array) $spec);
            if (in_array($name, $required, true)) {
                $type = $type->required();
            }
            $out[$name] = $type;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function type(JsonSchema $factory, array $spec): Type
    {
        $kind = $spec['type'] ?? 'string';

        $type = match ($kind) {
            'object'  => $factory->object(self::properties($factory, $spec)),
            'array'   => $factory->array()->items(self::type($factory, (array) ($spec['items'] ?? ['type' => 'string']))),
            'integer' => $factory->integer(),
            'number'  => $factory->number(),
            'boolean' => $factory->boolean(),
            default   => $factory->string(),
        };

        if (! empty($spec['enum']) && is_array($spec['enum']) && method_exists($type, 'enum')) {
            $type = $type->enum($spec['enum']);
        }

        if (! empty($spec['description']) && method_exists($type, 'description')) {
            $type = $type->description((string) $spec['description']);
        }

        return $type;
    }
}
