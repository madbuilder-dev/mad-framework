<?php

namespace Mad\Ai;

/**
 * Falha da IA do app com a mensagem PRONTA para o usuário (pt-BR) — nunca o
 * corpo cru do provedor nem da plataforma.
 *
 * `codigo()` é o motivo estável, para a tela decidir o que mostrar:
 *  - `coding_plan_required`        quem mantém o sistema não tem o Mad Coding Plan ativo;
 *  - `coding_plan_quota_exhausted` a franquia semanal acabou (`resetAt()` = quando volta);
 *  - `pool_exhausted`              muita demanda no momento (tente de novo em instantes);
 *  - `rate_limited`                muitas chamadas seguidas neste sistema;
 *  - `app_ai_disabled`             a opção está desligada no projeto, no MadBuilder;
 *  - `ai_not_configured`           sem chave própria e sem Mad Coding Plan;
 *  - `not_linked`                  app sem MAD_BUILDER_URL/MAD_PROJECT_TOKEN;
 *  - `unavailable`                 falha de rede ou do provedor.
 */
final class MadAiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $codigo = 'unavailable',
        private readonly ?string $resetAt = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    /** ISO-8601 de quando a franquia volta (só em `coding_plan_quota_exhausted`). */
    public function resetAt(): ?string
    {
        return $this->resetAt;
    }

    /** Código do frame de erro do chat (o iframe do Copilot já reconhece estes). */
    public function chatCode(): ?string
    {
        return match ($this->codigo) {
            'coding_plan_required'        => 'CODING_PLAN_REQUIRED',
            'coding_plan_quota_exhausted' => 'CODING_PLAN_QUOTA_REACHED',
            'pool_exhausted'              => 'CODING_PLAN_POOL_EXHAUSTED',
            'rate_limited'                => 'EMBED_RATE_LIMITED',
            'app_ai_disabled'             => 'EMBED_CHAT_DISABLED',
            'ai_not_configured', 'not_linked' => 'EMBED_UNCONFIGURED',
            default                       => null,
        };
    }
}
