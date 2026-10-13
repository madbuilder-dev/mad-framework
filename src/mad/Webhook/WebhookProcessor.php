<?php

namespace Mad\Webhook;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;

/**
 * Processa uma entrega de webhook: reivindica, aplica o contexto da unidade,
 * chama o handler do projeto e grava o resultado.
 *
 * É o ÚNICO caminho de processamento — fila ({@see ProcessWebhookDelivery}),
 * depois da resposta, modo `sync`, `mad:webhook:tick`, `mad:webhook test` e o
 * botão Reprocessar passam todos por `process()`, e todos começam pela mesma
 * reivindicação atômica. Dois caminhos disputando a mesma entrega: um processa,
 * o outro sai sem fazer nada.
 *
 * `process()` nunca lança: exceção do handler vira entrega `failed`, com nova
 * tentativa agendada até `mad.webhook.max_attempts`.
 */
final class WebhookProcessor
{
    /** Chave de cache do batimento do worker da fila. */
    public const HEARTBEAT_KEY = 'mad:webhook:worker-heartbeat';

    /** Intervalo mínimo (s) entre duas gravações do batimento pelo mesmo worker. */
    private const HEARTBEAT_EVERY = 30;

    private static int $lastBeat = 0;

    /**
     * @return array{claimed: bool, ok: bool, result: mixed, error: ?string}
     *   `claimed` falso = outro processo já levou a entrega (ou ela não está `received`).
     */
    public static function process(int $deliveryId): array
    {
        if (! WebhookStore::claim($deliveryId)) {
            return ['claimed' => false, 'ok' => false, 'result' => null, 'error' => null];
        }

        $started = microtime(true);
        $elapsed = static fn (): int => (int) round((microtime(true) - $started) * 1000);
        $scoped = false;

        try {
            $row = WebhookStore::delivery($deliveryId);
            if ($row === null) {
                return ['claimed' => true, 'ok' => false, 'result' => null, 'error' => 'Entrega removida.'];
            }

            $definition = WebhookRegistry::find((string) $row->webhook);
            if ($definition === null) {
                throw new \RuntimeException('Webhook sem definição neste app (removido ou ainda não publicado).');
            }

            if ($row->unit_id !== null && $row->unit_id !== '') {
                WebhookScope::activate((int) $row->unit_id);
                $scoped = true;
            }

            $result = self::invoke($definition, new WebhookDelivery($deliveryId, $row));

            WebhookStore::markProcessed($deliveryId, $elapsed());

            return ['claimed' => true, 'ok' => true, 'result' => $result, 'error' => null];
        } catch (\Throwable $e) {
            $error = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

            try {
                WebhookStore::markFailed($deliveryId, $error, $elapsed());
            } catch (\Throwable $ignored) {
                // sem banco para registrar a falha: a reivindicação vence e o tick retoma
            }

            if (! $e instanceof WebhookScopeException) {
                try {
                    report($e);
                } catch (\Throwable $ignored) {
                    // relatar o erro nunca pode derrubar quem está processando
                }
            }

            return ['claimed' => true, 'ok' => false, 'result' => null, 'error' => $error];
        } finally {
            if ($scoped) {
                WebhookScope::deactivate();
            }
        }
    }

    /**
     * No modo pedido, a entrega vai para a fila? `queue` sempre; `auto` só com
     * um worker vivo e uma fila de verdade; `sync` nunca.
     */
    public static function shouldQueue(string $mode): bool
    {
        if ($mode === WebhookDefinition::MODE_QUEUE) {
            return true;
        }

        return $mode === WebhookDefinition::MODE_AUTO && self::queueIsReal() && self::workerAlive();
    }

    /** Põe a entrega na fila. Falso se a fila recusou (aí quem processa é o próprio processo). */
    public static function enqueue(int $deliveryId): bool
    {
        try {
            dispatch(new ProcessWebhookDelivery($deliveryId));

            return true;
        } catch (\Throwable $e) {
            try {
                report($e);
            } catch (\Throwable $ignored) {
            }

            return false;
        }
    }

    /**
     * Varredura preguiçosa: no máximo UMA entrega parada (ou falha com nova
     * tentativa vencida) é retomada a cada entrega aceita. É o que mantém o
     * recurso andando onde não há agendador (Teste Online): sem `schedule:run`,
     * o `mad:webhook:tick` não roda.
     */
    public static function sweepOne(int $exceptId = 0): ?int
    {
        try {
            $id = WebhookStore::staleIds(1, $exceptId)[0] ?? null;

            if ($id === null) {
                $id = WebhookStore::dueRetryIds(1, $exceptId)[0] ?? null;
                if ($id !== null && ! WebhookStore::release($id)) {
                    return null;
                }
            }
            if ($id === null) {
                return null;
            }

            $definition = WebhookRegistry::find((string) (WebhookStore::delivery($id)->webhook ?? ''));
            if ($definition !== null && $definition->mode !== WebhookDefinition::MODE_SYNC
                && self::shouldQueue($definition->mode) && self::enqueue($id)) {
                return $id;
            }

            self::process($id);

            return $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── batimento do worker ─────────────────────────────────────────────────

    /**
     * Chamado a cada volta do `queue:work` (evento `Looping`). Grava o
     * batimento no cache no máximo a cada 30 s.
     */
    public static function beat(): void
    {
        $now = time();
        if ($now - self::$lastBeat < self::HEARTBEAT_EVERY) {
            return;
        }
        self::$lastBeat = $now;

        try {
            Cache::put(self::HEARTBEAT_KEY, $now, 86400);
        } catch (\Throwable $e) {
            // sem cache: o modo `auto` processa no próprio processo
        }
    }

    /** Há um worker de fila que deu sinal de vida há menos de `heartbeat_ttl` segundos? */
    public static function workerAlive(): bool
    {
        try {
            $beat = (int) Cache::get(self::HEARTBEAT_KEY, 0);
        } catch (\Throwable $e) {
            return false;
        }

        return $beat > 0 && time() - $beat < WebhookConfig::heartbeatTtl();
    }

    /** A conexão de fila padrão guarda o job para outro processo (não é `sync` nem `null`)? */
    public static function queueIsReal(): bool
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        return ! in_array($driver, ['sync', 'null', ''], true);
    }

    /** Zera o controle de frequência do batimento (testes). */
    public static function flush(): void
    {
        self::$lastBeat = 0;
    }

    // ── handler do projeto ──────────────────────────────────────────────────

    private static function invoke(WebhookDefinition $definition, WebhookDelivery $delivery): mixed
    {
        $class = $definition->handlerClass;
        if (! class_exists($class)) {
            throw new \RuntimeException("Classe {$class} não encontrada.");
        }

        if ($definition->handlerKind === 'job') {
            // O job do projeto roda AQUI, e não numa segunda ida à fila: este
            // processador já está fora da resposta, e é só dentro dele que
            // valem o contexto da unidade, o registro da falha e a repetição.
            return app(Dispatcher::class)->dispatchSync(new $class($delivery));
        }

        $handler = app()->make($class);
        $method = $definition->handlerMethod;
        if (! is_callable([$handler, $method])) {
            throw new \RuntimeException("Método {$class}::{$method}() não existe ou não é público.");
        }

        return $handler->{$method}($delivery);
    }
}
