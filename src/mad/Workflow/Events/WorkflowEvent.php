<?php

declare(strict_types=1);

namespace Mad\Workflow\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * Base dos eventos do motor de workflow — disparados SEMPRE depois do commit
 * da decisão (rollback nunca dispara; listener que lança exceção é logado e
 * não desfaz nada).
 *
 * O app ouve criando um listener em `app/Listeners` (code_create
 * kind=php-listener) com o `handle()` tipado no evento — o Laravel acha
 * sozinho, sem editar provider:
 *
 *   class MoverProjetoAoAprovar
 *   {
 *       public function handle(\Mad\Workflow\Events\InstanceCompleted $e): void
 *       {
 *           if ($e->workflowSlug === 'aprovacao_rap' && $e->status === 'approved') { ... }
 *       }
 *   }
 */
// Propriedades públicas sem readonly: SerializesModels repõe os models ao
// tirar o evento da fila (listener ShouldQueue), o que readonly impediria.
abstract class WorkflowEvent
{
    use SerializesModels;

    public function __construct(
        /** mad_wf_instance (App\Models\Wf\Instance) */
        public Model $instance,
        public string $workflowSlug,
        public string $recordTable,
        public string $recordId,
    ) {}
}
