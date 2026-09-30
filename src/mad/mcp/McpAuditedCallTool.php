<?php

namespace Mad\Mcp;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * McpAuditedCallTool
 *
 * Substitui o handler padrao de `tools/call` (registrado em
 * McpManifestServer::boot). Antes de delegar ao CallTool do pacote, detecta se a
 * tool existe no manifest mas foi NEGADA por permissao para o usuario atual —
 * nesse caso AUDITA a tentativa (McpAccessAudit) e devolve um erro explicito de
 * permissao (em vez do generico "Tool not found" que o filtro shouldRegister
 * produziria).
 */
final class McpAuditedCallTool implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $name = (string) ($request->get('name') ?? '');

        if ($name !== '') {
            try {
                $manifest = McpManifestLoader::load();
                $spec     = $manifest->tool($name);
                if ($spec !== null) {
                    $reason = (new McpPermissionResolver())->denyReason($manifest, $spec);
                    if ($reason !== null) {
                        McpAccessAudit::denied($name, $reason);

                        return JsonRpcResponse::error(
                            $request->id,
                            -32603,
                            "Permissao negada para a tool [{$name}] (motivo: {$reason})."
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Falha no gate de auditoria nao deve quebrar a chamada — segue p/ CallTool.
                error_log('[MCP audited-call] ' . $e->getMessage());
            }
        }

        return (new CallTool())->handle($request, $context);
    }
}
