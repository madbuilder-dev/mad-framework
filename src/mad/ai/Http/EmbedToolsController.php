<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Mad\Ai\McpToolCaller;

/**
 * Tools do embed em modo Mad Coding Plan — o loop de agente roda CLIENT-side
 * (iframe): o Node do builder só streama o modelo; as tools do manifest MCP
 * são buscadas AQUI e cada tool_use volta pra cá pra executar.
 *
 *   GET  /embed/v1/tools      → defs Anthropic (name/description/input_schema)
 *                               já filtradas por permissão (shouldRegister sob
 *                               o McpCurrentUser do Bearer).
 *   POST /embed/v1/tool-call  → executa UMA tool { name, args } → { ok, result, ms }.
 *
 * SEGURANÇA: mesmo trilho do chat in-process (McpToolCaller) — permissão
 * piso+matriz, row-scope, PII masking e allowlist custom acontecem DENTRO das
 * tools do manifest. Aqui só filtramos ESCRITAS: tool de escrita exige o fluxo
 * de confirm do chat local, que não existe no loop client-side → 403 (v1).
 * Auth = McpManifestAuthMiddleware (mesmo Bearer MCP do resto do /embed/v1).
 */
final class EmbedToolsController
{
    public function list(Request $request): JsonResponse
    {
        $caller = self::caller();
        if ($caller === null) {
            return response()->json(['tools' => []]);
        }

        $tools = [];
        foreach ($caller->allowedTools() as $publicName => $tool) {
            if ($caller->isWrite($publicName)) {
                continue; // v1: só leitura no loop client-side (sem confirm UI)
            }

            $schema      = $tool->schema(new JsonSchemaTypeFactory());
            $inputSchema = ['type' => 'object', 'properties' => (object) []];
            if (filled($schema)) {
                $schemaArray = (new ObjectSchema($schema))->toSchema();
                $inputSchema['properties'] = (object) ($schemaArray['properties'] ?? []);
                $inputSchema['required']   = $schemaArray['required'] ?? [];
            }

            $tools[] = [
                'name'         => $publicName,
                'description'  => (string) $tool->description(),
                'input_schema' => $inputSchema,
            ];
        }

        return response()->json(['tools' => $tools]);
    }

    public function call(Request $request): JsonResponse
    {
        $name = trim((string) $request->json('name', ''));
        $args = $request->json('args', []);
        $args = is_array($args) ? $args : [];

        if ($name === '') {
            return response()->json(['error' => 'name obrigatório.'], 422);
        }

        $caller = self::caller();
        if ($caller === null || ! $caller->has($name)) {
            return response()->json(['error' => "Tool '{$name}' indisponível ou sem permissão."], 404);
        }
        if ($caller->isWrite($name)) {
            return response()->json(['error' => 'Tool de escrita exige confirmação — indisponível no embed.'], 403);
        }

        $out = $caller->invoke($name, $args);

        return response()->json([
            'ok'     => (bool) ($out['ok'] ?? false),
            'result' => $out['result'] ?? null,
            'ms'     => (int) ($out['ms'] ?? 0),
        ]);
    }

    /** McpToolCaller sob o usuário do Bearer; null quando não há manifest. */
    private static function caller(): ?McpToolCaller
    {
        try {
            return new McpToolCaller();
        } catch (\Throwable $e) {
            \error_log('[embed-tools] manifest indisponível: ' . $e->getMessage());

            return null;
        }
    }
}
