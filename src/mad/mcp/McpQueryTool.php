<?php

namespace Mad\Mcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * McpQueryTool
 *
 * Tool de query salva (Contrato 3.2). A estrutura (colunas/filtros/order/limit)
 * e FIXA no manifest; os `params` viram args dinamicos, sempre com bind
 * parametrizado (nunca concatenado). Somente leitura.
 *
 * spec.query = { columns[], filters[[col,op,val]], order, limit }
 * spec.params = ["cliente_id?", ...]  ('?' = opcional)
 */
final class McpQueryTool extends McpManifestTool
{
    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    /** @return array<int,string> */
    private function params(): array
    {
        return array_map('strval', (array) ($this->spec['params'] ?? []));
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->schemaBuilder()->paramsSchema($schema, $this->params());
    }

    public function handle(Request $request): Response
    {
        $entity  = $this->entity();
        $query   = (array) ($this->spec['query'] ?? []);
        $exposed = $this->manifest->exposedColumnNamesFor($entity);

        $columns = (array) ($query['columns'] ?? []);
        $columns = $columns !== [] ? array_values(array_intersect($columns, $exposed)) : $exposed;
        if ($columns === []) {
            $columns = $exposed;
        }

        // filtros fixos da query salva
        $filters = [];
        foreach ((array) ($query['filters'] ?? []) as $f) {
            if (is_array($f) && count($f) >= 3) {
                $filters[] = [(string) $f[0], (string) $f[1], $f[2]];
            }
        }

        // params dinamicos -> filtro de igualdade (param obrigatorio ausente = erro)
        foreach ($this->params() as $param) {
            $optional = str_ends_with($param, '?');
            $col      = rtrim($param, '?');
            $val      = $request->get($col);
            if ($val === null || $val === '') {
                if (! $optional) {
                    return Response::error("Parametro '{$col}' obrigatorio.");
                }
                continue;
            }
            $filters[] = [$col, '=', $val];
        }

        $order = isset($query['order']) ? (string) $query['order'] : null;
        $limit = (int) ($query['limit'] ?? 50);

        try {
            $rows = $this->gateway()->select($entity, $columns, $filters, $order, $exposed, $limit);
        } catch (\Throwable $e) {
            return Response::error('Erro na query: ' . $e->getMessage());
        }

        return Response::json([
            'total' => count($rows),
            'rows'  => $this->masker()->maskRows($rows),
        ]);
    }
}
