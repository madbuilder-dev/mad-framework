<?php

namespace Mad\Webhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Job que processa uma entrega de webhook na fila.
 *
 * Leva SÓ o id da entrega. `$tries = 1`: quem repete uma entrega que falhou é
 * o runtime dos webhooks (recuo e limite em `mad.webhook.max_attempts`), não a
 * fila — duas camadas de repetição multiplicariam as tentativas e perderiam o
 * registro de cada uma.
 *
 * O processador não deixa exceção do handler escapar (ela vira entrega
 * `failed`), então este job não vai para `failed_jobs` por erro do projeto.
 */
class ProcessWebhookDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(): void
    {
        WebhookProcessor::process($this->deliveryId);
    }
}
