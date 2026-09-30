<?php

namespace Mad\Http;

/**
 * Id de correlação por transação de banco — agrupa as linhas de log (SQL,
 * change-audit) escritas dentro do MESMO DB::transaction(). Substitui o
 * getUniqId() do helper de transação legado.
 *
 * Pilha por nível de aninhamento (SAVEPOINT): o evento TransactionBeginning
 * empilha um id, TransactionCommitted/TransactionRolledBack desempilha (wiring
 * em MadServiceProvider::bootTransactionCorrelation). get() devolve o id do
 * nível corrente; FORA de qualquer transação cai no RequestId (correlação por
 * request, mesma granularidade do antigo uniqid quando não havia tx aberta).
 */
class TransactionId
{
    /** @var string[] pilha de ids, 1 por nível de transação ativo */
    private static array $stack = [];

    /** Empilha um novo id (chamado em TransactionBeginning). Retorna-o. */
    public static function push(): string
    {
        $id = uniqid('tx_');
        self::$stack[] = $id;

        return $id;
    }

    /** Desempilha (chamado em TransactionCommitted/RolledBack). */
    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    /** Id da transação corrente, ou o RequestId fora de transação. */
    public static function get(): string
    {
        return self::$stack === [] ? RequestId::get() : self::$stack[array_key_last(self::$stack)];
    }

    /** Zera a pilha — defensivo p/ reuso de worker/queue entre jobs e testes. */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
