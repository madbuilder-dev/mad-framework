<?php

namespace Mad\Ai\Console;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\ConfirmCoordinator;
use Mad\Ai\JsonSchemaBuilder;
use Mad\Ai\SseSink;

/**
 * SystemTool — adapta um SystemToolHandler (operação de backend) para o loop do
 * laravel/ai. Espelho do McpDataTool, mas para tools que MUTAM o sistema.
 *
 * Self-gate: se requiresConfirmation, NUNCA executa — emite o bloco `confirm`
 * (com risco + preview/diff) e registra a pendência {tool, args}. A execução real
 * é determinística no turno seguinte, via SystemToolExecutor (AgentConsoleController),
 * com re-check de admin. Tools seguras (backups) executam direto aqui.
 */
final class SystemTool implements Tool
{
    public function __construct(
        private readonly SystemToolHandler $handler,
        private readonly SseSink $sink,
        private readonly ConfirmCoordinator $confirms,
        private readonly ?ToolAuditStore $audit = null,
        private readonly string $conversationId = '',
        private readonly int $userId = 0,
        private readonly string $login = '',
    ) {
    }

    public function name(): string
    {
        return $this->handler->name();
    }

    public function description(): string
    {
        $desc = $this->handler->description();
        if ($this->handler->risk()->requiresConfirmation) {
            $desc .= ' [REQUER CONFIRMAÇÃO — ao chamar, um cartão é exibido e a ação só roda após o usuário aprovar.]';
        }

        return $desc;
    }

    public function schema(JsonSchema $schema): array
    {
        return JsonSchemaBuilder::properties($schema, $this->handler->schemaSpec());
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $risk = $this->handler->risk();

        // Tool segura (backups): executa agora, audita 1 linha.
        if (! $risk->requiresConfirmation) {
            $this->sink->toolUseStart($this->handler->name(), $args);
            $start = microtime(true);
            try {
                $outcome = $this->handler->execute($args, $this->sink);
            } catch (\Throwable $e) {
                error_log('[agent-console] safe ' . $this->handler->name() . ': ' . $e->getMessage());
                $outcome = ToolOutcome::fail($e->getMessage());
            }
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->sink->toolUseEnd($this->handler->name(), $args, $ms, ['ok' => $outcome->ok, 'message' => $outcome->message], $outcome->ok);
            $this->audit?->record([
                'conversation_id' => $this->conversationId,
                'user_id'         => $this->userId,
                'login'           => $this->login,
                'tool'            => $this->handler->name(),
                'params'          => $args,
                'risk_level'      => $risk->level,
                'requires_backup' => $risk->requiresBackup,
                'confirm_id'      => '',
                'confirmed'       => true,
                'backup_ref'      => $outcome->backupRef,
                'status'          => $outcome->ok ? 'ok' : 'failed',
                'result'          => ['message' => $outcome->message, 'data' => $outcome->data],
            ]);

            return ($outcome->ok ? 'OK: ' : 'Falha: ') . $outcome->message;
        }

        // Tool gated: emite confirm + stash + auditoria pendente.
        $id      = $this->confirms->newId();
        $preview = $this->handler->preview($args);
        $this->confirms->stash($id, $this->handler->name(), $args);

        $block = array_merge([
            'type'   => 'confirm',
            'id'     => $id,
            'tool'   => $this->handler->name(),
            'title'  => 'Confirmar ação: ' . $this->handler->name(),
            'danger' => $risk->isDanger(),
            'fields' => $preview['fields'] ?? [],
        ], $risk->toArray());

        if (isset($preview['diff'])) {
            $block['diff'] = $preview['diff'];
        }
        if ($risk->requiresBackup) {
            $block['backup'] = ['willBackup' => true];
        }
        if ($risk->level === 'critical') {
            $block['requireTypedConfirm'] = true;
        }

        $this->audit?->record([
            'conversation_id' => $this->conversationId,
            'user_id'         => $this->userId,
            'login'           => $this->login,
            'tool'            => $this->handler->name(),
            'params'          => $args,
            'risk_level'      => $risk->level,
            'requires_backup' => $risk->requiresBackup,
            'confirm_id'      => $id,
            'confirmed'       => false,
            'backup_ref'      => '',
            'status'          => 'pending',
            'result'          => null,
        ]);

        $this->sink->block($block);

        return 'Cartão de confirmação exibido ao usuário. NÃO execute a ação agora — aguarde a aprovação.';
    }
}
