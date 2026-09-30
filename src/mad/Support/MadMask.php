<?php

namespace Mad\Support;

/**
 * MadMask — ponto ÚNICO da tabela de aliases de máscara de digitação.
 *
 * ## Por que isto existe
 *
 * O alias (`mask="cpf"`) é resolvido em DOIS lugares independentes:
 *
 *   - no client (`_madResolveMaskPattern` em mad-ui.js), que formata enquanto
 *     o usuário digita;
 *   - no server, que precisa do TAMANHO do alias para assar o `maxlength` do
 *     input quando o autor não passou um — sem ele o campo aceita digitação
 *     além do pattern e a máscara trunca em silêncio.
 *
 * A tabela de tamanhos vivia inline no `input-field.blade.php`. Quando o
 * `<mad-field-list>` passou a aceitar `mask` por coluna (fw 5.40), copiá-la
 * daria duas tabelas para manter em sincronia — e a divergência só apareceria
 * como "no field-list o CPF deixa digitar um dígito a mais", que ninguém liga
 * a um alias faltando numa cópia. Por isso a tabela mora aqui e os dois
 * templates a consomem.
 *
 * A resolução do PATTERN em si continua no client: ela depende do valor
 * digitado (cpfcnpj alterna conforme o tamanho), e duplicar essa decisão no
 * PHP criaria uma segunda verdade sobre a mesma máscara.
 */
class MadMask
{
    /**
     * Aliases com tamanho FIXO de string mascarada.
     *
     * `cpfcnpj` é dinâmico (14 no CPF, 18 no CNPJ) — fica com o maior, que é o
     * que o `maxlength` precisa permitir.
     */
    private const ALIAS_MAX_LENGTH = [
        'cpf'      => 14,
        'cnpj'     => 18,
        'cpfcnpj'  => 18,
        'cep'      => 9,
        'phone'    => 15,
        'tel'      => 15,
        'telefone' => 15,
        'celular'  => 15,
        'date'     => 10,
        'time'     => 5,
        'datetime' => 16,
        'placa'    => 8,
        'rg'       => 12,
    ];

    /**
     * Tamanho da string mascarada de um alias conhecido, ou null.
     *
     * Pattern literal (`999.999-99`) devolve null de propósito: o client já
     * trunca pelo próprio pattern, e assar um `maxlength` calculado aqui
     * duplicaria essa contagem no PHP.
     */
    public static function aliasMaxLength(string $mask): ?int
    {
        return self::ALIAS_MAX_LENGTH[strtolower(trim($mask))] ?? null;
    }

    /** O valor é um alias conhecido (e não um pattern literal)? */
    public static function isAlias(string $mask): bool
    {
        return isset(self::ALIAS_MAX_LENGTH[strtolower(trim($mask))]);
    }

    /**
     * Remove tudo que não é dígito/letra — o que o `strip-mask` grava no banco.
     *
     * Espelha o `preg_replace` do `MadFormRegistry::transformField()` e do
     * `MadSheet`, para que os três caminhos (input solto, planilha, linha de
     * field-list) gravem exatamente a mesma coisa.
     */
    public static function strip(string $value): string
    {
        $stripped = preg_replace('/[^0-9A-Za-z]/u', '', $value);

        return is_string($stripped) ? $stripped : $value;
    }
}
