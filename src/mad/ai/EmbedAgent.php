<?php

namespace Mad\Ai;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * EmbedAgent — AnonymousAgent com teto de iteracoes + prompt cache (Anthropic).
 *
 * #[MaxSteps] limita as rodadas modelo↔tools por turno (sem ele o gateway usa
 * round(count(tools) * 1.5) — alto demais com 24 tools).
 *
 * HasProviderOptions liga o prompt cache da Anthropic: o laravel/ai 0.7.2 nao
 * tem suporte nativo a cache_control, mas faz `array_merge($body, $providerOptions)`
 * como ULTIMO passo do buildTextRequestBody — entao sobrescrevemos:
 *   - `system`: string → bloco [{type:text, cache_control:ephemeral}]
 *   - `tools`:  re-mapeadas no MESMO formato do MapsTools::mapTool
 *               (name/description/input_schema), com cache_control na ULTIMA
 *               (um breakpoint cobre o array inteiro de tools)
 *
 * O loop de tools do gateway reusa o $requestBody ja merged (handleStreaming-
 * ToolCalls so faz append de messages e re-POST), entao o cache_control
 * sobrevive a todas as iteracoes do turno. tool_choice nao conflita: sem
 * schema o gateway seta {type:auto} e o merge nao toca nessa chave.
 *
 * Cache por driver:
 *   - anthropic  → override de system+tools com cache_control (acima).
 *   - openrouter → caching e AUTOMATICO nos providers que suportam (OpenAI,
 *     Gemini 2.5+, DeepSeek, Grok; modelos :free custam 0 de qualquer jeito).
 *     Injetamos `usage: {include: true}` (accounting nativo OpenRouter) para o
 *     stream final trazer cached_tokens/cache_write_tokens + cost — o gateway
 *     0.7.2 ja parseia `prompt_tokens_details.*` para o Usage. Marcacao
 *     explicita p/ Claude-via-OpenRouter exigiria cache_control multipart
 *     DENTRO de `messages` (dinamicas) — sem hook no 0.7.2; nao suportado.
 *   - outros → [].
 */
#[MaxSteps(12)]
final class EmbedAgent extends AnonymousAgent implements HasProviderOptions
{
    /**
     * Teto de tokens de completion ([ai] max_tokens). Protege modelos :free com
     * limite de completion baixo; null → default do provider/modelo.
     */
    public function maxTokens(): ?int
    {
        $v = (int) (config('ai.max_tokens') ?: 0);

        return $v > 0 ? $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : (string) $provider;

        return match ($driver) {
            'anthropic'  => $this->anthropicOptions(),
            'openrouter' => ['usage' => ['include' => true]],
            default      => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function anthropicOptions(): array
    {
        $options = [];

        $system = (string) $this->instructions;
        if ($system !== '') {
            $options['system'] = [
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
            ];
        }

        $tools = $this->mapToolsWithCacheControl();
        if ($tools !== []) {
            $options['tools'] = $tools;
        }

        return $options;
    }

    /**
     * Re-mapeia as tools no formato Anthropic (espelho de MapsTools::mapTool) e
     * marca a ULTIMA com cache_control — a Anthropic cacheia ate o breakpoint,
     * cobrindo o array inteiro.
     *
     * @return list<array<string, mixed>>
     */
    private function mapToolsWithCacheControl(): array
    {
        $mapped = [];

        foreach ($this->tools as $tool) {
            if (! $tool instanceof Tool) {
                continue; // ProviderTool (web search/fetch) nao e usado no embed
            }

            $schema = $tool->schema(new JsonSchemaTypeFactory());

            $inputSchema = ['type' => 'object', 'properties' => (object) []];
            if (filled($schema)) {
                $schemaArray = (new ObjectSchema($schema))->toSchema();
                $inputSchema['properties'] = (object) ($schemaArray['properties'] ?? []);
                $inputSchema['required']   = $schemaArray['required'] ?? [];
            }

            $mapped[] = [
                'name'         => ToolNameResolver::resolve($tool),
                'description'  => (string) $tool->description(),
                'input_schema' => $inputSchema,
            ];
        }

        if ($mapped !== []) {
            $mapped[count($mapped) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $mapped;
    }
}
