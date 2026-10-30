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

A chave para outro cadastro (`paciente_id`, `profissional_id`…) é conferida contra a
empresa e a unidade do **token**: id de outra empresa, de outra unidade ou que não
existe → `422` no mesmo formato, com o erro no campo da chave
(`errors.paciente_id`), e nada é gravado. Vale também para `unit_id` explícito no
corpo (só a unidade do token) e para a rota escrita à mão que grava com
`create()`/`save()`. A chave que não muda numa edição não é conferida.

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

## Login pela API

Além do token manual (tela **Tokens de API** / `mad:api-token`), o app externo
(SPA, celular, outro sistema em nome de uma pessoa) entra com **usuário e
senha** e recebe um par de tokens. As rotas são do framework — não declare
`/api/auth/*` no `routes/api.php` (a rota do projeto no mesmo endereço vence e
desliga a do framework).

| Rota | Corpo | Resposta |
|---|---|---|
| `POST /api/auth/login` | `{login, password, unit_id?, tenant_id?, device_name?, scope?}` | 200 com o token, ou erro |
| `POST /api/auth/login/mfa` | `{mfa_token, code}` | 200 com o token |
| `POST /api/auth/login/mfa/resend` | `{mfa_token}` | 202 (só 2FA por e-mail) |
| `POST /api/auth/refresh` | `{refresh_token, unit_id?}` | 200 com um par **novo** |
| `POST /api/auth/logout` | Bearer e/ou `{refresh_token}` | sempre 200 |
| `POST /api/auth/logout-all` | Bearer do login | 200: derruba todas as sessões de login do usuário |
| `GET /api/auth/me` | Bearer | usuário, unidade, empresa, unidades, scope e sessão |

### Antes de tudo: liberar a API num grupo

O login pela API nasce **fechado**. Enquanto nenhum grupo tiver permissão de
API, `POST /api/auth/login` responde `403 api_login_disabled` sem nem olhar a
senha. Marque o que o grupo alcança em **Administração › Grupos › aba API** (as
permissões listadas são as que as rotas declaram em `mad.api:<ability>`), ou:

```bash
php artisan mad:api-abilities Standard --set=os.read,clientes.read
php artisan mad:api-abilities Standard --set='*'      # todas as rotas da API
php artisan mad:api-abilities Standard --clear        # sem acesso pela API
php artisan mad:api-abilities Integração --all-users=on   # vale para todos os usuários
```

**Vale para todos os usuários.** Para o login pela API funcionar sem colocar
cada usuário num grupo, marque as rotas na aba API de um grupo e ligue **Vale
para todos os usuários** (ou `--all-users=on`). Todo usuário ativo que entrar
pela API recebe aquelas rotas, mesmo sem estar no grupo; as telas do grupo
continuam só para quem está nele. Nada muda no resto do login: senha, duas
etapas, unidade, horário de acesso, manutenção e termos valem igual, e o plano
da unidade (com licenciamento) também corta o grupo. Desligar a opção corta o
acesso de quem não é do grupo no request seguinte. Pede o framework 5.219.0 ou
mais novo e a migration da coluna (`php artisan migrate`); sem ela a opção não
aparece e o grupo vale só para quem está nele.

**Pelo Studio.** No MadBuilder, o dev marca as permissões de API de cada grupo
em **Grupos de permissão**, e elas chegam ao app quando ele publica. A
publicação só grava num grupo cuja aba API ainda está como o Studio deixou da
última vez (nenhuma marcada vale o mesmo que lista vazia). Se o admin mudou a
aba API daquele grupo no próprio app, o Studio não sobrescreve o grupo: vale o
que o admin deixou. Desmarcar tudo no app corta o acesso, e a publicação não
devolve. Receber as permissões do Studio pede o framework 5.218.0 ou mais novo;
app em versão anterior não recebe nada pela publicação.

O token do login vale o que os **grupos de agora** dão: tirar a pessoa do grupo
(ou desmarcar a permissão) corta o acesso no request seguinte. Uma permissão
nova no grupo chega ao token na próxima renovação. Rota `mad.api` **sem**
ability declarada só aceita o token do login com `*` — declare a ability nas
rotas que o app externo usa. O token manual continua como sempre (abilities
NULL = acesso total).

### Fluxo completo

```bash
# 1. login
curl -s -X POST https://app.exemplo.com/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"login":"maria","password":"…","device_name":"App do celular","scope":"os.read"}'
# {"token_type":"Bearer","access_token":"mad_api_…","expires_in":3600,
#  "refresh_token":"mad_rt_…","refresh_expires_in":2592000,"scope":"os.read",
#  "session_id":"…","user":{…},"unit":{"id":1,"name":"Matriz"},"tenant":{…}}

# 2. usar
curl -s https://app.exemplo.com/api/os -H 'Authorization: Bearer mad_api_…'

# 3. renovar (o refresh usado morre; guarde o par novo)
curl -s -X POST https://app.exemplo.com/api/auth/refresh \
  -H 'Content-Type: application/json' -d '{"refresh_token":"mad_rt_…"}'

# 4. sair
curl -s -X POST https://app.exemplo.com/api/auth/logout \
  -H 'Authorization: Bearer mad_api_…' \
  -H 'Content-Type: application/json' -d '{"refresh_token":"mad_rt_…"}'
```

Com verificação em duas etapas, o login responde `403 mfa_required` com
`{mfa_token, mfa_type, expires_in}`; mande o código do aplicativo autenticador
(ou do e-mail) para `/api/auth/login/mfa` com o `mfa_token` e receba o token.
O `mfa_token` vale 5 minutos e 5 códigos.

### Copilot pelo app

O `access_token` do login também abre o Copilot e Meus Dashboards
(`/embed/v1/*`, a mesma API do chat do sistema), para usar a IA num app
próprio. Precisa da ability `copilot` (ou `*`) no grupo da pessoa e do programa
Copilot IA (Meus Dashboards, para `/embed/v1/dashboards` e `/embed/v1/widgets`).
O token manual não vale aqui.

```bash
curl -N -X POST https://app.exemplo.com/embed/v1/chat \
  -H 'Authorization: Bearer mad_api_…' -H 'Content-Type: application/json' \
  -d '{"message":"Quantas OS abertas hoje?","conversationId":"app-123"}'
# resposta em SSE (text/event-stream): eventos `data: {…}` e, no fim, `data: [DONE]`

curl -s https://app.exemplo.com/embed/v1/conversations -H 'Authorization: Bearer mad_api_…'
```

Mande sempre o `conversationId` (qualquer texto estável do app): sem ele a
conversa fica presa ao token, e o `access_token` muda a cada renovação. Sem a
ability, a resposta é `403` com `{"code":"insufficient_scope"}`; sem o
programa, `403` com `{"error":"forbidden"}`.

### Erros

`{error, message}` — `error` é o código estável (para o app decidir),
`message` vem traduzido.

| Status | `error` |
|---|---|
| 400 | `invalid_request`, `invalid_unit`, `invalid_scope`, `invalid_grant` |
| 401 | `invalid_credentials` (o mesmo corpo para usuário inexistente, inativo ou senha errada) |
| 403 | `api_login_disabled` (nenhum grupo dá permissão de API — nem com "Vale para todos os usuários" ligado), `api_access_denied`, `password_reset_required`, `password_change_required` (+`reason`), `access_schedule_denied`, `unit_required`, `terms_acceptance_required`, `tenant_db_not_provisioned`, `mfa_required`, `invalid_mfa_code` |
| 429 | `too_many_attempts`, `mfa_locked` (com `Retry-After`) |
| 503 | `maintenance` |

Nas rotas protegidas (`mad.api`), o 401 traz `WWW-Authenticate: Bearer
error="invalid_token"` e a falta de permissão é `403` com
`code: insufficient_scope`.

### Guia do cliente

- **Access token curto (60 min), refresh rotativo (30 dias), teto de 90 dias**
  por sessão. Cada refresh é de **uso único**: reusar um refresh já usado
  derruba a sessão inteira (sinal de token vazado). Renove em **voo único** —
  um refresh por vez, os outros pedidos esperam o resultado; dois refresh
  juntos com o mesmo token derrubam a sessão.
- Guarde o refresh em armazenamento seguro (Keychain/Keystore no celular,
  cookie `HttpOnly` do seu backend numa SPA) — nunca em `localStorage`.
- Trocar a senha encerra todas as sessões de API da pessoa; o app recebe 401
  e volta ao login.
- `unit_id` no refresh troca a unidade da sessão (a pessoa precisa ter acesso
  a ela).
- `MAD_API_AUTH_ENABLED=0` desliga tudo: as rotas saem do ar e os tokens de
  login já emitidos deixam de valer (os manuais seguem).

### Configuração (`config/mad.php` › `api.auth`)

| Chave | Padrão | Env |
|---|---|---|
| `enabled` | `true` | `MAD_API_AUTH_ENABLED` |
| `access_ttl_minutes` | 60 | `MAD_API_AUTH_ACCESS_TTL_MINUTES` |
| `refresh_ttl_days` | 30 | `MAD_API_AUTH_REFRESH_TTL_DAYS` |
| `session_max_days` | 90 | `MAD_API_AUTH_SESSION_MAX_DAYS` |
| `max_sessions_per_user` | 10 | `MAD_API_AUTH_MAX_SESSIONS` |
| `refresh_reuse_grace_seconds` | 0 | `MAD_API_AUTH_REFRESH_GRACE_SECONDS` |
| `mfa_ttl_seconds` | 300 | `MAD_API_AUTH_MFA_TTL_SECONDS` |
| `undeclared_routes` | `deny` | `MAD_API_AUTH_UNDECLARED_ROUTES` |
| `throttle_per_minute` | 10 (refresh/logout: o triplo) | `MAD_API_AUTH_THROTTLE_PER_MINUTE` |

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
