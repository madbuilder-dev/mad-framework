<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ScopedReferences — a chave que um registro com escopo de linha grava tem de
 * apontar para um registro que quem grava ENXERGA (fw#531).
 *
 * Por que existe: o combo de paciente de um app multiempresa só lista os
 * pacientes da empresa de quem está logado (o escopo global do
 * BelongsToTenant), mas o número que chega no Salvar é dado do navegador.
 * Trocando `paciente_id` pelo id de um paciente de outra empresa, a consulta
 * gravava com `tenant_id` da empresa 1 apontando para o paciente da empresa 3
 * — e as telas que carregam o relacionamento mostravam nome e telefone dele.
 * A regra `exists` do `rules()` gerado confere isso, mas só quando a tela
 * chama `validate()` com ela; o serviço que grava `getData()` direto, a API,
 * o código escrito à mão não passavam por regra nenhuma.
 *
 * Aqui a conferência é do MODEL, no `saving` dos que têm escopo de linha
 * (BelongsToTenant / BelongsToUnit) — vale para todo caminho que grava com
 * Eloquent. Cada chave que MUDA e aponta para:
 *
 *   - outro model com escopo de linha: o registro tem de existir na empresa
 *     DO REGISTRO gravado (`tenant_id`) e, se o alvo é por unidade, numa das
 *     unidades em que o usuário pode gravar ({@see UnitContext::allowedIds()}:
 *     as dele e a ativa — a mesma lista do UnitScopeViolation);
 *   - a unidade do IAM (`unidade_destino_id` → Unit): a unidade tem de ser da
 *     empresa do registro. Transferir para outra filial da MESMA empresa é
 *     negócio do app; para a filial de outra empresa, não;
 *   - a empresa do IAM (`empresa_id` → Tenant): tem de ser a do registro;
 *   - o usuário do IAM (`usuario_id` → User, o "responsável" de um combo):
 *     ele tem de ter acesso a alguma unidade da empresa do registro. As
 *     colunas que o próprio framework carimba com a sessão (`created_by`,
 *     `updated_by_user_id`...) ficam de fora.
 *
 * A consulta é a do model alvo, com os escopos globais dele (exclusão lógica,
 * visibilidade), trocando só os de empresa/unidade pela regra acima — um por
 * (model, chave, escopo) em cada pedido ({@see DataScope::memo()}).
 *
 * O que NÃO é conferido, de propósito:
 *   - a chave que não mudou: o registro pode apontar, de antes, para um
 *     cadastro que hoje está fora (inativo, de outra unidade) — salvar sem
 *     mexer nele não pode ser recusado;
 *   - `null`/vazio;
 *   - sem contexto que diga o que o usuário enxerga: comando, agendador, fila,
 *     ou código que fixou o contexto com `TenantContext::set()` /
 *     `UnitContext::set()` — quem fixou responde por ele (a mesma regra do
 *     UnitScopeViolation);
 *   - na visão de todas as empresas do administrador do dono
 *     ({@see AdminScope::readsAll()}) a unidade não restringe, e a empresa
 *     conferida é a do REGISTRO (ele edita registros de outras empresas, sem
 *     misturá-las);
 *   - colunas que o model declara em `$madReferencesOutsideScope` (a chave
 *     que aponta de propósito para fora do escopo).
 *
 * De onde vem "coluna → model": as relações `belongsTo` públicas, sem
 * parâmetros e com o tipo de retorno, que o gerador de models emite para toda
 * chave estrangeira (física ou lógica) — funciona em app já gerado, sem
 * regerar. Metadado de classe: lido uma vez por processo, como o
 * {@see ScopedModels}.
 *
 * Código que precisa gravar uma chave fora do escopo DE PROPÓSITO (rotina de
 * consolidação entre empresas, importação feita pelo dono):
 *
 *   ScopedReferences::without(fn () => $consulta->save());
 */
final class ScopedReferences
{
    /** Bucket do memo por pedido. */
    private const MEMO = 'mad_scoped_ref';

    /** Model alvo com escopo de linha / unidade do IAM / empresa do IAM. */
    private const ROW    = 'row';
    private const UNIT   = 'unit';
    private const TENANT = 'tenant';
    private const USER   = 'user';

    /** Colunas de auditoria (HasMadAudit): o framework as carimba com a sessão, não a tela. */
    private const AUDIT = [
        'created_by', 'updated_by', 'deleted_by',
        'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id', 'created_by_unit_id',
    ];

    /** @var array<class-string<Model>, array<string, array{target: class-string<Model>, owner: string, kind: string, label: string}>> */
    private static array $map = [];

    /** Profundidade de without() em curso (aninhável). */
    private static int $off = 0;

    /**
     * Chaves postas pelo framework na linha filha (a do registro pai que a
     * tela está gravando): registro => [coluna => valor]. Referência fraca: o
     * worker de longa duração não segura o registro.
     *
     * @var \WeakMap<Model, array<string,string>>|null
     */
    private static ?\WeakMap $trusted = null;

    /**
     * Confere as chaves de `$model` que vão ser gravadas.
     *
     * @internal chamado pelo `saving` de BelongsToTenant/BelongsToUnit
     *
     * @throws ReferenceScopeViolation chave para um registro que quem grava não enxerga
     */
    public static function guard(Model $model): void
    {
        if (self::$off > 0) {
            return;
        }
        $references = self::references($model::class);
        if ($references === []) {
            return;
        }

        $attributes = $model->getAttributes();
        $trusted    = (self::$trusted !== null && isset(self::$trusted[$model])) ? self::$trusted[$model] : [];
        foreach ($references as $column => $reference) {
            if (! array_key_exists($column, $attributes) || ($model->exists && ! $model->isDirty($column))) {
                continue;
            }
            $value = $attributes[$column];
            if ($value === null || $value === '' || is_bool($value) || ! is_scalar($value)) {
                continue;
            }
            if (isset($trusted[$column]) && $trusted[$column] === (string) $value) {
                continue;   // a chave do pai, posta pelo framework
            }
            if (! self::visible($model, $reference, $value)) {
                throw new ReferenceScopeViolation($column, is_int($value) ? $value : (string) $value, $reference['label']);
            }
        }
    }

    /**
     * Roda `$fn` sem a conferência — para o código que grava, de propósito,
     * uma chave fora do escopo de quem está logado.
     *
     * @template T
     * @param  callable(): T $fn
     * @return T
     */
    public static function without(callable $fn): mixed
    {
        self::$off++;
        try {
            return $fn();
        } finally {
            self::$off--;
        }
    }

    /**
     * A linha filha `$child` aponta para `$parent` porque o framework pôs a
     * chave (Lista de itens, Detail Form, Upload em tabela, API, ligação N:N):
     * essa chave não é conferida enquanto continuar apontando para ele. Quem
     * edita o registro pai grava as linhas dele — inclusive o pai de uma
     * unidade que hoje não é mais de quem salva (fw#480).
     *
     * @internal chamado por ParentUnit::fill()
     */
    public static function trustParent(Model $child, Model $parent): void
    {
        $key = $parent->getKey();
        if ($key === null || $key === '' || ! is_scalar($key)) {
            return;
        }
        foreach (self::references($child::class) as $column => $reference) {
            $value = $child->getAttributes()[$column] ?? null;
            if (is_a($parent, $reference['target']) && is_scalar($value) && (string) $value === (string) $key) {
                self::trustKey($child, $column, $key);
            }
        }
    }

    /**
     * A chave `$column` de `$child` foi posta pelo framework com `$value` (o
     * registro pai): não é conferida enquanto tiver esse valor.
     *
     * @internal chamado por ParentUnit::fillByKey()
     */
    public static function trustKey(Model $child, string $column, int|float|string $value): void
    {
        self::$trusted ??= new \WeakMap();
        $list          = self::$trusted[$child] ?? [];
        $list[$column] = (string) $value;
        self::$trusted[$child] = $list;
    }

    /**
     * As chaves de `$class` que apontam para registros com escopo: coluna =>
     * alvo (model, coluna-chave do alvo, tipo, rótulo para a mensagem).
     *
     * @param  class-string<Model> $class
     * @return array<string, array{target: class-string<Model>, owner: string, kind: string, label: string}>
     */
    public static function references(string $class): array
    {
        return self::$map[$class] ??= self::discover($class);
    }

    /** Esvazia o metadado — testes (junto com Model::clearBootedModels()). */
    public static function flush(): void
    {
        self::$map     = [];
        self::$off     = 0;
        self::$trusted = null;
    }

    /** @return array<string, array{target: class-string<Model>, owner: string, kind: string, label: string}> */
    private static function discover(string $class): array
    {
        try {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                return [];
            }
            /** @var Model $instance */
            $instance = $reflection->newInstance();
        } catch (\Throwable) {
            return [];
        }

        $outside = array_merge(self::outsideScope($reflection), self::auditColumns($instance));
        $found   = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfParameters() > 0
                || ! $type instanceof \ReflectionNamedType
                || ! is_a($type->getName(), BelongsTo::class, true)
                || is_a($type->getName(), MorphTo::class, true)) {
                continue;
            }
            try {
                $relation = $method->invoke($instance);
            } catch (\Throwable) {
                continue;   // relação que só monta com dados: fica de fora
            }
            if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
                continue;
            }
            $column = (string) $relation->getForeignKeyName();
            if ($column === '' || str_contains($column, '.') || isset($found[$column]) || in_array($column, $outside, true)) {
                continue;
            }
            $target = $relation->getRelated()::class;
            $kind   = self::kind($target);
            if ($kind === null || self::ownScope($instance, $column, $kind)) {
                continue;
            }
            $found[$column] = [
                'target' => $target,
                'owner'  => (string) $relation->getOwnerKeyName(),
                'kind'   => $kind,
                'label'  => self::label($column),
            ];
        }

        return $found;
    }

    /** O tipo de alvo que é conferido, ou null (alvo sem escopo: nada a conferir aqui). */
    private static function kind(string $target): ?string
    {
        if (method_exists($target, 'madTenantScoped') || method_exists($target, 'madUnitScoped')) {
            return self::ROW;
        }
        if (is_a($target, 'App\\Models\\Iam\\Unit', true)) {
            return self::UNIT;
        }
        if (is_a($target, 'App\\Models\\Iam\\Tenant', true)) {
            return self::TENANT;
        }
        if (is_a($target, 'App\\Models\\Iam\\User', true)) {
            return self::USER;
        }

        return null;
    }

    /**
     * A coluna de escopo do PRÓPRIO registro (`unit_id` de quem usa
     * BelongsToUnit, `tenant_id` de quem usa BelongsToTenant) tem regra
     * própria: carimbo + UnitScopeViolation/TenantScopeViolation.
     */
    private static function ownScope(Model $instance, string $column, string $kind): bool
    {
        return ($column === 'unit_id' && $kind === self::UNIT && method_exists($instance, 'madUnitScoped'))
            || ($column === 'tenant_id' && $kind === self::TENANT && method_exists($instance, 'madTenantScoped'));
    }

    /** @return list<string> colunas de auditoria do model (as padrão e as que ele renomeou) */
    private static function auditColumns(Model $instance): array
    {
        $columns = self::AUDIT;
        foreach (['getCreatedByColumn', 'getUpdatedByColumn', 'getCreatedByUserIdColumn', 'getUpdatedByUserIdColumn', 'getCreatedByUnitIdColumn'] as $getter) {
            if (method_exists($instance, $getter)) {
                try {
                    $column = $instance->{$getter}();
                } catch (\Throwable) {
                    $column = null;
                }
                if (is_string($column) && $column !== '') {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    /** @return list<string> colunas que o model declara em `$madReferencesOutsideScope` */
    private static function outsideScope(\ReflectionClass $reflection): array
    {
        if (! $reflection->hasProperty('madReferencesOutsideScope')) {
            return [];
        }
        try {
            $property = $reflection->getProperty('madReferencesOutsideScope');
            $value    = $property->isStatic() ? $property->getValue() : $property->getValue($reflection->newInstance());
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }

    /** `unidade_destino_id` → "Unidade destino" (o formulário usa o rótulo do campo). */
    private static function label(string $column): string
    {
        $base = (string) preg_replace('/_id$/i', '', $column);
        $text = trim(str_replace('_', ' ', $base));

        return $text === '' ? $column : ucfirst($text);
    }

    /**
     * A chave aponta para um registro que quem grava enxerga? `true` também
     * quando não há como saber (sem contexto): nada a conferir.
     *
     * @param array{target: class-string<Model>, owner: string, kind: string, label: string} $reference
     */
    private static function visible(Model $model, array $reference, int|float|string $value): bool
    {
        $tenant = self::recordTenant($model);
        $target = $reference['target'];

        if ($reference['kind'] === self::TENANT) {
            return $tenant === null || (is_numeric($value) && (string) (int) $value === (string) $value && (int) $value === $tenant);
        }
        if ($reference['kind'] === self::UNIT) {
            return $tenant === null || self::exists($target, $reference['owner'], $value, $tenant, null);
        }
        if ($reference['kind'] === self::USER) {
            return $tenant === null || self::userInTenant($target, $value, $tenant);
        }

        $byTenant = method_exists($target, 'madTenantScoped') && $target::madTenantScoped() ? $tenant : null;
        $byUnit   = method_exists($target, 'madUnitScoped') && $target::madUnitScoped() && ! AdminScope::readsAll()
            ? UnitContext::allowedIds()
            : null;
        if ($byTenant === null && $byUnit === null) {
            return true;
        }

        return self::exists($target, $reference['owner'], $value, $byTenant, $byUnit);
    }

    /**
     * Empresa do registro gravado: o `tenant_id` dele (o carimbo do
     * BelongsToTenant) ou a empresa ativa. null quando não há contexto de
     * usuário — comando, fila, ou contexto fixado com set().
     */
    private static function recordTenant(Model $model): ?int
    {
        if (TenantContext::overridden() || UnitContext::overridden()) {
            return null;
        }
        $active = TenantContext::id();
        if ($active === null) {
            return null;
        }
        if (method_exists($model, 'madTenantScoped') && $model::madTenantScoped()) {
            $own = $model->getAttributes()['tenant_id'] ?? null;
            if (is_numeric($own)) {
                return (int) $own;
            }
        }

        return $active;
    }

    /**
     * Uma consulta por (model, chave, escopo) no pedido.
     *
     * @param class-string<Model> $target
     * @param list<int>|null      $units
     */
    private static function exists(string $target, string $owner, int|float|string $value, ?int $tenant, ?array $units): bool
    {
        if ($units !== null) {
            sort($units);
        }
        $key = $target . '|' . $owner . '|' . $value . '|t' . ($tenant ?? '-') . '|u' . ($units === null ? '-' : implode(',', $units));

        return (bool) DataScope::memo(self::MEMO, $key, static function () use ($target, $owner, $value, $tenant, $units): bool {
            try {
                /** @var Model $proto */
                $proto = new $target();
                // Chave inteira que nem número é: não existe, e o PostgreSQL
                // recusaria a consulta (o que derrubaria a transação).
                if ($owner === $proto->getKeyName() && in_array($proto->getKeyType(), ['int', 'integer'], true)
                    && ! preg_match('/^-?\d+$/', (string) $value)) {
                    return false;
                }
                $table = $proto->getTable();
                $query = $target::query()->withoutGlobalScopes(['mad_tenant', 'mad_unit']);
                if ($tenant !== null) {
                    $query->where($table . '.tenant_id', $tenant);
                }
                if ($units !== null) {
                    $units === [] ? $query->whereRaw('0 = 1') : $query->whereIn($table . '.unit_id', $units);
                }

                return $query->where($table . '.' . $owner, $value)->exists();
            } catch (\Throwable $e) {
                // Quem não conseguiu conferir não grava: o motivo vai para o log.
                self::log('a chave para ' . $target . ' não pôde ser conferida ao gravar: ' . \Mad\Ui\MadErrorRedactor::describe($e));

                return false;
            }
        });
    }

    /**
     * O usuário `$value` tem acesso a alguma unidade da empresa `$tenant` (um
     * vínculo em `mad_iam_user_unit`, ou a unidade principal do cadastro)?
     *
     * @param class-string<Model> $target
     */
    private static function userInTenant(string $target, int|float|string $value, int $tenant): bool
    {
        if (! preg_match('/^\d+$/', (string) $value)) {
            return false;
        }
        $key = $target . '|user|' . $value . '|t' . $tenant;

        return (bool) DataScope::memo(self::MEMO, $key, static function () use ($target, $value, $tenant): bool {
            try {
                /** @var Model $proto */
                $proto = new $target();
                $db    = $proto->getConnection();
                $units = class_exists('App\\Models\\Iam\\Unit') ? (new \App\Models\Iam\Unit())->getTable() : 'mad_iam_unit';
                $links = class_exists('App\\Models\\Iam\\UserUnit') ? (new \App\Models\Iam\UserUnit())->getTable() : 'mad_iam_user_unit';
                $ofTenant = static fn ($q) => $q->select('id')->from($units)->where('tenant_id', $tenant);

                return $db->table($links)->where('user_id', (int) $value)->whereIn('unit_id', $ofTenant)->exists()
                    || $db->table($proto->getTable())->where($proto->getKeyName(), (int) $value)->whereIn('unit_id', $ofTenant)->exists();
            } catch (\Throwable $e) {
                self::log('o usuário apontado não pôde ser conferido ao gravar: ' . \Mad\Ui\MadErrorRedactor::describe($e));

                return false;
            }
        });
    }

    private static function log(string $message): void
    {
        $message = '[ScopedReferences] ' . $message;
        try {
            function_exists('logger') ? logger()->warning($message) : error_log($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }
}
