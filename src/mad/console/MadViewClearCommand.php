<?php

namespace Mad\Console;

use Illuminate\Foundation\Console\ViewClearCommand;

/**
 * `php artisan view:clear` (e o `optimize:clear`, que o chama) limpando também
 * o cache das telas `<mad-*>` (fórum #407).
 *
 * As telas do app são compiladas pelo MadBlade num cache próprio,
 * `storage/framework/mad-blade/v<versão do framework>/`, que o `view:clear`
 * do Laravel não alcança: o deploy rodava `view:clear` e a tela velha
 * continuava no ar. Aqui sai a pasta inteira (todas as versões: as antigas
 * são lixo de updates anteriores). Arquivo que o usuário do comando não pode
 * apagar (criado pelo servidor web) fica, sem derrubar o comando; o console
 * avisa quantos ficaram.
 */
class MadViewClearCommand extends ViewClearCommand
{
    public function handle()
    {
        $this->clearMadBlade();

        return parent::handle();
    }

    private function clearMadBlade(): void
    {
        $dir = storage_path('framework/mad-blade');
        if (! $this->files->isDirectory($dir)) {
            return;
        }

        foreach ($this->files->directories($dir) as $sub) {
            $this->files->deleteDirectory($sub);
        }
        foreach ($this->files->files($dir, true) as $file) {
            $this->files->delete($file->getPathname());
        }

        $left = count($this->files->allFiles($dir, true));
        if ($left > 0) {
            $this->components->warn("Cache das telas (MadBlade): {$left} arquivo(s) sem permissão para apagar em storage/framework/mad-blade.");

            return;
        }
        $this->components->info('Cache das telas (MadBlade) limpo.');
    }
}
