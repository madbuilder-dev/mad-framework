<?php

declare(strict_types=1);

namespace Mad\Form;

/**
 * `{combo->campo}` nas expressões do "Preencher valor quando" (`fill-value`) e
 * do campo calculado (`compute`) — fórum #440: o campo `campo` do registro
 * escolhido no combo `combo` da mesma tela (ou da mesma linha do detalhe).
 *
 *   <mad-dbcombo-field name="tipo_documento_id" model="TipoDocumento" … />
 *   <mad-date-field name="proximo_vencimento" fill-overwrite
 *       fill-when="{data_vencimento} != ''"
 *       fill-value="addMonths({data_vencimento}, {tipo_documento_id->vencimento_meses})" />
 *
 * O registro é o do Model do próprio combo (`model` + `key`), carregado pelo
 * valor escolhido — nada de consulta livre. Só um elo (`combo->campo`), só
 * nome simples. O valor é DADO: lido pelo Eloquent (cast e accessor valem) e
 * nunca executado. Campo do `$hidden` do Model (senha, token) não é lido. Combo
 * sem escolha, registro que não existe ou campo sem valor = vazio, sem erro.
 *
 * No navegador o registro vem pelo mesmo serviço do preenchimento do combo
 * (MadAutoFillService, token cifrado com a lista dos campos que as expressões
 * da TELA usam — o navegador não escolhe campo). No Salvar, o servidor lê o
 * mesmo campo do mesmo registro ({@see valuesFor()}) e refaz a conta com o
 * DateFormula — o mesmo resultado dos dois lados.
 */
final class ComboFieldRef
{
    /** `{combo->campo}` (também com `->` escapado no HTML). */
    public const RE = '/\{([A-Za-z_]\w*)->([A-Za-z_]\w*)\}/';

    /** Registros já carregados nesta requisição: classe|chave|valor => registro|null. */
    private static array $memo = [];

    /** Esquece os registros carregados (teste; novo request no Octane). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /** A expressão lê algum `{combo->campo}` (fora de aspas)? */
    public static function uses(string $expr): bool
    {
        return $expr !== '' && str_contains($expr, '->') && preg_match(self::RE, self::outsideQuotes($expr)) === 1;
    }

    /**
     * Os `{combo->campo}` da expressão (fora de aspas): combo => campos.
     *
     * @return array<string, list<string>>
     */
    public static function refs(string $expr): array
    {
        $out = [];
        if ($expr === '' || !str_contains($expr, '->')) {
            return $out;
        }
        if (preg_match_all(self::RE, self::outsideQuotes($expr), $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (!in_array($hit[2], $out[$hit[1]] ?? [], true)) {
                    $out[$hit[1]][] = $hit[2];
                }
            }
        }

        return $out;
    }

    /**
     * Os `{combo->campo}` lidos pelas expressões de um HTML já desenhado
     * (`x-mad-compute` e `data-mad-fill-value`, com o `->` escapado ou não),
     * só do combo `$combo`.
     *
     * @return list<string>
     */
    public static function fieldsInHtml(string $html, string $combo): array
    {
        if ($combo === '' || !str_contains($html, $combo)) {
            return [];
        }
        $fields = [];
        if (preg_match_all('/\s(?:x-mad-compute|data-mad-fill-value)="([^"]*)"/', $html, $attrs)) {
            $re = '/\{' . preg_quote($combo, '/') . '(?:->|-&gt;)([A-Za-z_]\w*)\}/';
            foreach ($attrs[1] as $value) {
                if (preg_match_all($re, $value, $m)) {
                    foreach ($m[1] as $f) {
                        if (!in_array($f, $fields, true)) {
                            $fields[] = $f;
                        }
                    }
                }
            }
        }

        return array_slice($fields, 0, 50);
    }

    /**
     * Valor de `$field` no registro, como texto — o MESMO que o navegador
     * recebe: data sem hora `Y-m-d`, data e hora `Y-m-d H:i:s`, verdadeiro/
     * falso `1`/`0`. Campo oculto do Model, lista ou objeto = vazio.
     */
    public static function read(object $record, string $field): string
    {
        if (!preg_match('/^[A-Za-z_]\w*$/', $field)) {
            return '';
        }
        try {
            if ($record instanceof \Illuminate\Database\Eloquent\Model) {
                if (in_array($field, $record->getHidden(), true)) {
                    return '';
                }
                $v = $record->getAttribute($field);
            } else {
                $v = $record->{$field} ?? null;
            }
        } catch (\Throwable) {
            return '';
        }

        if ($v instanceof \DateTimeInterface) {
            return $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format('Y-m-d H:i:s');
        }
        if ($v instanceof \BackedEnum) {
            $v = $v->value;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v) || is_string($v)) {
            return (string) $v;
        }

        return '';
    }

    /**
     * O registro escolhido no combo: pelo Model e pela chave do próprio combo
     * (os que a fórmula leva no estado da tela — MadFormRegistry::comboSource).
     * Combo de `:query` sem Model, valor vazio ou registro que não existe = null.
     *
     * @param array<string, mixed>|mixed $source
     */
    public static function load(mixed $source, mixed $value): ?object
    {
        if (!is_array($source) || !is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $model = (string) ($source['model'] ?? '');
        $key = (string) ($source['key'] ?? '') ?: 'id';
        if ($model === '' || !preg_match('/^[A-Za-z_]\w*$/', $key)) {
            return null;
        }
        try {
            $cls = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
            if (!is_string($cls) || !is_subclass_of($cls, \Illuminate\Database\Eloquent\Model::class)) {
                return null;
            }
            $memoKey = $cls . '|' . $key . '|' . (string) $value;
            if (!array_key_exists($memoKey, self::$memo)) {
                self::$memo[$memoKey] = $cls::query()->where($key, '=', (string) $value)->first();
            }

            return self::$memo[$memoKey];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Os valores dos `{combo->campo}` da expressão, para o DateFormula ler como
     * campo (`'combo->campo' => texto`). O combo precisa ser campo da tela (com
     * Model) e ter um valor em `$values`; senão o valor é vazio.
     *
     * @param array<string, mixed>                $values valores da tela/linha (crus)
     * @param array<string, array<string, mixed>> $combos combo => ['model', 'key']
     * @return array<string, string>
     */
    public static function valuesFor(string $expr, array $values, array $combos): array
    {
        $out = [];
        foreach (self::refs($expr) as $combo => $fields) {
            $record = self::load($combos[$combo] ?? null, $values[$combo] ?? null);
            foreach ($fields as $field) {
                $out[$combo . '->' . $field] = $record === null ? '' : self::read($record, $field);
            }
        }

        return $out;
    }

    /** A expressão sem o conteúdo entre aspas. */
    private static function outsideQuotes(string $expr): string
    {
        return (string) preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s', "''", $expr);
    }
}
