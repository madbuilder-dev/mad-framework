<?php

namespace Mad\Ai;

use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * CostTrackingOpenRouterGateway — gateway OpenRouter que captura o custo.
 *
 * O OpenRouter devolve `usage.cost` (creditos US$) quando o request pede
 * usage accounting (`usage: {include: true}` — injetado pelo middleware HTTP
 * global no MadAi::boot). O Usage do laravel/ai nao tem campo de custo, entao
 * o vendor descarta. Este subclass tapeia o extractUsage — UNICO ponto por
 * onde TODO usage passa (stream e nao-stream) — e acumula em AiCallCost antes
 * de delegar ao parent.
 *
 * Registrado via $provider->useTextGateway() no MadAi::boot (seam publico do
 * pacote) — sem patch de vendor.
 *
 * Tambem liga o prompt cache do Claude (`anthropic/*`), que pelo OpenRouter
 * NAO e automatico como no OpenAI/Gemini: sem `cache_control` cada turno do
 * Embed Chat pagava o prompt inteiro (~14k tokens). Dois breakpoints:
 *   - no system (bloco com cache_control) — cobre tools + system, a parte
 *     estavel; e o que acerta ENTRE turnos, porque o historico salvo nao e
 *     byte a byte o que foi mandado (o contexto do turno nao e persistido);
 *   - `cache_control` no topo (cache automatico da Anthropic) — o breakpoint
 *     anda ate o fim da conversa e cobre os passos do mesmo turno (tool loop).
 * Medido: 2o turno leu 11.849 de 12.206 tokens, custo 9x menor.
 */
final class CostTrackingOpenRouterGateway extends OpenRouterGateway
{
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        return self::withClaudePromptCache(
            parent::buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function withClaudePromptCache(array $body): array
    {
        if (preg_match('#^~?anthropic/#', (string) ($body['model'] ?? '')) !== 1) {
            return $body;
        }

        foreach ($body['messages'] ?? [] as $i => $message) {
            if (($message['role'] ?? '') === 'system' && is_string($message['content'] ?? null) && $message['content'] !== '') {
                $body['messages'][$i]['content'] = [
                    ['type' => 'text', 'text' => $message['content'], 'cache_control' => ['type' => 'ephemeral']],
                ];
                break;
            }
        }
        $body['cache_control'] ??= ['type' => 'ephemeral'];

        return $body;
    }

    // laravel/ai 1.0: o pai devolve TextUsage. Tipo divergente aqui nao e
    // excecao, e erro FATAL de compilacao (o try/catch do MadAi::boot nao pega).
    protected function extractUsage(array $data): TextUsage
    {
        $cost = $data['usage']['cost'] ?? null;
        if (is_numeric($cost)) {
            AiCallCost::add((float) $cost);
        }

        return parent::extractUsage($data);
    }
}
