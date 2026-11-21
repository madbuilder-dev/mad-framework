<?php

namespace Mad\Ai\Gateways;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;

/**
 * Gateway da OpenAI (Responses API) que transforma `response.failed`,
 * `response.incomplete` sem saída e stream vazio em erro com o motivo
 * (GuardsResponsesStream). Ligado por MadAi::boot() quando o provider é `openai`.
 */
class StatusAwareOpenAiGateway extends OpenAiGateway
{
    use GuardsResponsesStream;

    protected function providerLabel(): string
    {
        return 'OpenAI';
    }
}
