<?php

namespace Mad\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * EmbedAgent — AnonymousAgent com teto de iteracoes + prompt cache.
 *
 * #[MaxSteps] limita as rodadas modelo↔tools por turno (sem ele o gateway usa
 * round(count(tools) * 1.5) — alto demais com 24 tools).
 *
 * Cache por driver:
 *   - anthropic  → #[CacheInstructions] + #[CacheToolDefinitions] (nativos do
 *     laravel/ai 1.0): o system vira bloco com cache_control e a ULTIMA tool
 *     ganha o breakpoint (um breakpoint cobre o array inteiro de tools). Ate o
 *     0.8 isto era feito a mao aqui, re-mapeando as tools numa copia do
 *     MapsTools::mapTool via providerOptions.
 *   - openrouter → caching e AUTOMATICO nos providers que suportam (OpenAI,
 *     Gemini 2.5+, DeepSeek, Grok; modelos :free custam 0 de qualquer jeito).
 *     Injetamos `usage: {include: true}` (accounting nativo OpenRouter) para o
 *     stream final trazer cached_tokens/cache_write_tokens + cost.
 *   - outros → [].
 */
#[MaxSteps(12)]
#[CacheInstructions]
#[CacheToolDefinitions]
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

        return $driver === 'openrouter' ? ['usage' => ['include' => true]] : [];
    }
}
