<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fluxo encerrado: chegou a um fim (approved | rejected | returned | outro
 * status do nó end) ou foi cancelado porque o registro mudou de versão
 * (`cancelled`, nodeKey null). Traz o registro RECARREGADO (já com a coluna
 * de status escrita pelo fluxo) e a trilha de decisões — o suficiente para
 * mover o estado do app ou montar o PDF sem reconsultar.
 */
class InstanceCompleted extends WorkflowEvent
{
    /**
     * @param  list<array{task_id: int, node: string, step: string|null, outcome: string|null,
     *   user_id: int|null, user_name: string|null, on_behalf_of: int|null, decided_at: string|null,
     *   comment: string|null, ip: string|null, user_agent: string|null, record_hash: string|null}>  $decisions
     */
    public function __construct(
        Model $instance,
        string $workflowSlug,
        string $recordTable,
        string $recordId,
        /** Registro-alvo (model do app), ou null se foi apagado. */
        public ?Model $record,
        public string $status,
        public ?string $nodeKey,
        public \DateTimeInterface $completedAt,
        public array $decisions,
    ) {
        parent::__construct($instance, $workflowSlug, $recordTable, $recordId);
    }

    public function approved(): bool
    {
        return $this->status === 'approved';
    }

    /** Data da última decisão (ex.: data de abertura = última aprovação). */
    public function lastDecidedAt(): ?string
    {
        $dates = array_filter(array_column($this->decisions, 'decided_at'));

        return $dates === [] ? null : max($dates);
    }
}
