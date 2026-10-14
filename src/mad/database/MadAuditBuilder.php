<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Builder;

/**
 * Builder dos models com HasMadAudit.
 *
 * O trait desliga os timestamps nativos (as datas continuam texto, sem cast de
 * Carbon), e com eles o Eloquent deixava de acrescentar a coluna de alteração
 * ao UPDATE em massa (`Model::where(...)->update([...])`, `increment()`,
 * `decrement()`): a linha alterada por consulta ficava com `updated_at` velho
 * ou nulo. Aqui a coluna mapeada em `$madAudit['updated_at']` volta a entrar,
 * com o mesmo formato de data do trait, como o Eloquent faria com
 * `$timestamps = true`. Valor informado na chamada vence.
 *
 * `DB::table(...)->update()` não passa pelo model e não carimba nada
 * (comportamento do Laravel).
 */
class MadAuditBuilder extends Builder
{
    protected function addUpdatedAtColumn(array $values)
    {
        $model = $this->model;
        if ($model->usesTimestamps() || ! method_exists($model, 'madAuditTimestamp')) {
            return parent::addUpdatedAtColumn($values);
        }

        $column = $model->getUpdatedAtColumn();
        if ($column === null) {
            return $values;
        }

        $segments = preg_split('/\s+as\s+/i', (string) $this->query->from);
        $qualified = end($segments) . '.' . $column;

        if (! array_key_exists($column, $values) && ! array_key_exists($qualified, $values)) {
            $values = array_merge([$column => $model->madAuditTimestamp()], $values);
        }

        if (array_key_exists($column, $values)) {
            $values[$qualified] = array_key_exists($qualified, $values) ? $values[$qualified] : $values[$column];
            unset($values[$column]);
        }

        return $values;
    }
}
