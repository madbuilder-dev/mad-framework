# Mad\Rest\ApiResourceController

Base abstrata para APIs REST/CRUD sobre models Eloquent, com
mestre-detalhe (múltiplos detalhes), eager-load, filtros declarativos, validação
por `Model::rules()` e atomicidade via `DB::transaction()`.

Casa com `Route::apiResource` — a subclasse só declara configuração; as actions
`index`/`show`/`store`/`update`/`destroy` vêm da base.

## Uso mínimo

```php
// app/Http/Controllers/Api/OrderApiController.php
namespace App\Http\Controllers\Api;

use App\Models\Order;
use Mad\Rest\ApiResourceController;

class OrderApiController extends ApiResourceController
{
    protected string $model      = Order::class;
    protected array  $searchable = ['status', 'customer_id'];
    protected array  $sortable   = ['created_at', 'total'];
    protected array  $with       = ['customer'];
    protected array  $details    = ['items', 'payments'];   // hasMany no model
    protected ?string $defaultOrder = 'created_at desc';
}
```

O controller fica em `app/Http/Controllers/` (PSR-4 `App\`), nunca em
`app/control/`: lá o autoload é `App\Control\` e só guarda telas — uma classe
de namespace global nessa pasta não carrega.

```php
// routes/api.php — o arquivo JÁ ganha o prefixo /api e não tem sessão nem
// CSRF. Não repita ->prefix('api') aqui (a rota viraria /api/api/orders).
use App\Http\Controllers\Api\OrderApiController;

Route::middleware('mad.api')->apiResource('orders', OrderApiController::class);
```

`mad.api` autentica pelo token: `Authorization: Bearer mad_api_…` (emita com
`php artisan mad:api-token {login} {unit_id}` ou na tela **Tokens de API**). Para
restringir por permissão do token, declare-a na rota:

```php
Route::middleware('mad.api:orders.read')->apiResource('orders', OrderApiController::class)->only(['index', 'show']);
Route::middleware('mad.api:orders.write')->apiResource('orders', OrderApiController::class)->except(['index', 'show']);
```

Só para chamadas feitas pelas próprias telas do app (no navegador, com a
sessão de quem está logado e o token CSRF), registre em `routes/web.php` /
`routes/modules/*.php`, que não têm prefixo: aí sim
`Route::middleware('mad.auth')->prefix('api')->group(...)` — é o caso de
`App\Http\Controllers\Sys\ImportTemplateApiController`.

O model precisa declarar as relações `hasMany` usadas em `$details` e,
opcionalmente, `public static function rules($id = null): array`.

## Propriedades de configuração

| Propriedade | Tipo | Default | Função |
|---|---|---|---|
| `$model` | `string` | — (obrigatório) | FQCN do model Eloquent. |
| `$primaryKey` | `?string` | `null` | PK do recurso; `null` → deriva de `getKeyName()`. |
| `$searchable` | `array` | `[]` | Whitelist de colunas filtráveis (DSL de filtros). |
| `$sortable` | `array` | `[]` | Whitelist de colunas ordenáveis (`sort`). |
| `$with` | `array` | `[]` | Eager-load default. Os `$details` entram junto (sem N+1). |
| `$withCount` | `array` | `[]` | `withCount` default. |
| `$appends` | `array` | `[]` | Accessors computados a incluir (`Model::append`). |
| `$indexFields` | `array` | `[]` | Projeção da listagem. Vazio = todos menos `$hidden`. |
| `$showFields` | `array` | `[]` | Projeção do show/respostas de write. |
| `$hidden` | `array` | `[]` | Campos sempre removidos da resposta. |
| `$defaultOrder` | `?string` | `null` | Ordem default `"coluna asc\|desc"` quando não vem `sort`. |
| `$perPage` | `int` | `15` | Itens por página default. |
| `$maxPerPage` | `int` | `200` | Teto de `per_page` (defesa). |
| `$details` | `array` | `[]` | Relações `hasMany` tratadas como detalhes. Múltiplas. |
| `$transformers` | `array` | `[]` | `campo => fn($value, $model)`. Ver `addTransformer()`. |

### Projeção e alias (`$indexFields` / `$showFields`)

Vazio → `toArray()` (todos menos `$hidden`, mais `$appends` e detalhes carregados).
Com lista, suporta dot-notation de relações e alias por chave:

```php
protected array $indexFields = [
    'id',
    'total',
    'cliente' => 'customer.name',  // alias → chave 'cliente'
    'customer.email',              // achata → chave 'customer_email'
];
```

### Transformers

```php
$controller->addTransformer('price', fn ($v, $model) => 'R$ ' . number_format($v, 2, ',', '.'));
```

## DSL de filtros

Request: `filters = { coluna: { operador: valor } }`. Só colunas em `$searchable`
passam; valores vazios são ignorados (exceto `0`/`'0'` e os operadores de
presença/range).

`filters` vale em três formas, com o mesmo resultado:

```bash
# texto JSON na query string
curl -G https://app/api/orders -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'filters={"status":{"eq":"open"},"total":{"gte":100}}'

# forma de array (use os nomes dos operadores: o `=` de `[=]` quebra no --data-urlencode)
curl -G https://app/api/orders -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'filters[status][eq]=open' --data-urlencode 'filters[total][gte]=100'

# corpo JSON: { "filters": { "status": { "eq": "open" } } }
```

Texto que não é um objeto JSON (JSON quebrado, lista, número) → `422` com o erro em
`errors.filters`. Coluna fora de `$searchable`, coluna sem operador e operador
desconhecido NÃO filtram — e voltam listados em `meta.ignored_filters`
(`["senha", "status[==]"]`), para quem integra perceber que o filtro não valeu.

| Operador (aliases) | SQL gerado |
|---|---|
| `=`, `eq` | `coluna = ?` |
| `!=`, `ne`, `not` | `coluna != ?` |
| `>`, `gt` / `>=`, `gte` | `coluna > ?` / `coluna >= ?` |
| `<`, `lt` / `<=`, `lte` | `coluna < ?` / `coluna <= ?` |
| `like` | `coluna LIKE %v%` |
| `like_start` / `like_end` | `coluna LIKE v%` / `%v` |
| `ilike` / `ilike_start` / `ilike_end` | `lower(coluna) LIKE lower(...)` (portável) |
| `in` / `not in`, `not_in` | `whereIn` / `whereNotIn` |
| `between` | `whereBetween` (valor = `[min, max]`) |
| `is null`, `is_null` | `whereNull` (se valor truthy) |
| `is not null`, `is_not_null` | `whereNotNull` (se valor truthy) |

```json
{ "filters": { "status": { "in": ["open", "paid"] }, "total": { "gte": 100 } } }
```

## Request / Response

### Listagem — `GET /orders`

Params (query ou body JSON): `filters`, `sort`, `direction` (`asc`/`desc`),
`page`, `per_page`.

```json
{
  "data": [ { "id": 1, "total": 99.5, "items": [ /* eager-loaded */ ] } ],
  "meta": { "total": 1, "per_page": 15, "current_page": 1, "last_page": 1 }
}
```

`meta.ignored_filters` só aparece quando algum filtro não foi aplicado.

### Show — `GET /orders/{id}`

Corpo = o item (projeção `$showFields` + `$appends` + detalhes). `404` →
`{ "error": "Resource not found" }`.

### Create / Update — `POST /orders` (201) · `PUT|PATCH /orders/{id}`

Payload master + detalhes (cada chave de `$details` é um array de linhas):

```json
{
  "total": 99.5,
  "status": "open",
  "items":    [ { "sku": "A", "qty": 2 }, { "id": 10, "sku": "B", "qty": 5 } ],
  "payments": [ { "method": "pix", "amount": 99.5 } ]
}
```

Sync de detalhes: linha **sem PK** → cria; **com PK existente sob o master** →
atualiza; **ausente do payload** → apaga (diff). A FK vem da relação (ignora FK
no payload). Resposta = o master recarregado com eager-load.

Validação falha → `422` (Laravel `ValidationException`, corpo `{message, errors}`),
com as mensagens no idioma do app (`APP_LOCALE`; arquivos `lang/<idioma>/validation.php`).

### Delete — `DELETE /orders/{id}`

Apaga master + detalhes (cascata pelas relações de `$details`), em transação.
`{ "message": "Resource deleted successfully" }` ou `404`.

## Hooks (override opcional)

| Hook | Quando |
|---|---|
| `authorize(string $action, Request $r): void` | Início de cada action (`index`/`show`/`store`/`update`/`destroy`). Lance p/ negar. |
| `beforeSave(Model $o, array $data)` / `afterSave(...)` | Antes/depois do `save()` do master. |
| `beforeSaveDetail(Model $master, Model $detail, string $relation, array $data)` / `afterSaveDetail(...)` | Por linha de detalhe, no sync. |
| `beforeDelete(Model $o)` | Antes do delete do master. |

```php
protected function authorize(string $action, Request $request): void
{
    if (! $request->user()?->can($action, $this->model)) {
        abort(403);
    }
}
```

## Gotchas

- **Race do idPolicy `max`.** Models com `$idPolicy = 'max'` calculam `MAX(pk)+1`
  no evento `creating` — há janela de corrida. Duas requests que se sobrepõem
  computam o mesmo id; o constraint de PK rejeita o perdedor (`QueryException`) e
  a `DB::transaction()` faz rollback. Nunca corrompe em silêncio, mas um request
  pode falhar sob alta concorrência. Para zero-corrida use `$idPolicy = 'serial'`
  (auto-increment) ou `uuid`/`ulid` no model.

- **Soft-delete no sync.** Se o detail model configura `$deletedAtColumn`
  (`HasMadSoftDeletes`), o "apaga ausentes" do sync faz **soft-delete**
  (`deleted_at`), não `DELETE` físico — a linha some do conjunto ativo mas
  persiste e aparece em `withTrashed()`.

- **FK do payload é ignorada.** No sync, `detail.{fk} = master.getKey()` sempre
  sobrescreve qualquer FK vinda no payload; e um `id` de detalhe que pertence a
  outro master não é "sequestrado" (a relação é escopada pelo master corrente).

- **Chave do corpo que não é gravada volta em `ignored`.** O `fill()` descarta
  em silêncio o que está fora do `$fillable` (nome antigo de uma coluna
  renomeada, erro de digitação). O store/update não recusa — integração que
  manda campo a mais continua funcionando —, mas a resposta lista essas chaves:
  `"ignored": ["ordem_antiga"]` no registro e em cada linha de detalhe
  (`"items": [{"id": 7, …, "ignored": ["qtd"]}]`). Não entram a PK, a FK do
  detalhe nem a chave que um hook gravou por conta própria; sem nada ignorado
  a resposta sai como antes. Hook que consome uma chave sem coluna (ex.:
  `senha` → hash em `password`) sobrescreve `ignoredKeys()` para tirá-la da lista.

- **Validação antes de escrever.** Master e todas as linhas de detalhe são
  validados (via `Model::rules()`) antes de qualquer `save()` — falha não deixa
  meia-gravação.

- **`resolveId()` usa o último parâmetro da rota** (`end($params)`), então
  independe do nome gerado pelo `apiResource` (`{order}`), e cai pro
  body/query pela PK quando não há rota (uso direto em testes).

- **Conexão da transação.** A `DB::transaction()` abre na conexão do model
  (`getConnectionName()`); os `save()` do Eloquent participam da mesma transação
  (mesma conexão).

## Ver também

`Mad\Service\MadRecordService` (`src/mad/service/MadRecordService.php`) é a
**alternativa fina** — serviço AJAX de Active Record (legado), despachado por
verbo HTTP em `handle()` (`load`/`store`/`delete`/`loadAll`/`deleteAll`/`countAll`),
configurado por consts `ACTIVE_RECORD`/`DATABASE`/`ATTRIBUTES`. Sem mestre-detalhe,
validação, projeção ou paginação-meta.

Qual usar:

| Você precisa de… | Use |
|---|---|
| Recurso REST completo (`Route::apiResource`), **mestre-detalhe** (múltiplos), sync de detalhes, validação por `rules()`, filtros DSL, paginação `meta`, projeção/`$appends`, hooks | **`ApiResourceController`** (este) |
| Endpoint **AJAX simples** de um Active Record (CRUD raso + `loadAll` com filtros), estilo legado, exposto por nome de classe | **`MadRecordService`** |

Regra prática: tem detalhe/relação no payload ou expõe um recurso HTTP REST de
verdade → `ApiResourceController`. É só ler/gravar um registro plano via AJAX →
`MadRecordService`.

## Testes

`tests/Feature/ApiResourceControllerTest.php` — CRUD, múltiplos detalhes, sync
(create/update/delete), filtros, paginação, eager-load (sem N+1), validação/422,
e as bordas: race do `max`, FK forjada, soft-delete no sync.
