<?php

namespace Mad\Database;

/**
 * Gravação de linha numa unidade que não é do usuário do request.
 *
 * Lançada pelo `saving` do BelongsToUnit quando o unit_id vem explícito (form,
 * import, código) e está fora de UnitContext::allowedIds(). O MadForm::save()
 * converte em erro no campo `unit_id` (toast quando a tela não tem o campo).
 */
class UnitScopeViolation extends \DomainException
{
    public function __construct(public readonly int|string $unitId)
    {
        parent::__construct("A unidade {$unitId} não pertence ao usuário.");
    }
}
