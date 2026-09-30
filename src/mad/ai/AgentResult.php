<?php

namespace Mad\Ai;

use Laravel\Ai\Responses\Data\Usage;

/**
 * AgentResult — retorno de AgentRunner::run().
 *
 * Substitui o retorno antigo (string pura) por um VO que tambem carrega o
 * consumo de tokens (Usage do laravel/ai) e se a iteracao foi abortada pelo
 * cliente. Usado pelo EmbedChatController p/ gravar mad_ai_token_usage.
 *
 *   text     texto final agregado (p/ persistir no historico)
 *   usage    consumo combinado (null em abort ou quando o provider nao reporta)
 *   aborted  true se o cliente desconectou no meio do stream
 */
final class AgentResult
{
    public function __construct(
        public string $text,
        public ?Usage $usage,
        public bool $aborted,
    ) {
    }
}
