<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;

/** Pendência passou do prazo e foi reatribuída (SLA). */
class TaskEscalated extends WorkflowEvent
{
    public function __construct(
        Model $instance,
        string $workflowSlug,
        string $recordTable,
        string $recordId,
        public Model $task,
        public string $nodeKey,
        public ?string $fromRole,
        public ?int $fromUser,
        public ?string $toRole,
        public ?int $toUser,
    ) {
        parent::__construct($instance, $workflowSlug, $recordTable, $recordId);
    }
}
