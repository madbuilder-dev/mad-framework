<?php

namespace Mad\Routine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mad\Component\MadComponent;

/**
 * Os gatilhos automáticos das rotinas.
 *
 *  - **Ao salvar** ({@see afterSave}): chamado no fim de `MadForm::save()`,
 *    depois de o registro e as linhas filhas estarem gravados. Para cada
 *    rotina `on_save` do registro (e da tela, quando a rotina lista telas):
 *    `redo($registro)`. Roda dentro da transação do `onSave()` da página: um
 *    {@see RoutineStopped} desfaz o Salvar inteiro, e a mensagem vai para o
 *    diálogo de erro.
 *  - **Ao excluir** ({@see deleting}): evento `deleting` de qualquer model;
 *    para cada rotina `on_delete` do registro, `reverse($registro)`. A
 *    exclusão padrão do framework (grade, formulário de agenda) já roda numa
 *    transação da conexão do model: desfazer e excluir ficam juntos.
 *
 * Sem `app/config/routines.json` nada disto faz consulta nenhuma.
 */
final class RoutineTriggers
{
    /** Rotinas que disparam ao salvar este registro, na tela que está salvando. */
    public static function afterSave(object $record): void
    {
        if (! $record instanceof Model || RoutineRegistry::all() === []) {
            return;
        }
        $entries = RoutineRegistry::onSaveFor($record, self::savingController());
        foreach ($entries as $entry) {
            $class = self::routineClass($entry);
            if ($class !== null) {
                $class::redo($record);
            }
        }
    }

    /** Listener de `eloquent.deleting: *`. */
    public static function deleting(string $event, array $payload): void
    {
        $record = $payload[0] ?? null;
        if (! $record instanceof Model || RoutineRegistry::all() === []) {
            return;
        }
        foreach (RoutineRegistry::onDeleteFor($record) as $entry) {
            $class = self::routineClass($entry);
            if ($class !== null) {
                $class::reverse($record);
            }
        }
    }

    /**
     * A classe da rotina, conferida. Rotina declarada e sem classe (publicação
     * pela metade) fica de fora com aviso no log: não derruba o Salvar.
     *
     * @return class-string<Routine>|null
     */
    public static function routineClass(RoutineEntry $entry): ?string
    {
        $class = $entry->class;
        if (class_exists($class) && is_subclass_of($class, Routine::class)) {
            return $class;
        }
        try {
            Log::warning('[routine] rotina "' . $entry->slug . '" ignorada: classe ' . $class . ' não encontrada.');
        } catch (\Throwable) {
            // sem log, segue
        }

        return null;
    }

    /**
     * Basename da tela que está salvando: o MadComponent mais perto na pilha
     * de chamadas (o `onSave()` da página). Fora de uma tela, null — rotina que
     * lista telas não dispara; a que vale para qualquer formulário, sim.
     */
    private static function savingController(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $object = $frame['object'] ?? null;
            if ($object instanceof MadComponent) {
                return class_basename($object);
            }
        }

        return null;
    }
}
