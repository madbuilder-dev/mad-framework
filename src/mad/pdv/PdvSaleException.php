<?php
namespace Mad\Pdv;

/**
 * @internal Transporta um erro de DOMÍNIO (code §7.3 + details) de dentro da
 * transação do onFinalizeSale até o catch — vira `mad-pdv:sale-error`, nunca
 * exceção pro usuário.
 */
class PdvSaleException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode);
    }
}
