<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mad\Ai\Console\ToolAuditStore;
use Mad\Ai\ConversationStore;
use Mad\Mcp\McpCurrentUser;

/**
 * AgentConsoleConversationController — histórico + auditoria + backups do Command
 * Center (admin). Espelha EmbedConversationController e adiciona a trilha de tool
 * calls (mad_ai_tool_audit) e a lista de pontos de restauração.
 *
 *   GET /agent-console/v1/conversations       → list()
 *   GET /agent-console/v1/conversations/{id}  → load()
 *   GET /agent-console/v1/audit               → audit()    timeline de tool calls
 *   GET /agent-console/v1/backups             → backups()  pontos de restauração
 *
 * Ownership por McpCurrentUser::id(); o admin-gate da rota já barrou não-admin.
 */
final class AgentConsoleConversationController
{
    public function list(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        try {
            return response()->json(['conversations' => self::store()->listByUser((int) $userId)]);
        } catch (\Throwable $e) {
            error_log('[agent-console-conv] list: ' . $e->getMessage());

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
            error_log('[agent-console-conv] load: ' . $e->getMessage());

            return response()->json(['error' => 'load_failed'], 500);
        }
    }

    /** Timeline de tool calls (params/quem/quando/status/backup_ref). */
    public function audit(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        try {
            $rows = (new ToolAuditStore(self::db()))->listByUser((int) $userId, 100);

            return response()->json(['audit' => array_map(static function (array $r): array {
                return [
                    'id'             => (int) ($r['id'] ?? 0),
                    'conversationId' => (string) ($r['conversation_id'] ?? ''),
                    'tool'           => (string) ($r['tool'] ?? ''),
                    'login'          => (string) ($r['login'] ?? ''),
                    'riskLevel'      => (string) ($r['risk_level'] ?? ''),
                    'requiresBackup' => ! empty($r['requires_backup']),
                    'confirmId'      => (string) ($r['confirm_id'] ?? ''),
                    'confirmed'      => ! empty($r['confirmed']),
                    'confirmedAt'    => (string) ($r['confirmed_at'] ?? ''),
                    'backupRef'      => (string) ($r['backup_ref'] ?? ''),
                    'status'         => (string) ($r['status'] ?? ''),
                    'params'         => json_decode((string) ($r['params_json'] ?? '[]'), true),
                    'result'         => json_decode((string) ($r['result_json'] ?? 'null'), true),
                    'createdAt'      => (string) ($r['created_at'] ?? ''),
                ];
            }, $rows)]);
        } catch (\Throwable $e) {
            error_log('[agent-console-conv] audit: ' . $e->getMessage());

            return response()->json(['audit' => []]);
        }
    }

    /** Pontos de restauração: dumps de banco, snapshots de código e batches de código. */
    public function backups(Request $request): JsonResponse
    {
        $out = [];

        foreach (['db' => 'app/backup/agent/db', 'code' => 'app/backup/agent/code'] as $kind => $rel) {
            $dir = base_path($rel);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
                $path = $dir . '/' . $name;
                if (! is_file($path)) {
                    continue;
                }
                $out[] = [
                    'kind'      => $kind,
                    'ref'       => $rel . '/' . $name,
                    'label'     => $name,
                    'sizeBytes' => (int) (@filesize($path) ?: 0),
                    'createdAt' => date('Y-m-d H:i:s', (int) (@filemtime($path) ?: 0)),
                    'restorable' => $kind === 'db' || $kind === 'code',
                ];
            }
        }

        // Batches de código (BuilderCodeSyncService) — reversíveis por batch id.
        try {
            foreach (\App\Service\Builder\BuilderCodeSyncService::loadBatches() as $b) {
                $out[] = [
                    'kind'       => 'code_batch',
                    'ref'        => (string) ($b['batch_id'] ?? ''),
                    'label'      => 'Batch ' . (string) ($b['batch_id'] ?? ''),
                    'fileCount'  => (int) ($b['file_count'] ?? 0),
                    'status'     => (string) ($b['status'] ?? ''),
                    'createdAt'  => (string) ($b['ts'] ?? ''),
                    'restorable' => (string) ($b['status'] ?? '') === 'applied',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[agent-console-conv] backups batches: ' . $e->getMessage());
        }

        usort($out, static fn ($a, $b) => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));

        return response()->json(['backups' => $out]);
    }

    private static function store(): ConversationStore
    {
        return new ConversationStore(self::db());
    }

    private static function db(): string
    {
        return (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));
    }
}
