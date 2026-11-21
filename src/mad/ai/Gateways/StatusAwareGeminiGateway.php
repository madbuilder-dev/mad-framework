<?php

namespace Mad\Ai\Gateways;

use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;

/**
 * Gateway do Gemini que não deixa uma falha do Google virar resposta vazia
 * (fórum #376, 21/11/2026; a mesma correção do Agente do Studio).
 *
 * A Interactions API do Gemini responde HTTP 200 e conta a falha DENTRO do
 * stream: o último evento é `interaction.completed` (ou
 * `interaction.status_update`) com `status` `failed`/`cancelled` e a lista
 * `errors[{code, message}]`. O parser de stream do laravel/ai 1.0.1 só
 * reconhece um `error` no topo do evento: o stream terminava "com sucesso" e
 * vazio, e o chat do app não dizia nada.
 *
 * Aqui, por cima dos eventos SSE já decodificados, esses finais viram um
 * evento `error` com a mensagem do Google — o laravel/ai transforma em
 * `Streaming\Events\Error`, que o AgentRunner mostra no chat. Também viram
 * erro: `incomplete` sem nenhum texto nem chamada de ferramenta (o limite de
 * tokens acabou antes da resposta) e um stream sem evento nenhum.
 * `incomplete` com texto continua valendo (resposta cortada no fim).
 *
 * O log leva só o que o Google disse sobre a falha: nunca a chave nem o
 * conteúdo da conversa. Ligado por MadAi::boot() quando o provider é `google`.
 */
class StatusAwareGeminiGateway extends GeminiGateway
{
    private const FAILED = ['failed', 'cancelled'];

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function parseServerSentEvents($streamBody): Generator
    {
        $sawEvent  = false;
        $sawOutput = false;

        foreach (parent::parseServerSentEvents($streamBody) as $data) {
            $sawEvent = true;
            $type     = (string) ($data['event_type'] ?? '');

            if ($type === 'step.delta' || $type === 'step.start') {
                $sawOutput = $sawOutput || self::carriesOutput($data);
            }

            if ($type === 'interaction.completed' || $type === 'interaction.status_update') {
                $interaction = is_array($data['interaction'] ?? null) ? $data['interaction'] : $data;
                $status      = (string) ($interaction['status'] ?? $data['status'] ?? '');

                if (in_array($status, self::FAILED, true) || ($status === 'incomplete' && ! $sawOutput && $type === 'interaction.completed')) {
                    yield self::errorEvent($status, $interaction);

                    return;
                }
            }

            yield $data;
        }

        if (! $sawEvent) {
            yield self::errorEvent('empty', []);
        }
    }

    /**
     * Texto de verdade ou chamada de ferramenta — raciocínio (`thought`) não conta.
     *
     * @param array<string, mixed> $data
     */
    private static function carriesOutput(array $data): bool
    {
        $step = is_array($data['step'] ?? null) ? $data['step'] : [];
        if (($step['type'] ?? '') === 'function_call') {
            return true;
        }

        $delta     = is_array($data['delta'] ?? null) ? $data['delta'] : [];
        $deltaType = (string) ($delta['type'] ?? '');
        if ($deltaType === 'text') {
            return trim((string) ($delta['text'] ?? '')) !== '';
        }

        return $deltaType !== '' && ! in_array($deltaType, ['thought', 'thought_summary', 'thought_signature'], true);
    }

    /**
     * @param array<string, mixed> $interaction
     * @return array{event_type: string, error: array{code: string, message: string}}
     */
    private static function errorEvent(string $status, array $interaction): array
    {
        $first  = is_array($interaction['errors'][0] ?? null) ? $interaction['errors'][0] : [];
        $code   = (string) ($first['code'] ?? $status);
        $detail = trim((string) ($first['message'] ?? ''));

        $message = match ($status) {
            'empty'      => 'O Gemini encerrou a resposta sem enviar nada.',
            'incomplete' => 'O Gemini parou antes de responder (status incomplete: o limite de tokens acabou antes da resposta).',
            default      => "O Gemini recusou a resposta (status {$status})",
        };
        if ($detail !== '') {
            $message .= ': ' . SecretScrubber::clean($detail);
        } elseif ($status !== 'empty' && $status !== 'incomplete') {
            $message .= '.';
        }

        Log::warning('[mad-ai] gemini interaction did not produce a reply', [
            'status'         => $status,
            'code'           => $code,
            'detail'         => mb_substr(SecretScrubber::clean($detail), 0, 500),
            'model'          => $interaction['model'] ?? null,
            'interaction_id' => $interaction['id'] ?? null,
        ]);

        return ['event_type' => 'error', 'error' => ['code' => $code, 'message' => $message]];
    }
}
