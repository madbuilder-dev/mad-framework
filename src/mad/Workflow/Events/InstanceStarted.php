<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;

/** Registro enviado para aprovação: instância criada (e as primeiras tarefas). */
class InstanceStarted extends WorkflowEvent
{
    public function __construct(
        Model $instance,
        string $workflowSlug,
        string $recordTable,
        string $recordId,
        public ?int $requestedBy,
        /** Versão enviada (hash_column ou sha256 dos campos). */
        public ?string $recordHash,
    ) {
        parent::__construct($instance, $workflowSlug, $recordTable, $recordId);
    }
}
