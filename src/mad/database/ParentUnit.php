<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Throwable;

/**
 * ParentUnit — a linha filha vai para a unidade do REGISTRO PAI, não para a
 * unidade ATIVA de quem grava (fw#480, fw#483).
 *
 * Num app multiunidade, o líder que tem a filial na lista de unidades (ativa:
 * Matriz) edita o chamado da filial: o chamado continua na filial (o carimbo
 * do BelongsToUnit preserva a unidade da linha), mas a linha filha nova — o
 * item da Lista de itens, a linha do Detail Form, o anexo do Upload em
 * tabela, o comentário — recebia a ativa, e a filial deixava de vê-la.
 *
 * Regra, a mesma em todo caminho que grava filho:
 *   - só quando o Model da linha e o do pai são escopados por unidade
 *     (BelongsToUnit com a flag ligada);
 *   - só quando a linha ainda não tem unidade (nem o formulário, nem o código
 *     da tela, nem o hook a puseram);
 *   - a unidade do pai fora das unidades de quem grava: fica a ativa, como
 *     antes (o carimbo recusaria a outra). Sem pai achado, idem.
 * Nunca lança: na dúvida a linha grava como antes.
 */
final class ParentUnit
{
    /** O pai está em mãos (Detail Form, Lista de itens, Upload em tabela, API). */
    public static function fill(object $child, object $parent): void
    {
        if (! self::open($child) || ! self::scoped($parent) || ! $parent instanceof Model) {
            return;
        }
        self::put($child, $parent->getAttribute('unit_id'));
    }

    /**
     * Só a coluna e o id do pai (o `saveDetailItems()` da tela escrita à mão,
     * o `<mad-db-blocks>`, a ligação N:N). O pai é achado pelo `belongsTo` que
     * o Model da linha declara nessa coluna, como o gerador de models emite (com
     * o tipo de retorno), e a unidade é a gravada no banco, sem os escopos: quem
     * grava pode ter a unidade do pai só na lista, não ativa.
     */
    public static function fillByKey(object $child, string $foreignKey, mixed $parentId): void
    {
        if ($foreignKey === '' || $parentId === null || $parentId === '' || ! is_scalar($parentId) || ! self::open($child)) {
            return;
        }

        try {
            $relation = self::relationOn($child, $foreignKey);
            $parent = $relation?->getRelated();
            if ($relation === null || $parent === null || ! self::scoped($parent)) {
                return;
            }
            $unit = $parent->newQueryWithoutScopes()
                ->where($relation->getOwnerKeyName(), $parentId)
                ->value('unit_id');
        } catch (Throwable $e) {
            error_log('[ParentUnit] unidade do pai de ' . get_class($child) . ' não lida: ' . $e->getMessage());

            return;
        }
        self::put($child, $unit);
    }

    /** A linha é escopada por unidade e ainda não tem unidade? */
    private static function open(object $child): bool
    {
        if (! $child instanceof Model || ! self::scoped($child)) {
            return false;
        }
        $current = $child->getAttribute('unit_id');

        return $current === null || $current === '';
    }

    private static function scoped(object $model): bool
    {
        return method_exists($model, 'madUnitScoped') && $model::madUnitScoped();
    }

    private static function put(Model $child, mixed $unit): void
    {
        if (! is_numeric($unit)) {
            return;
        }
        $allowed = UnitContext::allowedIds();
        if ($allowed !== null && ! in_array((int) $unit, $allowed, true)) {
            return;
        }
        $child->setAttribute('unit_id', (int) $unit);
    }

    /** O `belongsTo` público, sem parâmetros e com o tipo de retorno, na coluna `$foreignKey`. */
    private static function relationOn(Model $child, string $foreignKey): ?BelongsTo
    {
        foreach ((new \ReflectionClass($child))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($method->isStatic() || $method->getNumberOfParameters() > 0
                || ! $type instanceof \ReflectionNamedType
                || ! is_a($type->getName(), BelongsTo::class, true)
                || is_a($type->getName(), MorphTo::class, true)) {
                continue;
            }
            $candidate = $method->invoke($child);
            if ($candidate instanceof BelongsTo && $candidate->getForeignKeyName() === $foreignKey) {
                return $candidate;
            }
        }

        return null;
    }
}
