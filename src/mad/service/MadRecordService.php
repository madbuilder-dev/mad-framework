<?php

namespace Mad\Service;

use Mad\Database\OrderGuard;
use Mad\Database\QuerySource;
use Mad\Form\ModelOptionsLoader;

/**
 * MadRecordService — base de serviços REST de Active Record (ex-serviço legado).
 *
 * Subclasses declaram as consts DATABASE e ACTIVE_RECORD (e opcionalmente
 * ATTRIBUTES) e ganham load/store/delete/loadAll/deleteAll/countAll + handle()
 * que despacha pelo verbo HTTP. Ex.: SystemUserRestService.
 *
 * Port pro Eloquent: o model resolve a própria conexão; DATABASE é mantido
 * por compatibilidade de assinatura (usado só nos paths QuerySource).
 */
class MadRecordService
{
    /**
     * Carrega um registro pelo id e devolve como array.
     */
    public function load($param)
    {
        $activeRecord = ModelOptionsLoader::resolveModelClass((string) static::ACTIVE_RECORD);

        return $this->exposed($activeRecord::query()->findOrFail($param['id'])->toArray());
    }

    /**
     * Apaga um registro pelo id.
     */
    public function delete($param)
    {
        $activeRecord = ModelOptionsLoader::resolveModelClass((string) static::ACTIVE_RECORD);

        $activeRecord::query()->findOrFail($param['id'])->delete();
    }

    /**
     * Cria/atualiza um registro a partir de $param['data'].
     */
    public function store($param)
    {
        $activeRecord = ModelOptionsLoader::resolveModelClass((string) static::ACTIVE_RECORD);

        /** @var \Illuminate\Database\Eloquent\Model $probe */
        $probe = new $activeRecord();
        $pk    = $probe->getKeyName();

        $data      = (array) ($param['data'] ?? []);
        $data[$pk] = $data['id'] ?? null;

        $object = !empty($data[$pk])
            ? ($activeRecord::query()->find($data[$pk]) ?? new $activeRecord())
            : new $activeRecord();

        foreach ($data as $col => $value) {
            if ($col === 'id' && $pk !== 'id') {
                continue;
            }
            $object->{$col} = $value;
        }
        $object->save();

        return $object->toArray();
    }

    /**
     * Lista registros com filtros/paginação/ordenação.
     */
    public function loadAll($param)
    {
        $q = $this->queryFromParam($param);

        if (isset($param['order']) && OrderGuard::isSafeOrderBy($param['order'])) {
            $dir = (isset($param['direction']) && in_array(strtolower((string) $param['direction']), ['asc', 'desc'], true))
                ? strtolower((string) $param['direction']) : 'asc';
            $q->orderByRaw((string) $param['order'] . ' ' . $dir);
        }
        if (isset($param['offset'])) $q->offset((int) $param['offset']);
        if (isset($param['limit']))  $q->limit((int) $param['limit']);

        return $q->get()->map(fn ($object) => $this->exposed($object->toArray()))->all();
    }

    /**
     * Apaga registros casando os filtros. Devolve o total apagado.
     */
    public function deleteAll($param)
    {
        return (int) $this->queryFromParam($param)->delete();
    }

    /**
     * Conta registros casando os filtros.
     */
    public function countAll($param)
    {
        return (int) $this->queryFromParam($param)->count();
    }

    /**
     * Despacha pelo verbo HTTP (GET=load/loadAll, POST/PUT=store, DELETE=delete/deleteAll).
     */
    public function handle($param)
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        unset($param['class'], $param['method']);
        $param['data'] = $param;

        switch ($method) {
            case 'GET':
                return !empty($param['id']) ? $this->load($param) : $this->loadAll($param);
            case 'POST':
            case 'PUT':
                return $this->store($param);
            case 'DELETE':
                return !empty($param['id']) ? $this->delete($param) : $this->deleteAll($param);
        }

        return null;
    }

    /**
     * Keeps only the columns listed in the optional ATTRIBUTES const, so a
     * service can hide fields (password hashes, tokens) from its responses.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function exposed(array $row): array
    {
        $attributes = defined('static::ATTRIBUTES') ? constant('static::ATTRIBUTES') : null;
        if (! $attributes) {
            return $row;
        }

        return array_intersect_key($row, array_flip((array) $attributes));
    }

    /** Builder a partir de $param['filters'] = [[col, op, valor], ...] (F4e, 100% Query Builder). */
    private function queryFromParam($param)
    {
        $cls = \Mad\Form\ModelOptionsLoader::resolveModelClass((string) static::ACTIVE_RECORD);
        $q   = $cls::query();
        // Filtros vindos do REQUEST: allowSubselect=false — um valor "SELECT ..."
        // NUNCA vira whereRaw (subselect cru); cai no ramo literal parametrizado.
        // É o ponto onde `filters` do cliente entra sem passar por closure/builder,
        // então este é o gate que impede a injeção via valor de filtro.
        \Mad\Database\QuerySource::applyArrayFilters($q, (array) ($param['filters'] ?? []), false);
        return $q;
    }
}
