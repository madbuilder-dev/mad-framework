<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mad\Ai\ConversationStore;
use Mad\Mcp\McpCurrentUser;
use Mad\Mcp\McpManifestLoader;

/**
 * EmbedConversationController — histórico de conversas do chat embed.
 *
 *   GET /embed/v1/conversations       → list()  conversas do usuário
 *   GET /embed/v1/conversations/{id}  → load()  mensagens de uma conversa
 *
 * Ownership: tudo escopado por McpCurrentUser::id() (preenchido pela auth).
 * load filtra (id, user_id) — conversa de outro usuário = 404.
 */
final class EmbedConversationController
{
    public function list(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        try {
            $rows = self::store()->listByUser((int) $userId);

            return response()->json(['conversations' => $rows]);
        } catch (\Throwable $e) {
            error_log('[embed-conv] list: ' . $e->getMessage());

            return response()->json(['conversations' => []]);
        }
    }

    public function load(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $id = trim($id);
        if ($id === '') {
            return response()->json(['error' => 'missing_id'], 400);
        }

        try {
            $conv = self::store()->load($id, (int) $userId);

            if (! $conv['found']) {
                return response()->json(['error' => 'not_found'], 404);
            }

            return response()->json([
                'id'         => $id,
                'title'      => $conv['title'],
                'messages'   => array_values($conv['messages']),
                'pending'    => (object) $conv['pending'],
                'transcript' => array_values($conv['transcript']),
            ]);
        } catch (\Throwable $e) {
            error_log('[embed-conv] load: ' . $e->getMessage());

            return response()->json(['error' => 'load_failed'], 500);
        }
    }

    private static function store(): ConversationStore
    {
        try {
            $db = McpManifestLoader::load()->database();
        } catch (\Throwable) {
            $db = (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));
        }

        return new ConversationStore($db);
    }
}
