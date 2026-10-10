<?php

namespace Mad\Support;

use Mad\Component\MadRenderContext;
use Mad\Form\MadForm;

/**
 * MadFieldValue — ponto ÚNICO de resolução do valor inicial de um campo.
 *
 * ## Por que isto existe
 *
 * Cada `*-field.blade.php` resolvia o valor inicial do seu jeito, e as regras
 * divergiram em três dialetos incompatíveis:
 *
 * 1. `number-field`, `input-field`, `time-field`, `seek-field`, `search-field`,
 *    `money-field`, `numeric-field`, `otp-field`, `dbentry-field` e
 *    `password-field` liam SÓ o `MadRenderContext` e **nunca** olhavam a prop
 *    `value` — que nem estava declarada no `@props`. Como o `@props` só define
 *    default para variável ainda não setada, `value="15"` na tag virava um
 *    `$value` vivo no escopo que o template simplesmente ignorava: prop morta,
 *    zero erro, zero aviso. No `<mad-number-field>` o sintoma era pior porque o
 *    `placeholder` default era `0` — o campo aparecia com "0" e o dev concluía
 *    que o valor tinha chegado como zero, quando na verdade não tinha chegado.
 * 2. `date-field`, `datetime-field`, `color-field`, `range-field`,
 *    `textarea-field` liam `$value` mas com `!isset($value)`, ou seja, o valor
 *    literal da tag GANHAVA do registro — em form de edição isso descarta o
 *    dado do banco.
 * 3. `cep-field`, `cnpj-field`, `spinner-field` usavam `array_key_exists()`
 *    puro, então uma coluna presente e vazia (`''`, o caso normal do form de
 *    criação) ganhava do default escrito pelo dev.
 *
 * ## A regra única
 *
 * 1. valor do registro/estado (`MadRenderContext`) quando a chave existe **e**
 *    não é vazia — form de edição sempre mostra o dado do banco;
 * 2. senão, a prop `value` da tag — que passa a valer como **default**;
 * 3. senão, string vazia.
 *
 * O "não vazio" do passo 1 é deliberado: é o que faz `value="15"` sobreviver no
 * form de criação (onde o contexto traz a coluna com `''`) sem atropelar a
 * edição. O preço é conhecido: registro que guarda `''` de propósito volta a
 * exibir o default — se algum campo precisar da distinção, ele resolve na mão,
 * não aqui.
 */
final class MadFieldValue
{
    /**
     * Valor inicial do campo como string pronta pro atributo `value=`.
     *
     * @param string $name     Nome do campo (chave no MadRenderContext)
     * @param mixed  $explicit Prop `value` recebida na tag (default do dev)
     */
    public static function resolve(string $name, $explicit = null): string
    {
        if ($name !== '') {
            $ctx = MadRenderContext::current();
            if (array_key_exists($name, $ctx)) {
                $v = $ctx[$name];
                if ($v !== null && $v !== '' && !is_array($v) && !is_object($v)) {
                    return (string) $v;
                }
            }
        }

        if ($explicit === null || is_array($explicit) || is_object($explicit)) {
            return '';
        }

        if (is_bool($explicit)) {
            return $explicit ? '1' : '';
        }

        // O campo vai para a tela com o valor padrão da tag: o navegador o
        // devolve sem ninguém ter digitado — o formulário anota, para não
        // tomá-lo por digitação (MadForm::_fieldsWithoutColumn).
        if ($name !== '' && (string) $explicit !== '') {
            MadRenderContext::getForm()?->noteFieldDefault($name, (string) $explicit);
        }

        return (string) $explicit;
    }

    /**
     * Monta a string `$attrs` do input injetando `value=` e `mad:model=`.
     *
     * Preserva SEMPRE o que o caller passou: `value=` e `mad:model=` só entram
     * quando ainda não existem em `$attrs`. Antes disto metade dos componentes
     * fazia `$attrs = 'value=… mad:model=…'` (atribuição, não concatenação),
     * e qualquer `mad:change`/`@change`/`data-*` do caller sumia em silêncio —
     * a menos que o caller passasse o próprio `mad:model`, único jeito de
     * desviar do bloco. Os dois casos agora convivem.
     *
     * @param string $attrs          Atributos crus vindos da tag
     * @param string $name           Nome do campo
     * @param mixed  $explicit       Prop `value` da tag (default do dev)
     * @param string $modelDirective `mad:model` ou `mad:model.live`
     */
    /** `mad:model.live` → `data-mad-model-live`; `mad:model` → `data-mad-model`. */
    private static function dataDirective(string $directive): string
    {
        return match (trim($directive)) {
            'mad:model.live', 'data-mad-model-live' => 'data-mad-model-live',
            default => 'data-mad-model',
        };
    }

    public static function mergeAttrs(
        string $attrs,
        string $name,
        $explicit = null,
        string $modelDirective = 'mad:model'
    ): string {
        $prefix = '';

        if (!preg_match('/(^|\s)value\s*=/i', $attrs)) {
            $val = self::resolve($name, $explicit);
            if ($val !== '') {
                $prefix .= 'value="' . htmlspecialchars($val, ENT_QUOTES) . '" ';
            }
        }

        if ($name !== ''
            && strpos($attrs, 'mad:model') === false
            && strpos($attrs, 'data-mad-model') === false
        ) {
            // Emite JÁ na forma data-*: a conversão `mad:model=` →
            // `data-mad-model=` do MadBlade acontece em tempo de COMPILAÇÃO do
            // template, e esta string nasce em tempo de RENDER — nunca passava
            // por lá. O runtime só serializa `[data-mad-model]`, então o campo
            // ficava fora do payload e a prop pública do componente nunca
            // hidratava: filtro lateral de texto não filtrava NADA (o valor
            // digitado não chegava no servidor).
            $prefix .= self::dataDirective($modelDirective) . '="' . $name . '" ';
        }

        return trim($prefix . $attrs);
    }

    /**
     * "Valor padrão" (`default` da tag) dos campos de tabela, aplicado POR
     * ÚLTIMO: só quando nem a prop `selected` nem o registro aberto trouxeram
     * valor.
     *
     * Nesses templates (dbcombo, dbradio, dbunique-search) a prop `selected`
     * vence o registro, então o `default` não pode virar `selected` na
     * compilação como vira `value` nos outros campos — a edição abriria no
     * padrão em vez do valor gravado. Mesma regra de `resolve()` para o vazio:
     * registro que guarda `''`/NULL exibe o padrão.
     *
     * @param string $current Valor já resolvido (selected > registro), '' = nenhum
     * @param mixed  $default Prop `default` da tag
     */
    public static function withDefault(string $name, string $current, $default = null): string
    {
        if ($current !== '' || $default === null || is_array($default) || is_object($default) || is_bool($default)) {
            return $current;
        }
        $value = (string) $default;
        if ($name !== '' && $value !== '') {
            MadRenderContext::getForm()?->noteFieldDefault($name, $value);
        }

        return $value;
    }

    /**
     * "Valor padrão" (`default` da tag) dos campos de MÚLTIPLA escolha, aplicado
     * por último: só no CADASTRO NOVO e com a seleção ainda vazia (nem a prop
     * da tag nem o registro marcaram nada).
     *
     * Diferente de `withDefault()`, o registro aberto com a seleção vazia NÃO
     * recebe o padrão: numa lista, nada marcado é o que o usuário gravou
     * (desmarcou tudo e salvou) — mostrar o padrão de novo faria o próximo
     * Salvar regravar as marcas que ele tirou. "Cadastro novo" é a mesma
     * resposta do auto-load das tabelas de ligação (MadRenderContext::recordId).
     * Pelo mesmo motivo, no cadastro novo a seleção que já voltou do navegador
     * (`[]`, o usuário desmarcou tudo e a tela foi redesenhada) é dele: o
     * padrão só vale enquanto o campo não veio (ausente, NULL ou '').
     *
     * @param string[] $current   Seleção já resolvida (prop > registro), [] = nenhuma
     * @param mixed    $default   Prop `default` da tag: lista separada por
     *                            `$separator`, array ou JSON (`["1","2"]`)
     * @return string[]
     */
    public static function withDefaultSelection(string $name, array $current, $default = null, string $separator = ','): array
    {
        if ($current !== [] || $default === null || is_bool($default) || is_object($default)) {
            return $current;
        }
        if ($name !== '') {
            if (MadRenderContext::recordId() !== null) {
                return $current;
            }
            $ctx = MadRenderContext::current();
            if (array_key_exists($name, $ctx) && $ctx[$name] !== null && $ctx[$name] !== '') {
                return $current;
            }
        }

        return MadForm::selectionKeys($default, $separator);
    }

    /**
     * Idem, já coagido para float — usado por money/numeric, que trabalham com
     * o valor cru e não com o texto formatado.
     */
    public static function resolveFloat(string $name, $explicit = null, float $fallback = 0.0): float
    {
        $raw = self::resolve($name, $explicit);

        return is_numeric($raw) ? (float) $raw : $fallback;
    }
}
