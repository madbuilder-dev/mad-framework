<?php

namespace Mad\Routine;

/**
 * Parâmetros declarados de uma rotina (fórum #294) — o que o Studio grava em
 * Rotinas › Parâmetros e o gerador escreve em `params()` da classe:
 *
 *   ['key' => 'tipo', 'label' => 'Tipo', 'type' => 'select', 'required' => true,
 *    'options' => ['A' => 'Avulso', 'M' => 'Mensal']]
 *
 * Duas regras, conferidas no servidor antes de a rotina rodar:
 *  - Seleção: valor informado fora da lista é recusado, venha de onde vier.
 *  - Obrigatório: vazio é recusado quando quem chama informa os parâmetros —
 *    o botão e a ação de linha "Executar rotina" (`onRunRoutine`). Ao salvar e
 *    ao excluir a rotina roda sem parâmetros, como sempre rodou.
 */
final class RoutineParams
{
    /**
     * @param list<array<string, mixed>> $declared
     * @param array<string, mixed>       $given
     *
     * @throws RoutineStopped com a mensagem para o usuário
     */
    public static function check(array $declared, array $given, bool $requireAll): void
    {
        foreach ($declared as $param) {
            if (! is_array($param) || ! is_string($param['key'] ?? null)) {
                continue;
            }
            $key   = $param['key'];
            $label = is_string($param['label'] ?? null) && trim($param['label']) !== '' ? trim($param['label']) : $key;
            $value = $given[$key] ?? null;
            $empty = $value === null || (is_string($value) && trim($value) === '') || $value === [];

            if ($empty) {
                if ($requireAll && ! empty($param['required'])) {
                    throw new RoutineStopped(RoutineText::get('param_required', ['label' => $label]));
                }

                continue;
            }

            if (($param['type'] ?? null) === 'select' && is_array($param['options'] ?? null) && $param['options'] !== []) {
                $options = array_map('strval', array_keys($param['options']));
                if (! is_scalar($value) || ! in_array((string) $value, $options, true)) {
                    throw new RoutineStopped(RoutineText::get('param_not_option', [
                        'label'   => $label,
                        'value'   => is_scalar($value) ? (string) $value : '…',
                        'options' => implode(', ', array_map('strval', array_values($param['options']))),
                    ]));
                }
            }
        }
    }
}
