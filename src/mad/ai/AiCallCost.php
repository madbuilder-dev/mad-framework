<?php

namespace Mad\Ai;

/**
 * AiCallCost — holder estatico do custo (US$) reportado pelo provider na
 * chamada corrente, por request PHP.
 *
 * O laravel/ai descarta `usage.cost` ao mapear o Usage; o
 * CostTrackingOpenRouterGateway intercepta o extractUsage e ACUMULA aqui
 * (um turno de agente faz N chamadas HTTP — o custo do turno e a soma).
 * O TokenUsageRecorder consome com take() (le e zera) logo apos a chamada,
 * entao cada registro carrega exatamente o custo do seu turno.
 *
 * Nem todo provider devolve custo (Anthropic direto nao) — null = desconhecido.
 */
final class AiCallCost
{
    private static ?float $cost = null;

    public static function add(float $usd): void
    {
        if ($usd > 0) {
            self::$cost = (self::$cost ?? 0.0) + $usd;
        }
    }

    /** Zera antes de um novo turno (protege de sobra de chamada nao registrada). */
    public static function reset(): void
    {
        self::$cost = null;
    }

    /** Le e zera. null = provider nao reportou custo. */
    public static function take(): ?float
    {
        $c = self::$cost;
        self::$cost = null;

        return $c;
    }
}
