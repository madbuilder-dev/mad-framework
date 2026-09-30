<?php

namespace Mad\Console;

use Illuminate\Console\Command;

/**
 * mad:trace-flush — drena o spool NDJSON do MadTrace (eventos enfileirados
 * pelo modo send=async) e POSTa ao receptor. Agendado a cada minuto em
 * routes/console.php (substitui o cron madtrace-flush.php do legado).
 */
class TraceFlushCommand extends Command
{
    protected $signature = 'mad:trace-flush {--max-attempts=5 : Tentativas antes de dropar um evento}';

    protected $description = 'Envia os eventos MadTrace enfileirados no spool ao receptor';

    public function handle(): int
    {
        if (!class_exists('MadTrace')) {
            $this->warn('MadTrace indisponível (autoload).');
            return self::FAILURE;
        }

        $dir = config('mad.trace.spool_dir') ?: storage_path('madtrace-spool');
        $res = \MadTrace::flushSpool($dir, (int) $this->option('max-attempts'));

        $this->info(sprintf(
            'sent=%d failed=%d requeued=%d dropped=%d',
            $res['sent'], $res['failed'], $res['requeued'], $res['dropped']
        ));

        return self::SUCCESS;
    }
}
