<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mad\Ai\EmbedSchema;
use Mad\Mcp\McpCurrentUser;
use Mad\Mcp\McpManifestLoader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EmbedFavoriteController — favoritos do chat embed (F3.2).
 *
 * O widget salva um BLOCO renderizado (kpis/donut/table/…) com título e
 * observação; a tabela é a `mad_ai_favorite` (shape da migration: note,
 * block_type, snapshot_json, source_json, spec_json, spec_status). Escopo:
 * SEMPRE do usuário do Bearer (+ tenant da sessão, que o middleware já
 * restaurou do contexto do token) — um favorito nunca vaza entre usuários
 * nem entre empresas.
 *
 *   GET    /embed/v1/favorites           lista (metadados p/ o painel)
 *   POST   /embed/v1/favorites           salva {title, note?, block, conversationId?}
 *   DELETE /embed/v1/favorites/{id}      remove (só o dono)
 *   POST   /embed/v1/favorites/{id}/run  replay SSE do bloco salvo (sem LLM)
 *
 * v1: replay = SNAPSHOT (re-emite o bloco como salvo; specStatus 'snapshot').
 * Replay VIVO (re-rodar o `source` {tool,args} e reconstruir o bloco) fica
 * pro slice em que o builder de blocos for reutilizável fora do turno.
 */
final class EmbedFavoriteController
{
    public function list(Request $request): JsonResponse
    {
        $rows = $this->query()
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'title', 'note', 'block_type', 'spec_status', 'updated_at']);

        $favorites = [];
        foreach ($rows as $r) {
            $favorites[] = [
                'id'         => (string) $r->id,
                'title'      => (string) $r->title,
                'note'       => (string) ($r->note ?? '') ?: null,
                'blockType'  => (string) $r->block_type,
                'specStatus' => (string) ($r->spec_status ?: 'snapshot'),
                'updatedAt'  => (string) $r->updated_at,
            ];
        }

        return response()->json(['favorites' => $favorites]);
    }

    public function save(Request $request): JsonResponse
    {
        $title = trim((string) $request->json('title', ''));
        $block = $request->json('block');
        if ($title === '' || ! is_array($block) || empty($block['type'])) {
            return response()->json(['error' => 'title e block são obrigatórios.'], 422);
        }

        $db = $this->db();
        EmbedSchema::ensure($db);

        $id  = 'fav_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');

        // `source` {tool, args} vem anexado ao bloco pelo runtime do widget —
        // guardado à parte (source_json) p/ o replay VIVO futuro.
        $source = is_array($block['source'] ?? null) ? $block['source'] : null;

        DB::connection($db)->table('mad_ai_favorite')->insert([
            'id'            => $id,
            'user_id'       => (int) McpCurrentUser::id(),
            'title'         => mb_substr($title, 0, 190),
            'note'          => mb_substr(trim((string) $request->json('note', '')), 0, 500),
            'block_type'    => mb_substr((string) $block['type'], 0, 24),
            'snapshot_json' => json_encode($block, JSON_UNESCAPED_UNICODE),
            'source_json'   => $source !== null ? json_encode($source, JSON_UNESCAPED_UNICODE) : null,
            'spec_json'     => null,
            'spec_status'   => 'snapshot',
            'created_at'    => $now,
            'updated_at'    => $now,
            'tenant_id'     => ($t = session('tenant_id')) !== null && $t !== '' ? (int) $t : null,
        ]);

        return response()->json(['id' => $id, 'specStatus' => 'snapshot']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $this->query()->where('id', $id)->delete();

        return $deleted > 0
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'Favorito não encontrado.'], 404);
    }

    /** Replay determinístico: re-emite o bloco salvo no MESMO shape SSE do chat. */
    public function run(Request $request, string $id): StreamedResponse
    {
        $row = $this->query()->where('id', $id)->first(['snapshot_json']);

        return new StreamedResponse(function () use ($row): void {
            $emit = static function (string $type, array $data): void {
                echo 'data: ' . json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                @flush();
            };

            $block = $row !== null ? json_decode((string) $row->snapshot_json, true) : null;
            $block = is_array($block) ? $block : null;

            if ($block === null) {
                $emit('error', ['message' => 'Favorito não encontrado.']);
            } else {
                $emit('block', $block);
            }
            $emit('message_end', []);
            echo "data: [DONE]\n\n";
            @flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Base query SEMPRE escopada por dono (user do token + tenant da sessão). */
    private function query(): \Illuminate\Database\Query\Builder
    {
        $db = $this->db();
        EmbedSchema::ensure($db);

        $q = DB::connection($db)->table('mad_ai_favorite')
            ->where('user_id', (int) McpCurrentUser::id());

        $tenant = session('tenant_id');
        if ($tenant !== null && $tenant !== '') {
            $q->where('tenant_id', (int) $tenant);
        }

        return $q;
    }

    /** Mesma resolução de conexão do EmbedChatController (manifest > config). */
    private function db(): string
    {
        try {
            return McpManifestLoader::load()->database();
        } catch (\Throwable) {
            return (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));
        }
    }
}
