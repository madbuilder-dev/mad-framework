<?php

namespace Mad\Console;

use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Contracts\Events\Dispatcher;
use Mad\Database\DestructiveGuard;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php artisan migrate --allow-destructive` (decisão do dono, 24/11/2026).
 *
 * A migration gerada pelo MadBuilder só apaga tabela ou coluna, ou troca o
 * tipo perdendo dado, com confirmação explícita: sem ela o passo fica pendente
 * e o `migrate` avisa no console. Com a opção, os passos rodam e, no fim, os
 * que um migrate anterior deixou pendentes são confirmados (DestructiveGuard).
 * `--pretend` nunca confirma nada.
 *
 * É o próprio `migrate` do Laravel com uma opção a mais: vale no terminal, no
 * deploy e no Artisan::call.
 */
class MadMigrateCommand extends MigrateCommand
{
    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);

        if (! $this->getDefinition()->hasOption('allow-destructive')) {
            $this->addOption(
                'allow-destructive',
                null,
                InputOption::VALUE_NONE,
                'Aplica também os passos que apagam tabela/coluna ou trocam o tipo perdendo dado, e confirma os pendentes',
            );
        }
    }

    public function handle()
    {
        if (! $this->option('allow-destructive') || $this->option('pretend')) {
            return parent::handle();
        }

        return DestructiveGuard::confirmed(function () {
            $code = parent::handle();
            foreach ($this->destructiveDirs() as $dir) {
                foreach (DestructiveGuard::applyPending($dir) as $name) {
                    $this->components->info("Confirmado: {$name}");
                }
            }

            return $code;
        });
    }

    /** @return list<string|null> null = database/migrations */
    private function destructiveDirs(): array
    {
        $paths = array_filter((array) $this->option('path'));
        if ($paths === []) {
            return [null];
        }

        return array_values(array_map(
            fn ($p) => $this->option('realpath') ? (string) $p : base_path((string) $p),
            $paths,
        ));
    }
}
