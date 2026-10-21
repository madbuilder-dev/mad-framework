<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;

/** Pendência criada para um perfil ou um usuário (um evento por responsável). */
class TaskCreated extends WorkflowEvent
{
    public function __construct(
        Model $instance,
        string $workflowSlug,
        string $recordTable,
        string $recordId,
        /** mad_wf_task (App\Models\Wf\Task) */
        public Model $task,
        public string $nodeKey,
        public ?string $assignedRole,
        public ?int $assignedUser,
        public ?\DateTimeInterface $dueAt,
    ) {
        parent::__construct($instance, $workflowSlug, $recordTable, $recordId);
    }
}
