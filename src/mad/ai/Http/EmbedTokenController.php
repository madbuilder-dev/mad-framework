<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * GET /embed/v1/token — Embed Chat IA via Mad Coding Plan (MiniMax pela
 * franquia do DONO). Quando `mad.ai.coding_plan` está on, o app NÃO
 * streama IA: o iframe fala direto com o Node do builder. Este endpoint
 * troca, server-to-server, o MAD_PROJECT_TOKEN por um EMBED JWT curto que
 * o iframe usa no `host:init` (a pool key nunca vem pra cá nem pro
 * browser). Auth = mesmo McpManifestAuth do resto do /embed/v1.
 *
 * Precedência no embed: coding_plan > proxy (openrouter) > in-process.
 */
final class EmbedTokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! (bool) config('mad.ai.coding_plan')) {
            return response()->json(['error' => 'coding_plan_disabled'], 404);
        }

        $builderUrl = rtrim((string) config('mad.builder.url'), '/');
        $projectToken = (string) config('mad.builder.token');

        if ($builderUrl === '' || $projectToken === '') {
            return response()->json(['error' => 'builder_link_missing'], 503);
        }

        // End-user id opaco pro anti-flood/audit do Node (NUNCA billing).
        $endUserId = (string) ($request->attributes->get('mcpUserId')
            ?? optional($request->user())->id
            ?? '');

        $resp = Http::withToken($projectToken)
            ->timeout(15)
            ->post($builderUrl . '/api/embed-llm/session', array_filter([
                'end_user_id' => $endUserId !== '' ? $endUserId : null,
            ]));

        if (! $resp->successful()) {
            // Repassa o código de negócio (403 plano/quota) pro widget explicar.
            $body = $resp->json();
            return response()->json(
                is_array($body) ? $body : ['error' => 'embed_token_failed'],
                $resp->status(),
            );
        }

        $data = (array) $resp->json();

        return response()->json([
            'token' => (string) ($data['token'] ?? ''),
            'expires_in' => (int) ($data['expires_in'] ?? 900),
            // Front door do stream: o builder devolve o dele; se vier vazio,
            // usa o config local do app (dev = Node URL).
            'agent_endpoint' => (string) ($data['agent_endpoint'] ?? '')
                ?: (string) config('mad.ai.embed_stream_url', ''),
        ]);
    }
}
