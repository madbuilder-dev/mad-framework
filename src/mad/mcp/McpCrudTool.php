<?php

namespace Mad\Mcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * McpCrudTool
 *
 * Tool CRUD generica (Contrato 3.1). UMA instancia por verbo habilitado de uma
 * entidade exposta. O verbo (list|read|create|update|del) vem do spec.
 */
final class McpCrudTool extends McpManifestTool
{
    private function verb(): string
    {
        return (string) ($this->spec['verb'] ?? 'list');
    }

    private function pk(): string
    {
        return $this->manifest->primaryKeyFor($this->entity());
    }

    public function annotations(): array
    {
        return match ($this->verb()) {
            'list', 'read' => ['readOnlyHint' => true],
            'update'       => ['idempotentHint' => true],
            'del'          => ['destructiveHint' => true],
            default        => [],
        };
    }

    public function schema(JsonSchema $schema): array
    {
        $b      = $this->schemaBuilder();
        $fields = $this->manifest->exposedFieldsFor($this->entity());

        return match ($this->verb()) {
            'list'   => $b->listSchema($schema, $this->manifest->exposedColumnNamesFor($this->entity())),
            'read'   => $b->idSchema($schema, $this->pk()),
            'create' => $b->createSchema($schema, $fields),
            'update' => $b->updateSchema($schema, $fields, $this->pk()),
            'del'    => $b->idSchema($schema, $this->pk(), confirm: true),
            default  => [],
        };
    }

    public function handle(Request $request): Response
    {
        return match ($this->verb()) {
            'list'   => $this->doList($request),
            'read'   => $this->doRead($request),
            'create' => $this->doCreate($request),
            'update' => $this->doUpdate($request),
            'del'    => $this->doDelete($request),
            default  => Response::error('Verbo desconhecido: ' . $this->verb()),
        };
    }

    private function doList(Request $request): Response
    {
        $entity   = $this->entity();
        $exposed  = $this->manifest->exposedColumnNamesFor($entity);
        $filtros  = (array) ($request->get('filtros', []) ?? []);
        $colunas  = (array) ($request->get('colunas', []) ?? []);
        $order    = $request->get('ordenacao');
        $limite   = (int) ($request->get('limite', 50) ?? 50);

        $columns = $colunas !== [] ? array_values(array_intersect($colunas, $exposed)) : $exposed;
        if ($columns === []) {
            $columns = $exposed;
        }

        $filters = [];
        foreach ($filtros as $col => $val) {
            if (in_array($col, $exposed, true)) {
                $filters[] = [(string) $col, '=', $val];
            }
        }

        try {
            $rows  = $this->gateway()->select($entity, $columns, $filters, is_string($order) ? $order : null, $exposed, $limite);
            $total = $this->gateway()->count($entity, $filters);
        } catch (\Throwable $e) {
            return Response::error('Erro no list: ' . $e->getMessage());
        }

        McpScopeAudit::query($entity, $this->name(), $filtros); // no-op se row-scope off

        return Response::json([
            'total' => $total,
            'rows'  => $this->masker()->maskRows($rows),
        ]);
    }

    private function doRead(Request $request): Response
    {
        $pk = $this->pk();
        $id = $request->get($pk);
        if ($id === null || $id === '') {
            return Response::error("Parametro {$pk} obrigatorio.");
        }

        try {
            $row = $this->gateway()->find($this->entity(), $pk, $id, $this->manifest->exposedColumnNamesFor($this->entity()));
        } catch (\Throwable $e) {
            return Response::error('Erro no read: ' . $e->getMessage());
        }

        if ($row === null) {
            return Response::error("Registro {$id} nao encontrado.");
        }

        return Response::json($this->masker()->maskRow($row));
    }

    private function doCreate(Request $request): Response
    {
        try {
            McpWriteGuard::assertAllowed($this->spec, (bool) $request->get('confirm', false));
        } catch (McpScopeException $e) {
            return Response::error($e->getMessage());
        }

        [$data, $errors] = $this->collect($request, requireRequired: true);
        if ($errors !== []) {
            return Response::error('Validacao: ' . implode(' ', $errors));
        }
        if ($data === []) {
            return Response::error('Nenhum campo informado.');
        }

        try {
            [$id, $row] = DB::connection($this->db())->transaction(function () use ($data) {
                $id  = $this->gateway()->insert($this->entity(), $data);
                $row = $this->gateway()->find($this->entity(), $this->pk(), $id, $this->manifest->exposedColumnNamesFor($this->entity()));

                return [$id, $row];
            });
        } catch (\Throwable $e) {
            return Response::error('Erro no create: ' . $e->getMessage());
        }

        McpChangeAudit::record($this->entity(), $this->pk(), $id, 'insert', [], $data);

        return Response::json([
            'created' => true,
            'id'      => $id,
            'row'     => $row !== null ? $this->masker()->maskRow($row) : null,
        ]);
    }

    private function doUpdate(Request $request): Response
    {
        try {
            McpWriteGuard::assertAllowed($this->spec, (bool) $request->get('confirm', false));
        } catch (McpScopeException $e) {
            return Response::error($e->getMessage());
        }

        $pk = $this->pk();
        $id = $request->get($pk);
        if ($id === null || $id === '') {
            return Response::error("Parametro {$pk} obrigatorio.");
        }

        [$data, $errors] = $this->collect($request, requireRequired: false);
        if ($errors !== []) {
            return Response::error('Validacao: ' . implode(' ', $errors));
        }
        if ($data === []) {
            return Response::error('Nenhum campo para atualizar.');
        }

        try {
            $res = DB::connection($this->db())->transaction(function () use ($pk, $id, $data) {
                $old = $this->gateway()->find($this->entity(), $pk, $id);
                if ($old === null) {
                    return ['notfound' => true];
                }
                $this->gateway()->update($this->entity(), $pk, $id, $data);
                $row = $this->gateway()->find($this->entity(), $pk, $id, $this->manifest->exposedColumnNamesFor($this->entity()));

                return ['old' => $old, 'row' => $row];
            });
        } catch (\Throwable $e) {
            return Response::error('Erro no update: ' . $e->getMessage());
        }

        if (! empty($res['notfound'])) {
            return Response::error("Registro {$id} nao encontrado.");
        }
        $old = $res['old'];
        $row = $res['row'];

        McpChangeAudit::record($this->entity(), $pk, $id, 'update', $old, $data);

        return Response::json([
            'updated' => true,
            'id'      => $id,
            'row'     => $row !== null ? $this->masker()->maskRow($row) : null,
        ]);
    }

    private function doDelete(Request $request): Response
    {
        $pk = $this->pk();
        $id = $request->get($pk);
        if ($id === null || $id === '') {
            return Response::error("Parametro {$pk} obrigatorio.");
        }
        if (! (bool) $request->get('confirm', false)) {
            return Response::error('Operacao destrutiva nao confirmada. Reenvie com confirm=true.');
        }

        try {
            $res = DB::connection($this->db())->transaction(function () use ($pk, $id) {
                $old = $this->gateway()->find($this->entity(), $pk, $id);
                if ($old === null) {
                    return ['notfound' => true];
                }
                $this->gateway()->delete($this->entity(), $pk, $id);

                return ['old' => $old];
            });
        } catch (\Throwable $e) {
            return Response::error('Erro no delete: ' . $e->getMessage());
        }

        if (! empty($res['notfound'])) {
            return Response::error("Registro {$id} nao encontrado.");
        }
        $old = $res['old'];

        McpChangeAudit::record($this->entity(), $pk, $id, 'delete', $old, []);

        return Response::json(['deleted' => true, 'id' => $id]);
    }

    /**
     * Coleta + valida campos expostos do request.
     *
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    private function collect(Request $request, bool $requireRequired): array
    {
        $data   = [];
        $errors = [];
        foreach ($this->manifest->exposedFieldsFor($this->entity()) as $f) {
            $name = (string) $f['name'];
            if (! empty($f['pk'])) {
                continue;
            }
            $val = $request->get($name);

            if ($val === null || $val === '') {
                if ($requireRequired && ! empty($f['required'])) {
                    $errors[] = "campo '{$name}' obrigatorio.";
                }
                continue;
            }

            if (! empty($f['enum']) && is_array($f['enum']) && ! in_array($val, $f['enum'], true)) {
                $errors[] = "campo '{$name}' deve ser um de: " . implode(',', $f['enum']) . '.';
                continue;
            }

            $data[$name] = $this->coerce($val, (string) ($f['type'] ?? ''));
        }

        return [$data, $errors];
    }

    private function coerce(mixed $val, string $type): mixed
    {
        $base = strtok(strtolower($type), '(');

        return match ($base) {
            'integer', 'bigint', 'int', 'smallint', 'tinyint' => (int) $val,
            'double', 'float', 'decimal', 'numeric', 'real'    => (float) $val,
            'boolean', 'bool'                                  => (bool) $val ? 1 : 0,
            default                                            => $val,
        };
    }
}
