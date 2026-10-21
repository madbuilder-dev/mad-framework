<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;

/** Lembrete de prazo enviado (count = quantos lembretes a pendência já recebeu). */
class TaskReminded extends WorkflowEvent
{
    public function __construct(
        Model $instance,
        string $workflowSlug,
        string $recordTable,
        string $recordId,
        public Model $task,
        public string $nodeKey,
        public int $count,
    ) {
        parent::__construct($instance, $workflowSlug, $recordTable, $recordId);
    }
}
