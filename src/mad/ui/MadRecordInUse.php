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
    public static function alongMessage(array $along): string
    {
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
     * O que o banco exclui JUNTO com o registro (`on delete cascade`), com a
     * quantidade — para quem exclui saber antes. Registro com lixeira
     * (SoftDeletes) não leva nada: a exclusão só o marca.
     *
     * @return list<array{label:string,count:int}>
     */
    public static function alongWith(Model $record): array
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($record), true)) {
            return [];
        }

        return self::scan($record)[1];
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

    // ── Internos ────────────────────────────────────────────────────────

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
