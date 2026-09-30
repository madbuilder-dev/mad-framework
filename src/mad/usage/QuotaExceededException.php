<?php

namespace Mad\Usage;

/**
 * QuotaExceededException — lancada por QuotaService::assertWithinQuota quando
 * alguma regra `block` ja estourou. Carrega o resultado do check() (periodos,
 * used/max) p/ o chamador montar mensagem amigavel.
 */
final class QuotaExceededException extends \RuntimeException
{
    /** @param array<string,mixed> $result resultado de QuotaService::check() */
    public function __construct(private array $result, string $message = 'Token quota exceeded')
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function result(): array
    {
        return $this->result;
    }
}
