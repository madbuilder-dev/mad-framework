<?php

namespace Mad\Ai;

use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Responses\Data\Usage;

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
 */
final class CostTrackingOpenRouterGateway extends OpenRouterGateway
{
    protected function extractUsage(array $data): Usage
    {
        $cost = $data['usage']['cost'] ?? null;
        if (is_numeric($cost)) {
            AiCallCost::add((float) $cost);
        }

        return parent::extractUsage($data);
    }
}
