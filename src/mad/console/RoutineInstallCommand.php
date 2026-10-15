<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Mad\Routine\RoutineLedger;
use Mad\Routine\RoutineRegistry;

/**
 * mad:routine:install — cria a tabela do livro das rotinas
 * (`mad_routine_entry`) em toda conexão listada em `app/config/routines.json`.
 *
 * Idempotente: conexão que já tem a tabela fica como está. É o conserto do app
 * que rodou a migration do livro com um framework anterior às rotinas (a
 * migration ficou marcada como rodada, sem tabela). `--connection=x` cria numa
 * conexão só, esteja ela no arquivo ou não.
 */
class RoutineInstallCommand extends Command
{
    protected $signature = 'mad:routine:install
        {--connection=* : Conexão (repita para várias); sem a opção, as do routines.json}';

    protected $description = 'Rotinas: cria a tabela do livro em cada conexão usada pelas rotinas';

    public function handle(): int
    {
        $connections = array_values(array_filter(array_map('trim', (array) $this->option('connection'))));
        if ($connections === []) {
            RoutineRegistry::flush();
            $connections = RoutineRegistry::connections();
            foreach (RoutineRegistry::all() as $entry) {
                if ($entry->connection !== null && ! in_array($entry->connection, $connections, true)) {
                    $connections[] = $entry->connection;
                }
            }
        }
        if ($connections === []) {
            $this->line('Nenhuma rotina declarada: nada a instalar.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($connections as $connection) {
            try {
                RoutineLedger::createTable($connection);
                $this->line("Livro das rotinas pronto na conexão {$connection}.");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("Conexão {$connection}: não foi possível criar a tabela (" . $e->getMessage() . ').');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
