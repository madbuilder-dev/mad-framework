<?php

namespace Mad\Mcp;

/**
 * McpScopedGateway
 *
 * Estende McpTableGateway p/ impor o McpScopePlan em TODA leitura/escrita do
 * agente no unico chokepoint (os filtros que vao p/ buildWhere). O path do
 * agente e raw-PDO e NAO passa por global scope do Eloquent, entao tambem
 * reaplica o predicado de soft-delete aqui.
 *
 * Identificadores via ident()/whitelist do manifest; valores PDO-bound; o
 * sujeito (dono/unidade) vem do plano (McpScopeContext, token-bound), nunca dos
 * args do LLM. AND-only => caller so estreita, jamais alarga.
 *
 * Duas camadas, somadas (AND): o PLANO do MCP row-scope (dono/unidades do
 * usuario, opt-in) e a TENANCY do app (unit_id/tenant_id do contexto do token,
 * sempre que Multi-unidade/tenant em pool estao ligados — ver
 * McpScopeContext::tenancyFor()). Um plano "sem filtro" (nivel all, tabela
 * isenta) nao desliga a tenancy.
 */
final class McpScopedGateway extends McpTableGateway
{
    private ?McpScopePlan $plan = null;
    private ?string $softDeleteColumn = null;

    /** @var list<array{0:string,1:string,2:mixed}> */
    private array $tenancyFilters = [];

    /** @var array<string,int> */
    private array $tenancyStamp = [];

    /**
     * @param list<array{0:string,1:string,2:mixed}> $filters
     * @param array<string,int>                      $stamp
     */
    public function withTenancy(array $filters, array $stamp): self
    {
        $this->tenancyFilters = $filters;
        $this->tenancyStamp   = $stamp;

        return $this;
    }

    public function withPlan(?McpScopePlan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function withSoftDeleteColumn(?string $col): self
    {
        $this->softDeleteColumn = $col;

        return $this;
    }

    /** Nega cedo se o plano e fail-closed (tabela sem politica honravel). */
    private function guard(): void
    {
        if ($this->plan !== null && $this->plan->denied) {
            throw new McpScopeException($this->plan->denyReason ?? 'fora de escopo');
        }
    }

    /**
     * @param array<int,array{0:string,1:string,2:mixed}> $filters
     * @return array<int,array{0:string,1:string,2:mixed}>
     */
    private function forced(array $filters): array
    {
        $this->guard();
        if ($this->plan !== null && $this->plan->readFilter !== null) {
            $filters[] = $this->plan->readFilter;
        }
        foreach ($this->tenancyFilters as $f) {
            $filters[] = $f;
        }
        if ($this->softDeleteColumn) {
            $filters[] = [$this->softDeleteColumn, 'is null', null]; // FURO #6: IS NULL real
        }

        return $filters;
    }

    public function select(string $table, array $columns, array $filters = [], ?string $order = null, array $allowedOrder = [], int $limit = 50, int $offset = 0): array
    {
        return parent::select($table, $columns, $this->forced($filters), $order, $allowedOrder, $limit, $offset);
    }

    public function count(string $table, array $filters = []): int
    {
        return parent::count($table, $this->forced($filters));
    }

    // find() herdado chama $this->select() => by-PK tambem e escopado.

    /** INSERT: carimba dono/unidade/tenant (server-side), sobrescrevendo o LLM. */
    public function insert(string $table, array $data): string
    {
        $this->guard();
        if ($this->plan !== null) {
            foreach ($this->plan->stamp as $col => $value) {
                $data[$col] = $value;
            }
        }
        foreach ($this->tenancyFilters as [$col]) {
            unset($data[$col]); // sem unidade/tenant corrente: nao aceita a do payload
        }
        foreach ($this->tenancyStamp as $col => $value) {
            $data[$col] = $value;
        }

        return parent::insert($table, $data);
    }

    /** UPDATE: so toca linha no escopo; colunas de dono/unidade/tenant sao imutaveis. */
    public function update(string $table, string $pk, mixed $id, array $data): int
    {
        $this->guard();
        $this->assertInScope($table, $pk, $id);
        if ($this->plan !== null) {
            foreach (array_keys($this->plan->stamp) as $col) {
                unset($data[$col]); // nunca deixa re-homear via payload
            }
        }
        foreach ($this->tenancyFilters as [$col]) {
            unset($data[$col]);
        }

        return parent::update($table, $pk, $id, $data);
    }

    public function delete(string $table, string $pk, mixed $id): int
    {
        $this->guard();
        $this->assertInScope($table, $pk, $id);

        return parent::delete($table, $pk, $id);
    }

    /** Nega se a linha-alvo nao esta no escopo (find() ja aplica o readFilter). */
    private function assertInScope(string $table, string $pk, mixed $id): void
    {
        if (($this->plan === null || $this->plan->readFilter === null) && $this->tenancyFilters === []) {
            return; // nivel 'all' / exempt e sem tenancy: sem restricao por linha
        }
        if ($this->find($table, $pk, $id, [$pk]) === null) {
            throw new McpScopeException('Registro fora do escopo do usuario.');
        }
    }
}
