<?php

namespace Mad\Ai\Console;

use Mad\Ai\SseSink;

/**
 * SystemToolExecutor — caminho DETERMINÍSTICO pós-aprovação (sem chamar o modelo).
 * Chamado pelo AgentConsoleController::resolveConfirm após re-checar admin.
 *
 * Orquestração: execute() do handler (que OWNS backup→mutate→verify) →
 *  - throw / ok=false             → status 'failed' (se backupRef vazio, nada mutou);
 *  - ok=true & verifyOk=false     → rollback(backupRef) → status 'rolled_back';
 *  - ok=true & verifyOk=true      → status 'ok'.
 * Finaliza a auditoria pelo confirmId e emite tool_use_start/end no SSE.
 */
final class SystemToolExecutor
{
    public function __construct(
        private readonly SystemToolRegistry $registry,
        private readonly ?ToolAuditStore $audit = null,
    ) {
    }

    public function execute(string $tool, array $args, SseSink $sink, string $confirmId = ''): ToolOutcome
    {
        $handler = $this->registry->handler($tool);
        if ($handler === null) {
            $this->audit?->finalize($confirmId, 'failed', '', ['error' => 'unknown_tool: ' . $tool]);

            return ToolOutcome::fail('Tool desconhecida: ' . $tool);
        }

        $sink->toolUseStart($tool, $args);
        $start = microtime(true);

        try {
            $outcome = $handler->execute($args, $sink);
        } catch (\Throwable $e) {
            error_log('[agent-console] execute ' . $tool . ': ' . $e->getMessage());
            $outcome = ToolOutcome::fail($e->getMessage());
        }

        $status = 'ok';
        if (! $outcome->ok) {
            // Falha antes/durante a mutação. backupRef vazio ⇒ nada foi tocado.
            $status = 'failed';
        } elseif (! $outcome->verifyOk) {
            // Mutou mas a verificação falhou → rollback do snapshot.
            $rolled  = $outcome->backupRef !== '' && $handler->rollback($outcome->backupRef);
            $status  = 'rolled_back';
            $outcome = new ToolOutcome(
                false,
                'Verificação pós-execução falhou; ' . ($rolled ? 'rollback executado.' : 'rollback indisponível/falhou.'),
                $outcome->backupRef,
                false,
                $outcome->data,
            );
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $sink->toolUseEnd($tool, $args, $ms, ['ok' => $outcome->ok, 'message' => $outcome->message, 'status' => $status], $outcome->ok);

        $this->audit?->finalize($confirmId, $status, $outcome->backupRef, ['message' => $outcome->message, 'data' => $outcome->data]);

        return $outcome;
    }
}
