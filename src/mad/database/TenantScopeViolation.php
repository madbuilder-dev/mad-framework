<?php

namespace Mad\Database;

/**
 * Gravação de linha numa empresa (tenant_id) que não é a do request.
 *
 * Lançada pelo `saving` do BelongsToTenant em dois casos:
 *   - tenant_id explícito (form, API, código que atribui do getData()) diferente
 *     da empresa ativa (fw#535);
 *   - o administrador do dono, na visão de todas as empresas (AdminScope), muda
 *     de empresa um registro de OUTRA empresa — a visão ampla só amplia a leitura.
 *
 * O MadForm::save() converte em erro no campo `tenant_id`; a API REST responde
 * 422 com o erro em `tenant_id` ({@see Concerns\AnswersAsFieldError}).
 */
class TenantScopeViolation extends \DomainException
{
    use Concerns\AnswersAsFieldError;

    public function __construct(public readonly int|string $tenantId, ?string $message = null)
    {
        parent::__construct($message ?? "Este registro é de outra empresa e não pode ser movido para a empresa {$tenantId}.");
    }

    /** tenant_id que veio no corpo e não é a empresa ativa (fw#535). */
    public static function foreign(int|string $tenantId): self
    {
        return new self($tenantId, "A empresa {$tenantId} não é a empresa ativa: o registro não pode ser gravado nela.");
    }

    public function errorField(): string
    {
        return 'tenant_id';
    }
}
