<?php

namespace Mad\Database\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Data de criação/alteração do HasMadAudit lida como data (builder#554).
 *
 * O trait desliga os timestamps do Eloquent para carimbar sozinho, e com eles
 * ia a conversão da coluna para data: `$r->created_at` chegava como texto e
 * `->format('H:i')` não existia. Aqui:
 *   - leitura → Carbon (texto que não é data volta como está);
 *   - gravação → texto no formato do carimbo (`madAuditDateMask`);
 *   - `toArray()`/JSON → o texto gravado, como sempre foi (lista, formulário
 *     e API não mudam).
 * Carbon novo a cada leitura: mexer nele (`->addDays(30)`) não regrava a
 * coluna no próximo save.
 */
final class MadAuditDate implements CastsAttributes, SerializesCastableAttributes
{
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Date::instance($value);
        }
        try {
            return Date::parse((string) $value);
        } catch (\Throwable) {
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return method_exists($model, 'madAuditFormatDate')
                ? $model->madAuditFormatDate($value)
                : $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $attributes[$key] ?? null;
    }
}
