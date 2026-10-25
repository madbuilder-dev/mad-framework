<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Mad\Service\RequestLogRetention;

/**
 * mad:request-log:purge — apaga do log de requisições o que passou do prazo
 * (fw#419). Agendado todo dia às 03:15 pelo MadServiceProvider, pulado
 * enquanto o app guarda tudo. Ver {@see RequestLogRetention}.
 */
class RequestLogPurgeCommand extends Command
{
    protected $signature = 'mad:request-log:purge
        {--days= : Prazo em dias (padrão: o da tela Logs › Requisições ou MAD_REQUEST_LOG_DAYS)}
        {--batch=5000 : Linhas por fatia}
        {--dry-run : Só conta o que sairia}';

    protected $description = 'Apaga do log de requisições o que passou do prazo';

    public function handle(): int
    {
        $opt  = $this->option('days');
        $days = $opt === null || $opt === '' ? RequestLogRetention::days() : RequestLogRetention::normalize($opt);
        if ($days <= 0) {
            $this->info('Log de requisições sem prazo: nada a apagar.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        try {
            $count = RequestLogRetention::purge($days, (int) $this->option('batch'), $dry);
        } catch (\Throwable $e) {
            // Sem a tabela ou banco fora: nunca derruba o agendador.
            $this->warn('request-log:purge pulado: ' . $e->getMessage());

            return self::SUCCESS;
        }

        $this->info(sprintf('%s%d linha(s) %s [prazo: %d dia(s)].', $dry ? '[dry-run] ' : '', $count, $dry ? 'sairiam' : 'apagada(s)', $days));

        return self::SUCCESS;
    }
}
