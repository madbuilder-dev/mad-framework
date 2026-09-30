<?php

namespace Mad\Rest;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ApiResourceController — base REST/CRUD para models Eloquent.
 *
 * Port Eloquent-native do antigo Mad\Rest\ApiResourceController (legado). Casa
 * com Route::apiResource (index/show/store/update/destroy), recebe
 * Illuminate\Http\Request e devolve JsonResponse. Atomicidade master+detalhes
 * via DB::transaction() na conexão do model.
 *
 * O que ganha sobre o original:
 *  - MÚLTIPLOS detalhes, dirigidos por relações Eloquent (hasMany) já declaradas
 *    no model. A FK vem da própria relação ($details = ['items','payments']).
 *  - SYNC de detalhes no update: cria novos, atualiza por PK, APAGA os ausentes
 *    (o original nunca apagava → órfãos).
 *  - Regras de carregamento default: $with / $withCount eager-load (sem N+1; os
 *    detalhes entram automaticamente no eager-load do index).
 *  - Validação pela convenção Model::rules() (master e cada detail model).
 *  - destroy() apaga detalhes em cascata e faz 404 de verdade.
 *  - Sem render(): projeção via dot-notation de relações + accessors ($appends).
 *
 * Exemplo:
 *   class OrderApiController extends ApiResourceController
 *   {
 *       protected string $model    = \App\Models\Order::class;
 *       protected array  $searchable = ['status', 'customer_id'];
 *       protected array  $sortable   = ['created_at', 'total'];
 *       protected array  $with       = ['customer'];
 *       protected array  $details    = ['items', 'payments'];
 *   }
 *   // routes: Route::apiResource('orders', OrderApiController::class);
 */
abstract class ApiResourceController
{
    /** FQCN do model (Eloquent\Model). Obrigatório. */
    protected string $model;

    /** PK do recurso. Null → deriva de $model->getKeyName(). */
    protected ?string $primaryKey = null;

    /** Colunas filtráveis (whitelist do DSL de filtros). */
    protected array $searchable = [];

    /** Colunas ordenáveis (whitelist de sort). */
    protected array $sortable = [];

    /** Eager-load default (regras de carregamento). Os $details entram junto. */
    protected array $with = [];

    /** withCount default. */
    protected array $withCount = [];

    /** Accessors computados a incluir na resposta (Model::append). */
    protected array $appends = [];

    /** Projeção da listagem. Vazio = todos menos $hidden. Suporta 'rel.campo' e alias. */
    protected array $indexFields = [];

    /** Projeção do show. Vazio = todos menos $hidden. */
    protected array $showFields = [];

    /** Campos sempre removidos da resposta. */
    protected array $hidden = [];

    /** Ordem default: "coluna asc|desc". Aplicada quando não vem sort no request. */
    protected ?string $defaultOrder = null;

    /** Itens por página default. */
    protected int $perPage = 15;

    /** Teto de itens por página (defesa contra per_page abusivo). */
    protected int $maxPerPage = 200;

    /**
     * Relações hasMany tratadas como detalhes (mestre-detalhe). Múltiplas.
     * Ex.: ['items', 'payments']. A FK vem da relação; sync por diff de PK.
     */
    protected array $details = [];

    /** Transformers do master: campo => fn($value, $model). */
    protected array $transformers = [];

    public function __construct()
    {
        if (empty($this->model) || !class_exists($this->model)) {
            throw new \LogicException(static::class . ': defina $model com o FQCN do Record.');
        }
    }

    // ─── Ações REST ─────────────────────────────────────────────────────────

    /** GET /recurso — lista paginada com filtros/ordenação/eager-load. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('index', $request);

        $query = $this->newQuery();
        $this->applyFilters($query, (array) $request->input('filters', []));
        $this->applyOrder($query, $request);

        $total   = (clone $query)->toBase()->getCountForPagination();
        $perPage = $this->resolvePerPage($request);
        $page    = max(1, (int) $request->input('page', 1));

        $items = $query->forPage($page, $perPage)->get();

        $data = [];
        foreach ($items as $object) {
            $data[] = $this->serializeItem($object, $this->indexFields);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /** GET /recurso/{id} — um registro. */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('show', $request);

        $object = $this->newQuery()->find($this->resolveId($request));
        if (!$object) {
            return $this->notFound();
        }

        return response()->json($this->serializeItem($object, $this->showFields));
    }

    /** POST /recurso — cria master (+ detalhes). */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('store', $request);

        $object = $this->persist($request, null);

        return response()->json($this->serializeFresh($object), 201);
    }

    /** PUT/PATCH /recurso/{id} — atualiza master e SINCRONIZA detalhes. */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('update', $request);

        $id = $this->resolveId($request);
        if (!$this->newModel()->newQuery()->whereKey($id)->exists()) {
            return $this->notFound();
        }

        $object = $this->persist($request, $id);

        return response()->json($this->serializeFresh($object));
    }

    /** DELETE /recurso/{id} — apaga master + detalhes em cascata. */
    public function destroy(Request $request): JsonResponse
    {
        $this->authorize('destroy', $request);

        $id     = $this->resolveId($request);
        $object = $this->newModel()->newQuery()->find($id);
        if (!$object) {
            return $this->notFound();
        }

        DB::connection($this->connectionName())->transaction(function () use ($object) {
            foreach ($this->details as $relation) {
                foreach ($this->relation($object, $relation)->get() as $child) {
                    $child->delete();
                }
            }
            $this->beforeDelete($object);
            $object->delete();
        });

        return response()->json(['message' => 'Resource deleted successfully']);
    }

    // ─── Persistência (store + update compartilham) ───────────────────────────

    /**
     * Cria ($id null) ou atualiza ($id) o master e sincroniza os detalhes,
     * tudo numa transação. Valida master e cada linha de detalhe ANTES de
     * escrever (ValidationException → rollback + 422).
     */
    protected function persist(Request $request, $id): Model
    {
        $payload = $request->all();

        // Separa os detalhes do payload do master.
        $detailPayload = [];
        foreach ($this->details as $relation) {
            if (array_key_exists($relation, $payload)) {
                $detailPayload[$relation] = (array) ($payload[$relation] ?? []);
                unset($payload[$relation]);
            }
        }

        return DB::connection($this->connectionName())->transaction(function () use ($payload, $detailPayload, $id) {
            $master = $id !== null
                ? $this->newQuery()->findOrFail($id)
                : $this->newModel();

            // Validação (master + cada detalhe) antes de qualquer escrita.
            $this->validateData($this->model, $payload, $id);
            foreach ($detailPayload as $relation => $rows) {
                $detailClass = get_class($this->relation($master, $relation)->getRelated());
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $this->validateData($detailClass, $row, $row[$this->keyOf($detailClass)] ?? null);
                }
            }

            // Escrita do master.
            $this->beforeSave($master, $payload);
            $master->fill($payload);
            $master->save();
            $this->afterSave($master, $payload);

            // Sync de cada detalhe.
            foreach ($detailPayload as $relation => $rows) {
                $this->syncDetails($master, $relation, $rows);
            }

            return $master;
        });
    }

    /**
     * Sincroniza uma relação hasMany: cria linhas sem PK, atualiza por PK e
     * APAGA as que sumiram do payload (diff). A FK vem da própria relação.
     */
    protected function syncDetails(Model $master, string $relation, array $rows): void
    {
        $hasMany = $this->relation($master, $relation);
        $related = $hasMany->getRelated();
        $foreign = $hasMany->getForeignKeyName();
        $pk      = $related->getKeyName();

        $kept = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $existingId = $row[$pk] ?? null;

            $child = $existingId
                ? ((clone $hasMany)->whereKey($existingId)->first() ?? $related->newInstance())
                : $related->newInstance();

            $child->fill($row);
            $child->{$foreign} = $master->getKey();

            $this->beforeSaveDetail($master, $child, $relation, $row);
            $child->save();
            $this->afterSaveDetail($master, $child, $relation, $row);

            $kept[] = $child->getKey();
        }

        // Apaga os detalhes que não vieram no payload (respeita soft-delete).
        foreach ((clone $hasMany)->get() as $child) {
            if (!in_array($child->getKey(), $kept, false)) {
                $child->delete();
            }
        }
    }

    // ─── Query / filtros / ordenação ──────────────────────────────────────────

    /** Query base com eager-load default (with + withCount + detalhes). */
    protected function newQuery(): Builder
    {
        $with = array_values(array_unique(array_merge($this->with, $this->details)));

        $query = $this->model::query();
        if ($with) {
            $query->with($with);
        }
        if ($this->withCount) {
            $query->withCount($this->withCount);
        }

        return $query;
    }

    /**
     * Traduz o DSL JSON de filtros para o query builder.
     * Shape: filters = { coluna: { operador: valor } }. Só colunas em $searchable.
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $field => $conditions) {
            if (!in_array($field, $this->searchable, true) || !is_array($conditions)) {
                continue;
            }

            foreach ($conditions as $operator => $value) {
                $this->applyFilter($query, $field, (string) $operator, $value);
            }
        }
    }

    /** Aplica um único operador de filtro. */
    protected function applyFilter(Builder $query, string $field, string $operator, $value): void
    {
        $isNullOp  = in_array($operator, ['is null', 'is_null', 'is not null', 'is_not_null'], true);
        $isRangeOp = in_array($operator, ['between', 'in', 'not in', 'not_in'], true);

        // Mesma regra do legado: ignora valores vazios (exceto 0 / '0'),
        // mas operadores de presença/range têm semântica própria.
        if (!$isNullOp && !$isRangeOp
            && (($value === '' || $value === null || $value === []) && $value !== '0' && $value !== 0)) {
            return;
        }

        switch ($operator) {
            case '=':
            case 'eq':          $query->where($field, '=', $value); break;
            case '!=':
            case 'ne':
            case 'not':         $query->where($field, '!=', $value); break;
            case '>':
            case 'gt':          $query->where($field, '>', $value); break;
            case '>=':
            case 'gte':         $query->where($field, '>=', $value); break;
            case '<':
            case 'lt':          $query->where($field, '<', $value); break;
            case '<=':
            case 'lte':         $query->where($field, '<=', $value); break;

            case 'like':        $query->where($field, 'like', "%{$value}%"); break;
            case 'like_start':  $query->where($field, 'like', "{$value}%"); break;
            case 'like_end':    $query->where($field, 'like', "%{$value}"); break;

            // ilike portável: lower(col) like lower(?). $field é da whitelist.
            case 'ilike':       $this->whereILike($query, $field, "%{$value}%"); break;
            case 'ilike_start': $this->whereILike($query, $field, "{$value}%"); break;
            case 'ilike_end':   $this->whereILike($query, $field, "%{$value}"); break;

            case 'in':          $query->whereIn($field, (array) $value); break;
            case 'not in':
            case 'not_in':      $query->whereNotIn($field, (array) $value); break;

            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($field, [$value[0], $value[1]]);
                }
                break;

            case 'is null':
            case 'is_null':     if ($value) { $query->whereNull($field); } break;
            case 'is not null':
            case 'is_not_null': if ($value) { $query->whereNotNull($field); } break;
        }
    }

    /** ilike portável (sqlite/mysql case-insensitive; pgsql via lower()). */
    protected function whereILike(Builder $query, string $field, string $pattern): void
    {
        $column = $query->getQuery()->getGrammar()->wrap($field);
        $query->whereRaw("lower({$column}) like ?", [mb_strtolower($pattern)]);
    }

    /** Itens por página efetivos (request per_page, com teto $maxPerPage). */
    protected function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', $this->perPage);
        if ($perPage < 1) {
            $perPage = $this->perPage;
        }
        return min($perPage, $this->maxPerPage);
    }

    /** Ordenação: sort+direction (whitelist) ou $defaultOrder. */
    protected function applyOrder(Builder $query, Request $request): void
    {
        $sort      = $request->input('sort');
        $direction = strtolower((string) $request->input('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        if ($sort && in_array($sort, $this->sortable, true)) {
            $query->orderBy($sort, $direction);
            return;
        }

        if ($this->defaultOrder) {
            $parts = preg_split('/\s+/', trim($this->defaultOrder));
            $col   = $parts[0];
            $dir   = strtolower($parts[1] ?? 'asc');
            $query->orderBy($col, in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc');
        }
    }

    // ─── Serialização ─────────────────────────────────────────────────────────

    /** Recarrega o master com o eager-load default e serializa (resposta de write). */
    protected function serializeFresh(Model $object): array
    {
        $fresh = $this->newQuery()->find($object->getKey()) ?? $object;
        return $this->serializeItem($fresh, $this->showFields);
    }

    /**
     * Serializa um registro. $fields vazio → toArray (todos menos $hidden).
     * Com $fields → projeção, suportando 'rel.campo' (achata p/ rel_campo) e
     * alias (chave => campo). Não vaza a PK se ela estiver em $hidden.
     */
    protected function serializeItem(Model $object, array $fields): array
    {
        if ($this->appends) {
            $object->append($this->appends);
        }
        if ($this->hidden) {
            $object->makeHidden($this->hidden);
        }

        if (empty($fields)) {
            return $this->applyTransformers($object->toArray(), $object);
        }

        $data = [];
        foreach ($fields as $key => $field) {
            $outputKey = is_string($key) ? $key : $field;
            $outputKey = str_replace('.', '_', trim($outputKey, '{}'));

            if (in_array($field, $this->hidden, true)) {
                continue;
            }

            $data[$outputKey] = data_get($object, $field);
        }

        // PK sempre presente — exceto se explicitamente escondida.
        $pk = $this->keyName();
        if (!array_key_exists($pk, $data) && !in_array($pk, $this->hidden, true)) {
            $data[$pk] = $object->getKey();
        }

        // Detalhes eager-loaded entram pela sua relação.
        foreach ($this->details as $relation) {
            if ($object->relationLoaded($relation)) {
                $data[$relation] = $object->getRelation($relation)->toArray();
            }
        }

        return $this->applyTransformers($data, $object);
    }

    /** Registra um transformer de campo do master. */
    public function addTransformer(string $field, callable $transformer): static
    {
        $this->transformers[$field] = $transformer;
        return $this;
    }

    /** Aplica os transformers registrados sobre o array de saída. */
    protected function applyTransformers(array $data, Model $object): array
    {
        foreach ($this->transformers as $field => $transformer) {
            if (array_key_exists($field, $data) && is_callable($transformer)) {
                $data[$field] = $transformer($data[$field], $object);
            }
        }
        return $data;
    }

    // ─── Validação ─────────────────────────────────────────────────────────────

    /**
     * Valida $data contra Model::rules($id). Lança ValidationException (→ 422)
     * em falha. Sem rules() no model, passa direto.
     */
    protected function validateData(string $modelClass, array $data, $id = null): array
    {
        if (!method_exists($modelClass, 'rules')) {
            return $data;
        }

        $rules = (array) $modelClass::rules($id);
        if (!$rules) {
            return $data;
        }

        $validator = validator($data, $rules);
        // Mesmo verificador dos formulários: com Multi-unidade/tenant em pool,
        // `unique` e `exists` contam só as linhas da unidade corrente (um código
        // de outra unidade não bloqueia; um id de outra unidade não vale).
        $verifier = \Mad\Form\MadValidator::presenceVerifier();
        if ($verifier !== null) {
            $validator->setPresenceVerifier($verifier);
        }

        return $validator->validate();
    }

    // ─── Hooks (override opcional na subclasse) ─────────────────────────────────

    /** Autorização por ação ('index'|'show'|'store'|'update'|'destroy'). No-op default. */
    protected function authorize(string $action, Request $request): void {}

    protected function beforeSave(Model $object, array $data): void {}
    protected function afterSave(Model $object, array $data): void {}
    protected function beforeSaveDetail(Model $master, Model $detail, string $relation, array $data): void {}
    protected function afterSaveDetail(Model $master, Model $detail, string $relation, array $data): void {}
    protected function beforeDelete(Model $object): void {}

    // ─── Helpers internos ───────────────────────────────────────────────────────

    /** Instância nova do model. */
    protected function newModel(): Model
    {
        $class = $this->model;
        return new $class();
    }

    /** Nome da PK do recurso (override $primaryKey ou deriva do model). */
    protected function keyName(): string
    {
        return $this->primaryKey ?? $this->newModel()->getKeyName();
    }

    /** Nome da PK de um model arbitrário (detail). */
    protected function keyOf(string $modelClass): string
    {
        return (new $modelClass())->getKeyName();
    }

    /** Conexão do model (null → default das conexões configuradas). */
    protected function connectionName(): ?string
    {
        return $this->newModel()->getConnectionName();
    }

    /** Resolve a relação hasMany pelo nome (valida que é hasMany). */
    protected function relation(Model $object, string $name): HasMany
    {
        $relation = $object->{$name}();
        if (!$relation instanceof HasMany) {
            throw new \LogicException(static::class . ": detalhe '{$name}' deve ser uma relação hasMany.");
        }
        return $relation;
    }

    /**
     * Id do recurso a partir da rota (último parâmetro da rota do apiResource)
     * ou, em fallback, do corpo/query pela PK.
     */
    protected function resolveId(Request $request)
    {
        $params = $request->route() ? $request->route()->parameters() : [];
        if ($params) {
            return end($params);
        }
        return $request->input($this->keyName());
    }

    protected function notFound(): JsonResponse
    {
        return response()->json(['error' => 'Resource not found'], 404);
    }
}
