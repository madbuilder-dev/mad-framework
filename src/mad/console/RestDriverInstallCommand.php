<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Mad\Rest\RestDriver;
use Mad\Rest\RestDriverKeyStore;

/**
 * `mad:rest-driver:install` — instala/pareia o Driver REST no app do cliente.
 *
 * Gera uma chave (keyId + secret de 256 bits), persiste no keystore local e
 * IMPRIME o payload de pareamento UMA vez (o secret só aparece aqui — o builder
 * o guarda cifrado). O HMAC é simétrico; o segredo nunca trafega depois.
 *
 *   php artisan mad:rest-driver:install --connection=business --read-only
 */
class RestDriverInstallCommand extends Command
{
    protected $signature = 'mad:rest-driver:install
        {--connection= : Conexão do banco a expor (default: a padrão do app)}
        {--read-only : Bloqueia INSERT/UPDATE/DELETE nesta chave}
        {--label= : Rótulo livre p/ identificar a chave}
        {--url= : URL pública do driver (default: APP_URL + path)}
        {--json : Imprime só o JSON do pareamento (p/ pipe)}';

    protected $description = 'Instala e pareia o Driver REST do MadBuilder (gera chave HMAC).';

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: RestDriver::defaultConnection());
        $readOnly = (bool) $this->option('read-only');
        $label = (string) ($this->option('label') ?: 'pareado via CLI');

        // Valida que a conexão existe no app.
        if (! config("database.connections.{$connection}")) {
            $this->error("Conexão '{$connection}' não existe em config/database.php.");
            return self::FAILURE;
        }

        $store = new RestDriverKeyStore();
        $gen = $store->generate($connection, $readOnly, $label);

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $url = $base . '/' . RestDriver::path();

        $pairing = [
            'key_id' => $gen['key_id'],
            'secret' => $gen['secret'],
            'url' => $url,
            'connection' => $connection,
            'read_only' => $readOnly,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($pairing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('Driver REST pareado. Cole estes dados na conexão REST do MadBuilder:');
        $this->newLine();
        $this->line(json_encode($pairing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->warn('⚠️  O secret só é mostrado AGORA. Guarde-o no builder (fica cifrado lá).');

        if (! RestDriver::enabled()) {
            $this->newLine();
            $this->warn('O driver está DESABILITADO. Habilite no app do cliente:');
            $this->line('  MAD_REST_DRIVER_ENABLED=true   (no .env)  — e rode `php artisan config:clear`');
        }

        return self::SUCCESS;
    }
}
