<?php

namespace Mad\Ui;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * MadRecordInUse — QUEM usa um registro que não pôde ser excluído.
 *
 * "Não é possível excluir este registro: ele está em uso em outra parte do
 * sistema." não diz a quem usa o app onde olhar. Aqui a recusa vira:
 *
 *   "Não é possível excluir este registro: ele está em uso em Produtos
 *    (3 registros) e Itens de combo (1 registro). Exclua ou altere esses
 *    registros primeiro."
 *
 * De onde vem a lista: das relações `hasMany`/`hasOne` do Model do registro
 * (métodos públicos sem parâmetro com o tipo de retorno declarado — é como o
 * Model gerado as escreve). Cada uma é contada pela própria relação, com os
 * escopos do Model filho. Sem relação que explique, vale a tabela que o banco
 * cita na recusa da chave estrangeira (PostgreSQL e MySQL), sem a quantidade.
 *
 * Filha que a chave estrangeira do banco exclui JUNTO (`on delete cascade`:
 * etapas da obra, itens do contrato) não usa o registro: vai com ele. Ela entra
 * em {@see alongWith()}, e quem a usa (as atividades daquelas etapas) é que
 * entra na lista — até {@see MAX_DEPTH} níveis. Filha que fica sem o vínculo
 * (`on delete set null`) não conta em nenhuma das duas.
 *
 * Registro com lixeira (SoftDeletes, HasMadSoftDeletes com a coluna): a
 * exclusão só o marca, e o banco não leva nada. Vai junto para a lixeira a
 * filha `on delete cascade` que TAMBÉM tem lixeira (e as dela, até
 * {@see MAX_DEPTH} níveis), sem contar as que já estão lá — {@see alongWith()}
 * conta, {@see trashAlong()} marca (fw#643). Filha sem lixeira fica onde está
 * (fórum #274): marcar o pai não a apaga. O par: restaurar o pai traz de volta
 * as filhas que foram junto NA MESMA exclusão (mesma marca: `deleted_at` e,
 * quando os dois têm, `deleted_by`) — {@see restoreAlong()}, chamado pelo
 * `restore()` do HasMadSoftDeletes e pelos listeners de {@see listen()}.
 *
 * O NOME mostrado é o do Model filho: a constante `MAD_LABEL`, quando ele a
 * declara, senão o nome da classe por extenso (`ItemPedido` → "Item pedido").
 *
 *     class Produto extends Model
 *     {
 *         public const MAD_LABEL = 'Produtos';
 *     }
 *
 * Quando vale ({@see matches()}): recusa de chave estrangeira do banco ao
 * excluir e o guard que o Model gerado lança no `deleting`. Outra recusa de
 * domínio ("Pedido faturado não pode ser excluído") não é tocada.
 *
 * Em tela própria:
 *
 *     } catch (\Throwable $e) {
 *         return MadToast::danger(MadRecordInUse::explain($e, $registro) ?? MadUserError::message($e));
 *     }
 *
 * Nenhuma frase traz SQL, valor, nome físico de tabela ou de conexão.
 */
final class MadRecordInUse
{
    /** Trecho fixo da frase que o Model gerado lança no `deleting` quando há filhos. */
    private const GENERATED_GUARD = 'ele está em uso em outra parte do sistema';

    /** Tabelas citadas por extenso; o resto vira "mais N lugares". */
    private const MAX_LISTED = 3;

    /** Níveis olhados: as filhas, as filhas das que vão junto, e mais um. */
    private const MAX_DEPTH = 3;

    /** `on_delete` das chaves estrangeiras lidas na chamada, por conexão e tabela filha. */
    private static array $foreignKeys = [];

    /** Profundidade do {@see restoreAlong()} em curso (o cache de FKs se limpa ao sair do último). */
    private static int $restoreDepth = 0;

    /** @var \WeakMap<Model, array{at:\Carbon\CarbonInterface,by:array<string,mixed>}>|null marca lida no `restoring` */
    private static ?\WeakMap $restoring = null;

    /**
     * A frase com quem usa o registro, ou null quando o erro não é "registro em
     * uso" ou quando nada diz quem o usa (quem chama mostra o aviso de antes).
     */
    public static function explain(\Throwable $e, ?Model $record): ?string
    {
        try {
            if ($record === null || ! self::matches($e)) {
                return null;
            }

            return self::message($record, $e);
        } catch (\Throwable) {
            return null;
        }
    }

    /** O erro é a recusa de excluir um registro que outro usa? */
    public static function matches(\Throwable $e): bool
    {
        $kind = MadDbErrorMessage::classify($e)['kind'] ?? null;
        if ($kind !== null) {
            return $kind === MadDbErrorMessage::IN_USE;
        }

        return ! MadUserError::isTechnical($e) && str_contains($e->getMessage(), self::GENERATED_GUARD);
    }

    /**
     * A frase pronta para o registro, ou null quando ninguém o usa.
     *
     * @param \Throwable|null $e a recusa do banco, para a tabela que ela cita
     */
    public static function message(Model $record, ?\Throwable $e = null): ?string
    {
        $usages = self::usages($record, $e);
        if ($usages === []) {
            return null;
        }

        return self::text(
            'in_use',
            'Não é possível excluir este registro: ele está em uso em :where. Exclua ou altere esses registros primeiro.',
            ['where' => self::where($usages)]
        );
    }

    /**
     * A pergunta antes de excluir o registro junto com o que pertence a ele.
     *
     * @param list<array{label:string,count:int|null}> $along {@see alongWith()}
     */
    public static function alongMessage(array $along, bool $trash = false): string
    {
        // Lixeira (fw#643): vai junto para a lixeira, e dá para desfazer —
        // "Não dá para desfazer" só na exclusão de verdade.
        if ($trash) {
            return self::text('delete_along_trash', 'Vai junto para a lixeira: :where.', ['where' => self::where($along)]);
        }

        return self::text(
            'delete_along',
            'Junto com este registro também serão excluídos: :where. Não dá para desfazer.',
            ['where' => self::where($along)]
        );
    }

    /**
     * Quem usa o registro, na ordem em que o Model declara as relações.
     *
     * @return list<array{label:string,count:int|null}> `count` null = o banco
     *         citou a tabela, mas não há relação para contar
     */
    public static function usages(Model $record, ?\Throwable $e = null): array
    {
        $usages = self::scan($record)[0];
        if ($usages !== [] || $e === null) {
            return $usages;
        }

        $table = self::tableFrom($e);

        return $table !== null ? [['label' => self::humanize($table), 'count' => null]] : [];
    }

    /**
     * O que vai JUNTO com o registro, com a quantidade — para quem exclui saber
     * antes. Exclusão de verdade: o que o banco exclui junto (`on delete
     * cascade`). Registro com lixeira (SoftDeletes, ou HasMadSoftDeletes com a
     * coluna configurada): as filhas `on delete cascade` que também têm
     * lixeira, sem as que já estão nela — vão para a lixeira com ele
     * ({@see trashAlong()}, fw#643). Filha sem lixeira fica como está
     * (fórum #274) e não entra.
     *
     * @return list<array{label:string,count:int}>
     */
    public static function alongWith(Model $record): array
    {
        if (self::goesToTrash($record)) {
            $byLabel = [];
            foreach (self::trashTargets($record) as [$label, $query]) {
                try {
                    $count = (int) (clone $query)->count();
                } catch (\Throwable) {
                    continue;
                }
                if ($count > 0) {
                    $byLabel[$label] = ($byLabel[$label] ?? 0) + $count;
                }
            }
            $out = [];
            foreach ($byLabel as $label => $count) {
                $out[] = ['label' => (string) $label, 'count' => $count];
            }

            return $out;
        }

        return self::scan($record)[1];
    }

    /**
     * Manda para a lixeira o que {@see alongWith()} contou para um registro com
     * lixeira: as filhas `on delete cascade` que também têm lixeira, da mais
     * funda para cima, uma a uma pelo Model (eventos e "excluído por" valem).
     * Chame depois de marcar o registro, na mesma transação. Registro sem
     * lixeira: nada (o banco leva as filhas).
     *
     * @return int quantas filhas foram para a lixeira
     */
    public static function trashAlong(Model $record): int
    {
        if (! self::goesToTrash($record)) {
            return 0;
        }

        // A filha leva a MESMA marca do pai (o laço pode virar o segundo): é
        // por ela que restaurar o pai sabe o que foi junto ({@see restoreAlong()}).
        $at   = self::trashMark($record)['at'] ?? null;
        $done = 0;
        foreach (array_reverse(self::trashTargets($record)) as [, $query]) {
            foreach ((clone $query)->get() as $child) {
                if ($child instanceof Model && $child->delete() !== false) {
                    $done++;
                    if ($at !== null) {
                        self::stampLike($child, $at);
                    }
                }
            }
        }

        return $done;
    }

    /**
     * Restaurar `$record` traz de volta as filhas que foram junto para a
     * lixeira NA MESMA exclusão (fw#643, o par de {@see trashAlong()}): relação
     * `on delete cascade`, filha com lixeira e com a mesma marca do pai
     * (`deleted_at` no mesmo segundo e, quando os dois têm a coluna,
     * `deleted_by`/`deleted_by_user_id` iguais). A que já estava na lixeira
     * antes fica. Cada filha volta pelo próprio `restore()` — que traz as dela
     * —, do nível mais raso para o mais fundo. `$mark` é a de
     * {@see trashMark()}, lida ANTES do `restore()` do pai apagar a coluna.
     *
     * @internal chamado pelo `restore()` do HasMadSoftDeletes e por {@see onRestored()}
     * @param array{at:\Carbon\CarbonInterface,by:array<string,mixed>}|null $mark
     * @return int quantas filhas voltaram (diretas)
     */
    public static function restoreAlong(Model $record, ?array $mark): int
    {
        if ($mark === null) {
            return 0;
        }

        $done = 0;
        self::$restoreDepth++;
        try {
            foreach (self::relations($record, true) as $relation) {
                try {
                    $child = $relation->getRelated();
                    if (! self::goesToTrash($child) || self::onDelete($relation, $record) !== 'cascade') {
                        continue;
                    }
                    $col  = $child->getDeletedAtColumn();
                    $rows = $relation->getQuery()
                        ->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class, 'madSoftDelete'])
                        ->whereNotNull($child->qualifyColumn($col))
                        ->get();
                } catch (\Throwable) {
                    continue;
                }
                foreach ($rows as $row) {
                    if ($row instanceof Model && self::sameMark(self::trashMark($row), $mark)) {
                        $row->restore();
                        $done++;
                    }
                }
            }
        } finally {
            if (--self::$restoreDepth === 0) {
                self::$foreignKeys = [];
            }
        }

        return $done;
    }

    /**
     * A marca da lixeira de `$model`: quando foi (`deleted_at`) e por quem
     * (`deleted_by`, `deleted_by_user_id` do HasMadSoftDeletes, quando tem).
     * Null: sem lixeira ou fora dela.
     *
     * @internal
     * @return array{at:\Carbon\CarbonInterface,by:array<string,mixed>}|null
     */
    public static function trashMark(Model $model): ?array
    {
        if (! self::goesToTrash($model)) {
            return null;
        }
        $at = self::moment($model->getAttribute($model->getDeletedAtColumn()));
        if ($at === null) {
            return null;
        }
        $by = [];
        foreach (['deleted_by' => 'getDeletedByColumn', 'deleted_by_user_id' => 'getDeletedByUserIdColumn'] as $key => $getter) {
            $col = method_exists($model, $getter) ? $model->{$getter}() : null;
            if (is_string($col) && $col !== '') {
                $by[$key] = $model->getAttribute($col);
            }
        }

        return ['at' => $at, 'by' => $by];
    }

    /** Liga os listeners do restaurar do SoftDeletes do Laravel (MadServiceProvider). */
    public static function listen(\Illuminate\Contracts\Events\Dispatcher $events): void
    {
        $events->listen('eloquent.restoring: *', [self::class, 'onRestoring']);
        $events->listen('eloquent.restored: *', [self::class, 'onRestored']);
    }

    /**
     * @internal Listener de `eloquent.restoring: *` (SoftDeletes do Laravel —
     * o HasMadSoftDeletes cuida disso no próprio `restore()`): guarda a marca
     * antes de o `restore()` apagar a coluna. Não devolve nada (devolver
     * `false` cancelaria o restaurar).
     */
    public static function onRestoring(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || ! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return;
        }
        $mark = self::trashMark($model);
        if ($mark !== null) {
            self::$restoring ??= new \WeakMap();
            self::$restoring[$model] = $mark;
        }
    }

    /** @internal Listener de `eloquent.restored: *`: as filhas da mesma exclusão voltam, numa transação. */
    public static function onRestored(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || self::$restoring === null || ! isset(self::$restoring[$model])) {
            return;
        }
        $mark = self::$restoring[$model];
        unset(self::$restoring[$model]);
        $model->getConnection()->transaction(fn () => self::restoreAlong($model, $mark));
    }

    /** Como a tabela do Model se chama para quem usa o app. */
    public static function label(Model|string $model): string
    {
        $class = is_string($model) ? $model : get_class($model);
        $const = $class . '::MAD_LABEL';
        if (defined($const) && is_string(constant($const)) && trim(constant($const)) !== '') {
            return trim(constant($const));
        }

        return self::humanize(Str::snake(class_basename($class)));
    }

    /**
     * @internal As relações filhas de `$model` (hasMany/hasOne declaradas com o
     * tipo de retorno), como a pergunta "vai junto" as lê. Usado por
     * {@see \Mad\Database\MadRecordFiles} para achar as filhas que o banco
     * apaga junto.
     *
     * @return list<HasOneOrMany>
     */
    public static function childRelations(Model $model, bool $constrained = true): array
    {
        return self::relations($model, $constrained);
    }

    /** @internal A regra `on delete` da FK da relação no banco (`cascade`, `set null`…) ou null. */
    public static function deleteRule(HasOneOrMany $relation, Model $parent): ?string
    {
        return self::onDelete($relation, $parent);
    }

    /** @internal Esquece as FKs lidas (o próximo {@see deleteRule()} lê de novo do banco). */
    public static function forgetForeignKeys(): void
    {
        self::$foreignKeys = [];
    }

    /** @internal A exclusão de `$record` só o marca (lixeira)? */
    public static function isTrashDelete(Model $record): bool
    {
        return self::goesToTrash($record);
    }

    // ── Internos ────────────────────────────────────────────────────────

    /** A exclusão de `$record` só o marca (lixeira), sem tirar a linha do banco? */
    private static function goesToTrash(Model $record): bool
    {
        $traits = class_uses_recursive($record);
        if (in_array(SoftDeletes::class, $traits, true)) {
            return ! $record->isForceDeleting();
        }
        if (in_array(\Mad\Database\Concerns\HasMadSoftDeletes::class, $traits, true)) {
            return $record->getDeletedAtColumn() !== null && ! $record->isForceDeleting();
        }

        return false;
    }

    /** Mesma exclusão: mesmo segundo e, onde os dois têm a coluna, o mesmo autor. */
    private static function sameMark(?array $child, array $parent): bool
    {
        if ($child === null || $child['at']->format('Y-m-d H:i:s') !== $parent['at']->format('Y-m-d H:i:s')) {
            return false;
        }
        foreach (array_intersect_key($child['by'], $parent['by']) as $key => $value) {
            if ((string) $value !== (string) $parent['by'][$key]) {
                return false;
            }
        }

        return true;
    }

    /** Valor da coluna da lixeira (Carbon do cast, ou o texto gravado) → momento; null se vazio. */
    private static function moment(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Grava em `$child` (já na lixeira) a marca de tempo `$at`, se ela caiu noutro segundo. */
    private static function stampLike(Model $child, \Carbon\CarbonInterface $at): void
    {
        $col = $child->getDeletedAtColumn();
        $cur = self::moment($child->getAttribute($col));
        if ($cur !== null && $cur->format('Y-m-d H:i:s') === $at->format('Y-m-d H:i:s')) {
            return;
        }
        $value = $child->fromDateTime($at);
        $child->newQueryWithoutScopes()->whereKey($child->getKey())->update([$col => $value]);
        $child->setAttribute($col, $value);
        $child->syncOriginalAttribute($col);
    }

    /**
     * As filhas que vão junto para a lixeira com `$record` (fw#643): relação
     * `on delete cascade` cujo Model também tem lixeira, e as dela, até
     * {@see MAX_DEPTH} níveis — `[rótulo, consulta]`, de cima para baixo. A
     * consulta usa o escopo da lixeira da filha: as já excluídas ficam de fora.
     *
     * @return list<array{0:string,1:Builder}>
     */
    private static function trashTargets(Model $record): array
    {
        self::$foreignKeys = [];
        $out = [];
        try {
            self::trashWalk($record, null, 1, $out);
        } finally {
            self::$foreignKeys = [];
        }

        return $out;
    }

    /** @param list<array{0:string,1:Builder}> $out */
    private static function trashWalk(Model $model, ?Builder $parents, int $depth, array &$out): void
    {
        foreach (self::relations($model, $parents === null) as $relation) {
            try {
                $child = $relation->getRelated();
                if (! self::goesToTrash($child) || self::onDelete($relation, $model) !== 'cascade') {
                    continue;
                }
                $query = $relation->getQuery();
                if ($parents !== null) {
                    $query->whereIn(
                        $relation->getQualifiedForeignKeyName(),
                        (clone $parents)->select($model->qualifyColumn($relation->getLocalKeyName()))
                    );
                    if ($relation instanceof MorphOneOrMany) {
                        $query->where($relation->getQualifiedMorphType(), $relation->getMorphClass());
                    }
                }
            } catch (\Throwable) {
                continue;
            }
            $out[] = [self::label($child), $query];
            if ($depth < self::MAX_DEPTH) {
                self::trashWalk($child, $query, $depth + 1, $out);
            }
        }
    }

    /**
     * Quem usa o registro e o que vai junto, numa passada.
     *
     * @return array{0:list<array{label:string,count:int}>,1:list<array{label:string,count:int}>}
     */
    private static function scan(Model $record): array
    {
        self::$foreignKeys = [];
        $inUse = $along = [];
        try {
            self::walk($record, null, 1, $inUse, $along);
        } finally {
            self::$foreignKeys = [];
        }

        $list = static function (array $byLabel): array {
            $out = [];
            foreach ($byLabel as $label => $count) {
                $out[] = ['label' => (string) $label, 'count' => $count];
            }

            return $out;
        };

        return [$list($inUse), $list($along)];
    }

    /**
     * As filhas de `$model`: a que o banco exclui junto soma em `$along` e as
     * filhas dela são olhadas no nível de baixo; a que fica sem vínculo não
     * conta; o resto soma em `$inUse`.
     *
     * @param Builder|null $parents as linhas de `$model` no nível de cima (null = `$model` é o registro)
     */
    private static function walk(Model $model, ?Builder $parents, int $depth, array &$inUse, array &$along): void
    {
        foreach (self::relations($model, $parents === null) as $relation) {
            try {
                $rule  = self::onDelete($relation, $model);
                $query = $relation->getQuery();
                if ($parents !== null) {
                    $query->whereIn(
                        $relation->getQualifiedForeignKeyName(),
                        (clone $parents)->select($model->qualifyColumn($relation->getLocalKeyName()))
                    );
                    if ($relation instanceof MorphOneOrMany) {
                        $query->where($relation->getQualifiedMorphType(), $relation->getMorphClass());
                    }
                }
                if ($rule === 'set null' || $rule === 'set default') {
                    continue;
                }
                $count = (int) (clone $query)->count();
            } catch (\Throwable) {
                continue;
            }
            if ($count === 0) {
                continue;
            }
            $label = self::label($relation->getRelated());
            if ($rule !== 'cascade') {
                $inUse[$label] = ($inUse[$label] ?? 0) + $count;
                continue;
            }
            $along[$label] = ($along[$label] ?? 0) + $count;
            if ($depth < self::MAX_DEPTH) {
                self::walk($relation->getRelated(), $query, $depth + 1, $inUse, $along);
            }
        }
    }

    /**
     * As relações filhas de `$model`, na ordem em que o Model as declara.
     * Sem `$constrained` (nível de baixo, Model sem registro), a relação vem
     * sem o filtro do pai: quem chama filtra pelas linhas do nível de cima.
     *
     * @return list<HasOneOrMany>
     */
    private static function relations(Model $model, bool $constrained): array
    {
        $out  = [];
        $seen = [];
        foreach ((new \ReflectionClass($model))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (! self::isChildRelation($method)) {
                continue;
            }
            try {
                $relation = $constrained
                    ? $method->invoke($model)
                    : Relation::noConstraints(fn () => $method->invoke($model));
            } catch (\Throwable) {
                continue;
            }
            if (! $relation instanceof HasOneOrMany) {
                continue;
            }
            // `hasOne` ao lado do `hasMany` da mesma chave (tabela irmã 1:1): um uso só.
            $key = get_class($relation->getRelated()) . '|' . $relation->getForeignKeyName();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $relation;
        }

        return $out;
    }

    /**
     * O que o banco faz com a filha quando o pai é excluído: `cascade`,
     * `set null`, `restrict`, `no action`… null quando a chave estrangeira não
     * existe no banco ou não deu para ler (conta como uso, como antes).
     */
    private static function onDelete(HasOneOrMany $relation, Model $parent): ?string
    {
        $child = $relation->getRelated();
        try {
            $connection = $child->getConnection();
            $cacheKey   = $connection->getName() . '|' . $child->getTable();
            self::$foreignKeys[$cacheKey] ??= $connection->getSchemaBuilder()->getForeignKeys($child->getTable());
        } catch (\Throwable) {
            return null;
        }

        $parentTable = Str::afterLast($parent->getTable(), '.');
        foreach (self::$foreignKeys[$cacheKey] as $fk) {
            if ((array) ($fk['columns'] ?? []) === [$relation->getForeignKeyName()]
                && Str::afterLast((string) ($fk['foreign_table'] ?? ''), '.') === $parentTable) {
                return strtolower((string) ($fk['on_delete'] ?? '')) ?: null;
            }
        }

        return null;
    }

    /**
     * "Produtos (3 registros), Itens (1 registro) e mais 2 lugares".
     *
     * @param list<array{label:string,count:int|null}> $usages
     */
    private static function where(array $usages): string
    {
        $parts = [];
        foreach (array_slice($usages, 0, self::MAX_LISTED) as $use) {
            $parts[] = match (true) {
                $use['count'] === null => $use['label'],
                $use['count'] === 1    => self::text('in_use_one', ':label (1 registro)', ['label' => $use['label']]),
                default                => self::text('in_use_many', ':label (:count registros)', ['label' => $use['label'], 'count' => $use['count']]),
            };
        }
        $more = count($usages) - count($parts);
        if ($more > 0) {
            $parts[] = self::text('in_use_more', 'mais :count lugares', ['count' => $more]);
        }

        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts) . self::text('in_use_and', ' e ') . $last;
    }

    /**
     * Método público, sem parâmetro, que DECLARA devolver `HasMany`/`HasOne`
     * (ou os polimórficos). Método sem tipo não é chamado: pode ser qualquer
     * coisa do app.
     */
    private static function isChildRelation(\ReflectionMethod $method): bool
    {
        if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfParameters() > 0) {
            return false;
        }
        $type = $method->getReturnType();

        return $type instanceof \ReflectionNamedType
            && ! $type->isBuiltin()
            && is_a($type->getName(), HasOneOrMany::class, true);
    }

    /** Tabela filha citada na recusa da chave estrangeira (PostgreSQL, MySQL). */
    private static function tableFrom(\Throwable $e): ?string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $message = $x->getMessage();
            foreach ([
                '/is still referenced from table "([^"]+)"/i',
                '/violates foreign key constraint "[^"]*" on table "([^"]+)"/i',
                '/foreign key constraint fails \(`[^`]*`\.`([^`]+)`/i',
            ] as $pattern) {
                if (preg_match($pattern, $message, $m) && preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $m[1])) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /** `item_pedido` → "Item pedido". */
    private static function humanize(string $name): string
    {
        return Str::ucfirst(trim((string) preg_replace('/[\s_]+/', ' ', $name)));
    }

    /**
     * Texto do catálogo do pacote (`mad.error.<chave>`), com reserva embutida.
     *
     * @param array<string,string|int> $replace
     */
    private static function text(string $key, string $fallback, array $replace = []): string
    {
        $full  = 'mad.error.' . $key;
        $value = $fallback;
        try {
            $found = mad_t($full);
            // Chave ausente no catálogo o mad_t devolve a própria chave.
            if ($found !== '' && $found !== $full) {
                $value = $found;
            }
        } catch (\Throwable) {
            // sem catálogo (teste de unidade, boot incompleto): fica a reserva
        }
        foreach ($replace as $name => $text) {
            $value = str_replace(':' . $name, (string) $text, $value);
        }

        return $value;
    }
}
