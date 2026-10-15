<?php

namespace Mad\Routine;

use Mad\Ui\MadMessage;
use Mad\Ui\MadToast;
use Mad\Ui\MadUserError;

/**
 * Ação `onRunRoutine` — o botão e a ação de linha "Executar rotina". Vem com
 * todo componente (formulário, listagem, grade, painel); só roda rotina
 * marcada como "por botão" (`"button": true` no `routines.json`).
 *
 *   Formulário (registro que a tela abriu):
 *     <mad-btn mad:click="onRunRoutine('gerar-parcelas', 'redo')">Gerar</mad-btn>
 *   Linha da listagem (id da linha):
 *     <mad-act method="onRunRoutine" params="{routine: 'gerar-parcelas', mode: 'redo'}" />
 *
 * Modos: `redo` (padrão), `launch`, `reverse`. Na ação de linha, as chaves de
 * `params` além de `routine`/`mode` vão como parâmetros da rotina.
 *
 * Permissão: a chave Editar da tela (ActionVocab).
 */
trait RunsRoutines
{
    /**
     * @param int|string|null             $first  formulário: slug · linha: id da linha
     * @param array|string|null           $second formulário: modo · linha: params da ação
     * @param array<string, mixed>|null   $third  formulário: parâmetros da rotina
     */
    public function onRunRoutine(int|string|null $first = null, array|string|null $second = null, ?array $third = null): mixed
    {
        if (is_array($second)) {
            // Ação de linha: onRunRoutine(<id>, {routine, mode, ...})
            $slug   = is_string($second['routine'] ?? null) ? $second['routine'] : '';
            $mode   = is_string($second['mode'] ?? null) && $second['mode'] !== '' ? $second['mode'] : 'redo';
            $params = array_diff_key($second, ['routine' => 1, 'mode' => 1]);
            $id     = $first;
            $row    = true;
        } else {
            // Botão do formulário: onRunRoutine('slug', 'modo', {params})
            $slug   = is_string($first) ? $first : '';
            $mode   = is_string($second) && $second !== '' ? $second : 'redo';
            $params = $third ?? [];
            $id     = null;
            $row    = false;
        }

        $entry = RoutineRegistry::find(trim($slug));
        $class = $entry !== null && $entry->button ? RoutineTriggers::routineClass($entry) : null;
        if ($class === null) {
            return MadToast::warning(RoutineText::get('unknown', ['slug' => $slug]));
        }
        if (! in_array($mode, ['redo', 'launch', 'reverse'], true)) {
            return MadToast::warning(RoutineText::get('unknown_mode', ['mode' => $mode]));
        }

        $hasSource = $class::sourceModel() !== null;
        if (! $row && $hasSource) {
            $id = $this->_routineRecordKey();
        }
        if ($hasSource && ($id === null || $id === '')) {
            return MadToast::warning(RoutineText::get('save_first'));
        }

        try {
            $result = match (true) {
                ! $hasSource && $mode === 'reverse' => throw new RoutineStopped(RoutineText::get('no_source')),
                ! $hasSource                        => $class::launch(null, $params),
                $mode === 'launch'                  => $class::launch($id, $params),
                $mode === 'reverse'                 => $class::reverse($id),
                default                             => $class::redo($id, $params),
            };
        } catch (RoutineStopped $e) {
            return MadMessage::error(RoutineText::get('failed_title'), $e->getMessage());
        } catch (\Throwable $e) {
            return MadMessage::error(
                RoutineText::get('failed_title'),
                MadUserError::message($e, RoutineText::get('failed'), static::class . '::onRunRoutine'),
            );
        }

        if ($row && method_exists($this, 'loadData')) {
            $this->loadData();
        }

        return MadToast::success($result->message());
    }

    /**
     * O registro que o formulário ABRIU (anotado no estado da tela pelo
     * `fill($registro)`), nunca um id mandado pelo navegador. Sem anotação,
     * as props de registro da tela (`registroId`/`recordId`/`id`).
     */
    public function _routineRecordKey(): int|string|null
    {
        foreach (get_object_vars($this) as $value) {
            if ($value instanceof \Mad\Form\MadForm) {
                $key = $value->loadedRecordKey();
                if ($key !== null) {
                    return $key;
                }
            }
        }

        return method_exists($this, '_recordId') ? $this->_recordId() : null;
    }
}
