<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;

/** Uma pendência foi decidida (o passo pode seguir aberto esperando os demais). */
class TaskDecided extends WorkflowEvent
{
    public function __construct(
        Model $instance,
        string $workflowSlug,
        string $recordTable,
        string $recordId,
        public Model $task,
        public string $nodeKey,
        /** Saída escolhida (v2: chave do outcome; legado: approved|rejected|returned). */
        public string $outcome,
        public ?int $actorId,
        /** Titular em nome de quem o substituto decidiu (null = o próprio). */
        public ?int $onBehalfOf,
        public ?string $comment,
        public ?string $ip,
        public ?string $recordHash,
    ) {
        parent::__construct($instance, $workflowSlug, $recordTable, $recordId);
    }
}
