<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Mad\Service\MadUploadStorage;

/**
 * mad:uploads:prune-empty — lista (e, com --delete, remove) as pastas vazias de
 * arquivo público que sobraram no disco de uploads (fórum #269).
 *
 * Um campo de arquivo com `public` grava em `_public/<pasta>/<hmac>/<nome>`.
 * Antes da 5.282.0, excluir o arquivo deixava a pasta do `<hmac>` para trás.
 * Daqui em diante ela sai junto; este comando limpa as que já ficaram.
 *
 * Só toca pasta `_public/<pasta>/<24 hex>` sem arquivo e sem subpasta. Sem
 * `--delete` só mostra o que removeria.
 */
class UploadsPruneEmptyCommand extends Command
{
    protected $signature = 'mad:uploads:prune-empty {--delete : Remove as pastas vazias (sem a opção, só lista)}';

    protected $description = 'Lista e remove as pastas vazias de arquivo público que sobraram no disco de uploads';

    public function handle(): int
    {
        $empty = MadUploadStorage::emptyPublicFolders();
        if ($empty === []) {
            $this->info('Nenhuma pasta vazia de arquivo público.');

            return self::SUCCESS;
        }

        foreach ($empty as $dir) {
            $this->line($dir);
        }

        if (! $this->option('delete')) {
            $this->info(count($empty) . ' pasta(s) vazia(s). Rode com --delete para remover.');

            return self::SUCCESS;
        }

        $removed = MadUploadStorage::pruneEmptyPublicFolders();
        $this->info(count($removed) . ' pasta(s) removida(s).');

        return self::SUCCESS;
    }
}
