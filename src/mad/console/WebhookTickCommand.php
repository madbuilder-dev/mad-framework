<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Mad\Webhook\WebhookProcessor;
use Mad\Webhook\WebhookRegistry;
use Mad\Webhook\WebhookStore;

/**
 * mad:webhook:tick — manutenção dos webhooks recebidos, agendada a cada minuto
 * pelo próprio framework:
 *
 *  1. dá por interrompidas as entregas cujo processamento morreu no meio;
 *  2. repete as que falharam e já passaram do recuo (até `mad.webhook.max_attempts`);
 *  3. retoma as `received` que ninguém processou (job perdido, processo que
 *     caiu depois de responder);
 *  4. apaga o que passou de `retention_days` de cada webhook.
 *
 * Onde não há agendador (Teste Online), os passos 2 e 3 também acontecem aos
 * poucos a cada entrega aceita ({@see WebhookProcessor::sweepOne()}).
 *
 * Sem definições ou sem as tabelas, não faz nada e sai com sucesso.
 */
class WebhookTickCommand extends Command
{
    protected $signature = 'mad:webhook:tick
        {--limit=50 : Máximo de entregas por passo nesta execução}
        {--json : Saída em uma linha JSON}';

    protected $description = 'Webhooks recebidos: repete falhas, retoma entregas paradas e apaga as vencidas';

    /** Segundos que uma execução pode gastar processando (o agendador chama de novo no minuto seguinte). */
    private const BUDGET = 45;

    public function handle(): int
    {
        $stats = ['ok' => true, 'interrupted' => 0, 'retried' => 0, 'resumed' => 0, 'pruned' => 0];

        if (! WebhookStore::tablesReady()) {
            return $this->finish($stats);
        }

        $limit = max(1, (int) $this->option('limit'));
        $deadline = time() + self::BUDGET;

        foreach (WebhookStore::expiredLeaseIds($limit) as $id) {
            $stats['interrupted'] += WebhookStore::failInterrupted($id) ? 1 : 0;
        }

        foreach (WebhookStore::dueRetryIds($limit) as $id) {
            if (time() >= $deadline) {
                break;
            }
            if (WebhookStore::release($id) && WebhookProcessor::process($id)['claimed']) {
                $stats['retried']++;
            }
        }

        foreach (WebhookStore::staleIds($limit) as $id) {
            if (time() >= $deadline) {
                break;
            }
            if (WebhookProcessor::process($id)['claimed']) {
                $stats['resumed']++;
            }
        }

        $retention = [];
        foreach (WebhookRegistry::all() as $definition) {
            $retention[$definition->slug] = $definition->retentionDays;
        }
        $stats['pruned'] = WebhookStore::prune($retention);

        return $this->finish($stats);
    }

    /** @param  array<string, mixed>  $stats */
    private function finish(array $stats): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($stats));
        } else {
            $this->line(sprintf(
                'Interrompidas: %d · repetidas: %d · retomadas: %d · apagadas: %d',
                $stats['interrupted'], $stats['retried'], $stats['resumed'], $stats['pruned'],
            ));
        }

        return self::SUCCESS;
    }
}
