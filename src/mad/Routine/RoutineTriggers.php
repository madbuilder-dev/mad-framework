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
 *  - **Ao excluir** ({@see deletingSource}): evento `deleting` do model de
 *    origem; para cada rotina `on_delete` do registro, `reverse($registro)`.
 *    A exclusão padrão do framework (grade, formulário de agenda) já roda numa
 *    transação da conexão do model: desfazer e excluir ficam juntos.
 *
 * Ordem ao excluir: o model gerado pela plataforma tem, no `booted()`, um
 * `static::deleting` que RECUSA a exclusão quando há filho (o guard de
 * integridade). As parcelas que a rotina gerou são filhos: o desfazer tem de
 * rodar ANTES desse guard, senão a exclusão é barrada e o desfazer nunca
 * acontece. O dispatcher roda os listeners do evento ESPECÍFICO
 * (`eloquent.deleting: App\Models\Pedido`) na ordem de registro e só depois
 * os curingas. Por isso:
 *
 *  - {@see listen} (boot do provider) registra o listener específico de cada
 *    origem `on_delete` do `routines.json` — pelo nome, sem carregar a classe —
 *    e, se o model já bootou (o guard já está lá), passa à frente dele;
 *  - {@see booting} (`eloquent.booting: *`, que dispara ANTES do `booted()`)
 *    cobre a rotina publicada depois do boot do app;
 *  - {@see deleting} (`eloquent.deleting: *`) fica de reserva para o que
 *    escapou dos dois (subclasse da origem, rotina publicada depois de o model
 *    bootar num processo longo) e não repete a origem que já tem o seu.
 *
 * Sem `app/config/routines.json` nada disto faz consulta nenhuma.
 */
final class RoutineTriggers
{
    /** @var \WeakMap<object, array<string, true>>|null por dispatcher: classes com listener específico */
    private static ?\WeakMap $listening = null;

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

    /**
     * Boot do provider: listener específico para cada origem `on_delete`, à
     * frente do guard do model. Idempotente por dispatcher.
     */
    public static function listen(object $events): void
    {
        foreach (RoutineRegistry::all() as $entry) {
            if ($entry->onDelete && $entry->source !== null) {
                self::listenFor($events, $entry->source);
            }
        }
    }

    /** Listener de `eloquent.booting: *`: o model vai bootar — o desfazer entra antes do guard dele. */
    public static function booting(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || RoutineRegistry::all() === []) {
            return;
        }
        $class = $model::class;
        foreach (RoutineRegistry::all() as $entry) {
            if ($entry->onDelete && $entry->source !== null && is_a($class, $entry->source, true)) {
                $events = Model::getEventDispatcher();
                if ($events !== null) {
                    self::listenFor($events, $class);
                }

                return;
            }
        }
    }

    /** Listener de `eloquent.deleting: <origem>`. */
    public static function deletingSource(mixed $record): void
    {
        if ($record instanceof Model) {
            self::reverseFor($record);
        }
    }

    /** Listener de `eloquent.deleting: *` (reserva: a origem com listener próprio já foi atendida). */
    public static function deleting(string $event, array $payload): void
    {
        $record = $payload[0] ?? null;
        if (! $record instanceof Model || RoutineRegistry::all() === []) {
            return;
        }
        $events = Model::getEventDispatcher();
        if ($events !== null && isset(self::listening()[$events][$record::class])) {
            return;
        }
        self::reverseFor($record);
    }

    private static function reverseFor(Model $record): void
    {
        if (RoutineRegistry::all() === []) {
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
     * Registra `eloquent.deleting: <classe>` uma vez por dispatcher, como
     * PRIMEIRO listener do evento: se o model já bootou, o guard dele está na
     * frente — sai e volta para trás do nosso.
     */
    private static function listenFor(object $events, string $class): void
    {
        $map  = self::listening();
        $seen = $map[$events] ?? [];
        if (isset($seen[$class])) {
            return;
        }
        $seen[$class]  = true;
        $map[$events]  = $seen;

        $event    = 'eloquent.deleting: ' . $class;
        $listener = [self::class, 'deletingSource'];
        $raw = method_exists($events, 'getRawListeners') ? ($events->getRawListeners()[$event] ?? []) : [];
        if ($raw === [] || ! method_exists($events, 'forget')) {
            $events->listen($event, $listener);

            return;
        }
        $events->forget($event);
        $events->listen($event, $listener);
        foreach ($raw as $other) {
            $events->listen($event, $other);
        }
    }

    /** @return \WeakMap<object, array<string, true>> */
    private static function listening(): \WeakMap
    {
        return self::$listening ??= new \WeakMap();
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
