<?php

namespace Mad\Mcp;

/**
 * McpScopePlan
 *
 * Resultado da resolucao de row-scope p/ (entidade × usuario). Diz ao gateway:
 *   - readFilter: o WHERE forcado nas leituras ([coluna, op, valores]) ou null
 *     (null = sem filtro: nivel 'all' ou tabela exempt);
 *   - stamp: colunas de dono/unidade a CARIMBAR no insert (proveniencia), das
 *     colunas que a tabela possui, sempre da identidade do token;
 *   - denied: fail-closed — a tabela nao tem como satisfazer o nivel do usuario
 *     (sem coluna de escopo e sem exempt) => negar a operacao.
 */
final class McpScopePlan
{
    /**
     * @param array{0:string,1:string,2:mixed}|null $readFilter [coluna, op, valores]
     * @param array<string,int>                      $stamp      coluna => valor (insert)
     */
    public function __construct(
        public readonly bool $denied = false,
        public readonly ?string $denyReason = null,
        public readonly ?array $readFilter = null,
        public readonly array $stamp = [],
    ) {
    }

    /** @param array{0:string,1:string,2:mixed}|null $readFilter @param array<string,int> $stamp */
    public static function allow(?array $readFilter, array $stamp = []): self
    {
        return new self(false, null, $readFilter, $stamp);
    }

    public static function deny(string $reason): self
    {
        return new self(true, $reason);
    }

    /** Sem filtro de leitura (nivel 'all'/exempt), mas ainda carimba proveniencia. */
    public static function unscoped(array $stamp = []): self
    {
        return new self(false, null, null, $stamp);
    }

    public function isScoped(): bool
    {
        return $this->readFilter !== null;
    }
}
