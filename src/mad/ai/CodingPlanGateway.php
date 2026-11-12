<?php

namespace Mad\Ai;

use Closure;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

/**
 * Gateway do provider `coding_plan` do MadAi: o driver anthropic do
 * laravel/ai INTEIRO (corpo, tools, saída estruturada, stream, cache), com
 * duas trocas:
 *
 *  - a credencial e a URL vêm da sessão curta que o MadAi minta no MadBuilder
 *    (POST /api/app-llm/session com o MAD_PROJECT_TOKEN) NA HORA da chamada
 *    — renovada sozinha antes de vencer, sem segurar nada no boot (e sem
 *    chamada à plataforma quando ninguém usa IA);
 *  - qualquer falha sai como MadAiException com a mensagem pronta para o
 *    usuário (`MadAi::mensagemDeErro`), nunca a exceção crua do HTTP.
 *
 * Sem IA disponível (sem plano, opção desligada, nada configurado) a sessão
 * não existe e a chamada falha antes de sair do app, com o motivo. É também a
 * guarda instalada no provider escolhido quando não há IA nenhuma: a chamada
 * não vai ao provedor com chave vazia.
 */
final class CodingPlanGateway extends AnthropicGateway
{
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        MadAi::codingPlanSession(); // sem IA: o motivo, antes de montar o pedido

        return parent::generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        MadAi::codingPlanSession();

        return yield from parent::generateStreamStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
    }

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $session = MadAi::codingPlanSession();

        // Sem `anthropic-beta`: a Messages API da plataforma não fala com a
        // Anthropic (o modelo é o da pool) e descarta o cabeçalho.
        return $this->createClient(
            $session['base_url'],
            [
                'x-api-key' => $session['token'],
                'anthropic-version' => '2023-06-01',
            ],
            [],
            $timeout ?? 60,
        );
    }

    protected function withErrorHandling(string $providerName, Closure $callback): mixed
    {
        try {
            return parent::withErrorHandling($providerName, $callback);
        } catch (MadAiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Credencial vencida/revogada: a próxima chamada minta outra.
            if (MadAi::httpStatusOf($e) === 401) {
                MadAi::forgetCodingPlanSession();
            }

            throw MadAi::asException($e);
        }
    }
}
