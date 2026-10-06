<?php

namespace Mad\Database;

/**
 * Mudança de empresa (tenant_id) de um registro de OUTRA empresa, feita pelo
 * administrador do dono na visão de todas as empresas (AdminScope).
 *
 * Lançada pelo `saving` do BelongsToTenant: a visão ampla só amplia a leitura —
 * o administrador lê e edita registros das outras empresas, mas cada um fica na
 * empresa dele. Fora dessa visão nada lança esta exceção.
 */
class TenantScopeViolation extends \DomainException
{
    public function __construct(public readonly int|string $tenantId)
    {
        parent::__construct("Este registro é de outra empresa e não pode ser movido para a empresa {$tenantId}.");
    }
}
