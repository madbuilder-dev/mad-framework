<?php

namespace Mad\Ai\Gateways;

use Laravel\Ai\Gateway\Xai\XaiGateway;

/**
 * Gateway da xAI (Grok, Responses API) com o mesmo cuidado do da OpenAI
 * (GuardsResponsesStream). Ligado por MadAi::boot() quando o provider é `xai`.
 */
class StatusAwareXaiGateway extends XaiGateway
{
    use GuardsResponsesStream;

    protected function providerLabel(): string
    {
        return 'xAI';
    }
}
