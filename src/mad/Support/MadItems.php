<?php

namespace Mad\Support;

/**
 * MadItems — lista de opções dos campos de escolha no formato que eles
 * percorrem: o MAPA `[valor => rótulo]`.
 *
 * ## Por que existe
 *
 * `<mad-select-field>`, `<mad-checkbox-group-field>`, `<mad-select-check-field>`
 * e os outros campos de escolha fazem `@foreach($items as $valor => $rotulo)`.
 * Uma LISTA DE OBJETOS — a forma natural de quem vem de JSON, e a que o agente
 * escreveu num filtro de listagem —
 *
 *   :items="[['value' => 'baixo', 'label' => 'Estoque baixo (até 5)'],
 *            ['value' => 'zerado', 'label' => 'Sem estoque']]"
 *
 * saía como `<option value="0"></option><option value="1"></option>`: a
 * posição virava o valor e o rótulo ficava vazio. Nenhum erro, nenhum aviso —
 * e o teste da tela "passou" escolhendo o valor 0.
 *
 * ## Regra
 *
 * Converte SÓ uma lista (`array_is_list`) em que TODO item é um array com uma
 * chave de valor (`value`, `id`, `key`) e uma de rótulo (`label`, `text`,
 * `name`, `title`, `nome`) — as mesmas que as colunas do field-list já aceitam
 * ({@see \Mad\Form\FieldListColumn::normalizeOptions()}), mais `title` e o
 * `nome` que é a coluna de exibição padrão do framework. Item com mais de uma
 * dessas chaves usa a primeira, na ordem acima.
 *
 * Qualquer outra forma volta INTACTA: o mapa canônico, a lista de textos
 * (`['A', 'B']`), o mapa de badge (`['ativo' => ['Ativo', 'success']]`) e a
 * lista de pares (`[['Ativo', 'success'], …]`) — cada componente continua
 * tratando essas formas como sempre tratou.
 *
 * O rótulo segue como veio (o componente já sabe achatar um par de badge). O
 * valor precisa servir de chave: escalar, nulo (vira `''`), enum com valor ou
 * objeto que vira texto — senão a lista não é de opções e volta intacta.
 */
final class MadItems
{
    /** Chaves aceitas para o VALOR da opção, em ordem de preferência. */
    private const VALUE_KEYS = ['value', 'id', 'key'];

    /** Chaves aceitas para o RÓTULO da opção, em ordem de preferência. */
    private const LABEL_KEYS = ['label', 'text', 'name', 'title', 'nome'];

    /**
     * Lista de objetos `{valor, rótulo}` → mapa `[valor => rótulo]`; o resto
     * volta como veio.
     *
     * @param  array<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    public static function normalize(array $items): array
    {
        if ($items === [] || ! array_is_list($items)) {
            return $items;
        }

        $map = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                return $items;
            }

            $valueKey = self::firstKey($item, self::VALUE_KEYS);
            $labelKey = self::firstKey($item, self::LABEL_KEYS);
            if ($valueKey === null || $labelKey === null) {
                return $items;
            }

            $value = self::optionKey($item[$valueKey]);
            if ($value === null) {
                return $items;
            }

            $map[$value] = $item[$labelKey];
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $item
     * @param list<string>            $keys
     */
    private static function firstKey(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item)) {
                return $key;
            }
        }

        return null;
    }

    /** Valor da opção pronto para ser chave do mapa; null = não serve de valor. */
    private static function optionKey(mixed $value): int|string|null
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
