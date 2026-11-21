<?php

namespace Mad\Ai\Gateways;

use Generator;
use Illuminate\Support\Facades\Log;

/**
 * O mesmo cuidado do StatusAwareGeminiGateway para a Responses API (OpenAI e
 * xAI), cujo stream conta a falha num evento próprio e não num `error`:
 *
 *  - `response.failed` traz `response.error {code, message}`;
 *  - `response.incomplete` traz `response.incomplete_details.reason`;
 *  - o `error` da OpenAI vem com `code`/`message` no topo, e o laravel/ai
 *    1.0.1 só lê `error.code`/`error.message` (mostraria "Unknown error").
 *
 * O laravel/ai 1.0.1 só trata `error`: os dois primeiros acabavam numa
 * resposta vazia sem motivo. Aqui viram `error` com o motivo do provedor;
 * `incomplete` com texto continua valendo. Stream sem evento nenhum também
 * vira erro. O log leva status/código/detalhe/modelo — sem chave e sem o
 * conteúdo da conversa.
 */
trait GuardsResponsesStream
{
    /** Nome do provedor nas mensagens ("OpenAI", "xAI"). */
    abstract protected function providerLabel(): string;

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function parseServerSentEvents($streamBody): Generator
    {
        $sawEvent  = false;
        $sawOutput = false;

        foreach (parent::parseServerSentEvents($streamBody) as $data) {
            $sawEvent = true;
            $type     = (string) ($data['type'] ?? '');

            if ($type === 'error' && ! is_array($data['error'] ?? null)) {
                yield $this->responsesError('error', (string) ($data['code'] ?? 'error'), (string) ($data['message'] ?? ''), []);

                return;
            }

            if ($type === 'response.output_text.delta' && trim((string) ($data['delta'] ?? '')) !== '') {
                $sawOutput = true;
            }
            if (in_array($type, ['response.function_call_arguments.delta', 'response.function_call_arguments.done'], true)
                || ($type === 'response.output_item.added' && ($data['item']['type'] ?? '') === 'function_call')) {
                $sawOutput = true;
            }

            if ($type === 'response.failed') {
                $response = is_array($data['response'] ?? null) ? $data['response'] : [];
                $error    = is_array($response['error'] ?? null) ? $response['error'] : [];

                yield $this->responsesError('failed', (string) ($error['code'] ?? 'failed'), (string) ($error['message'] ?? ''), $response);

                return;
            }

            if ($type === 'response.incomplete' && ! $sawOutput) {
                $response = is_array($data['response'] ?? null) ? $data['response'] : [];
                $reason   = (string) ($response['incomplete_details']['reason'] ?? '');

                yield $this->responsesError('incomplete', $reason !== '' ? $reason : 'incomplete', $reason, $response);

                return;
            }

            yield $data;
        }

        if (! $sawEvent) {
            yield $this->responsesError('empty', 'empty', '', []);
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array{type: string, error: array{code: string, message: string}}
     */
    private function responsesError(string $status, string $code, string $detail, array $response): array
    {
        $label  = $this->providerLabel();
        $detail = SecretScrubber::clean(trim($detail));

        $message = match ($status) {
            'empty'      => "A {$label} encerrou a resposta sem enviar nada.",
            'incomplete' => "A {$label} parou antes de responder (status incomplete"
                . ($detail !== '' ? ": {$detail}" : '') . ').',
            default      => "A {$label} recusou a resposta" . ($detail !== '' ? ": {$detail}" : " (status {$status})."),
        };

        Log::warning('[mad-ai] ' . strtolower($label) . ' response did not produce a reply', [
            'status'      => $status,
            'code'        => $code,
            'detail'      => mb_substr($detail, 0, 500),
            'model'       => $response['model'] ?? null,
            'response_id' => $response['id'] ?? null,
        ]);

        return ['type' => 'error', 'error' => ['code' => $code, 'message' => $message]];
    }
}
