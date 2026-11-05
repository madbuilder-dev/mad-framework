<?php

namespace Mad\Database\Casts;

use ArrayObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * Coluna `json`/`jsonb` lida como array no código (builder#644).
 *
 * O MadBuilder gerava o Model sem conversão nenhuma para essas colunas:
 * `$faixa->reparticao = ['irpj' => '5.5']; $faixa->save();` estourava com
 * "Array to string conversion" e a leitura devolvia o texto JSON. O cast
 * `array` do Laravel resolve o código, mas muda a tela: o formulário recebe o
 * array, o campo de texto aparece vazio e o Salvar apaga a coluna; o JSON
 * digitado vira um texto entre aspas dentro do JSON. Aqui:
 *   - leitura → array (texto que não é JSON volta como está);
 *   - gravação → array/objeto/número viram JSON; texto que já é JSON (o que o
 *     formulário manda) é gravado como veio, sem codificar de novo; outro
 *     texto vira um texto JSON; em branco vira null;
 *   - `toArray()`/JSON → o texto gravado, como sempre foi (formulário, lista e
 *     API não mudam).
 */
final class MadJson implements CastsAttributes, ComparesCastableAttributes, SerializesCastableAttributes
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Array novo a cada leitura, mesmo depois de atribuir uma Collection. */
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }

        return json_validate($value) ? json_decode($value, true) : $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            // Texto em branco vira null, como o formulário já faz com o campo
            // vazio. Texto que já é JSON vai como veio; outro texto vira um
            // texto JSON (`"a,b"`), que o banco aceita e a leitura devolve igual.
            if (trim($value) === '') {
                return null;
            }
            if (json_validate($value)) {
                return $value;
            }
        }
        if ($value instanceof ArrayObject) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof Arrayable && ! $value instanceof JsonSerializable) {
            $value = $value->toArray();
        }

        $json = json_encode($value, self::FLAGS);
        if ($json === false) {
            throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
        }

        return $json;
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $attributes[$key] ?? null;
    }

    /**
     * O mesmo conteúdo escrito de outro jeito não é mudança: o `jsonb` do
     * Postgres devolve `{"a": 1}` com espaço e chaves reordenadas, e gravar o
     * mesmo array de novo não deve contar como alteração.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return self::normalized($firstValue) === self::normalized($secondValue);
    }

    private static function normalized(mixed $raw): mixed
    {
        if (! is_string($raw) || ! json_validate($raw)) {
            return $raw;
        }
        $value = json_decode($raw, true);
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return $sort($value);
    }
}
