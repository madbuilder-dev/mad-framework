<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;
use Mad\Database\TenantContext;
use PDO;

/**
 * ConversationStore — histórico + pendências de confirmação por CONVERSA.
 *
 * Multi-conversa por usuário: cada conversa é uma linha em mad_ai_conversation,
 * identificada por id (uuid do cliente; ou hash(bearer) como conversa default)
 * e escopada por user_id. Toda leitura/escrita filtra (id, user) — um
 * usuário nunca lê/escreve conversa de outro.
 *
 * Port do antigo trocando a transação legada por PDO da conexão nativa (DB facade).
 * Schema self-contained via EmbedSchema (padrão do repo — migrator legado
 * morto). Tudo fail-safe: erro nunca quebra o chat.
 */
final class ConversationStore
{
    private const MAX_MESSAGES = 20;

    /** Entradas do transcript rico (pares user/agent) — 2x o cap de mensagens. */
    private const MAX_TRANSCRIPT = 40;

    public function __construct(private string $db)
    {
    }

    private function pdo(): PDO
    {
        EmbedSchema::ensure($this->db);

        return DB::connection($this->db)->getPdo();
    }

    /**
     * @return array{messages: list<array{role: string, content: string}>, pending: array<string, mixed>, transcript: list<array<string, mixed>>, found: bool, title: string}
     */
    public function load(string $conversationId, int $userId): array
    {
        $out = ['messages' => [], 'pending' => [], 'transcript' => [], 'found' => false, 'title' => ''];

        try {
            // Escopo de TENANT: além do user, a leitura filtra pelo tenant atual
            // (OR tenant_id IS NULL tolera linhas legadas sem tenant). Impede um
            // usuário multi-empresa de ler conversa de outro tenant.
            $st = $this->pdo()->prepare(
                'SELECT messages_json, pending_json, transcript_json, title FROM mad_ai_conversation WHERE id = ? AND user_id = ? AND (tenant_id = ? OR tenant_id IS NULL)'
            );
            $st->execute([$conversationId, $userId, TenantContext::id()]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $m = json_decode((string) ($row['messages_json'] ?? '[]'), true);
                $p = json_decode((string) ($row['pending_json'] ?? '{}'), true);
                $t = json_decode((string) ($row['transcript_json'] ?? '[]'), true);
                $out['messages']   = is_array($m) ? $m : [];
                $out['pending']    = is_array($p) ? $p : [];
                $out['transcript'] = is_array($t) ? $t : [];
                $out['title']      = (string) ($row['title'] ?? '');
                $out['found']      = true;
            }
        } catch (\Throwable $e) {
            error_log('[embed-store] load: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Upsert da conversa. No insert deriva o título da 1ª mensagem do usuário;
     * no update preserva (salvo $title explícito).
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                       $pending
     * @param list<array<string, mixed>>|null            $transcript null = não toca na coluna
     */
    public function save(string $conversationId, int $userId, array $messages, array $pending, ?string $title = null, ?array $transcript = null): void
    {
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }
        if ($transcript !== null && count($transcript) > self::MAX_TRANSCRIPT) {
            $transcript = array_slice($transcript, -self::MAX_TRANSCRIPT);
            // Alinha o corte no início de um turno do USUÁRIO — sem resposta
            // órfã no topo do histórico.
            foreach ($transcript as $i => $e) {
                if (($e['role'] ?? '') === 'user') {
                    if ($i > 0 && $i <= 4) {
                        $transcript = array_slice($transcript, $i);
                    }
                    break;
                }
            }
        }

        try {
            $conn = $this->pdo();

            $mj  = json_encode(array_values($messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pj  = json_encode((object) $pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tj  = $transcript !== null
                ? json_encode(array_values($transcript), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $now = date('Y-m-d H:i:s');

            $tid = TenantContext::id();
            // Existência escopada por tenant (mesmo predicado das leituras): sem
            // isto, uma conversa de OUTRO tenant com o mesmo id cairia no ramo de
            // UPDATE e seria sobrescrita em vez de criar a linha do tenant atual.
            $exists = $conn->prepare('SELECT 1 FROM mad_ai_conversation WHERE id = ? AND user_id = ? AND (tenant_id = ? OR tenant_id IS NULL)');
            $exists->execute([$conversationId, $userId, $tid]);

            if ($exists->fetchColumn() !== false) {
                $sets   = ['messages_json = ?', 'pending_json = ?'];
                $params = [$mj, $pj];
                if ($tj !== null) {
                    $sets[]   = 'transcript_json = ?';
                    $params[] = $tj;
                }
                if ($title !== null && $title !== '') {
                    $sets[]   = 'title = ?';
                    $params[] = $title;
                }
                $sets[]   = 'updated_at = ?';
                $params[] = $now;
                array_push($params, $conversationId, $userId);

                $conn->prepare(
                    'UPDATE mad_ai_conversation SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
                )->execute($params);
            } else {
                $resolvedTitle = ($title !== null && $title !== '') ? $title : self::deriveTitle($messages);
                $conn->prepare(
                    'INSERT INTO mad_ai_conversation (id, user_id, title, messages_json, pending_json, dashboards_json, transcript_json, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$conversationId, $userId, $resolvedTitle, $mj, $pj, '{}', $tj ?? '[]', $now, $now, $tid]);
            }
        } catch (\Throwable $e) {
            error_log('[embed-store] save: ' . $e->getMessage());
        }
    }

    /**
     * Conversas do usuário (mais recentes primeiro) — só metadados.
     *
     * @return list<array{id: string, title: string, updated_at: string, count: int}>
     */
    public function listByUser(int $userId, int $limit = 50): array
    {
        $rows  = [];
        $limit = max(1, min(200, $limit));

        try {
            // Escopo de TENANT (OR tenant_id IS NULL tolera linhas legadas).
            $st = $this->pdo()->prepare(
                'SELECT id, title, updated_at, messages_json FROM mad_ai_conversation WHERE user_id = ? AND (tenant_id = ? OR tenant_id IS NULL) ORDER BY updated_at DESC LIMIT ' . $limit
            );
            $st->execute([$userId, TenantContext::id()]);

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $m = json_decode((string) ($r['messages_json'] ?? '[]'), true);
                $rows[] = [
                    'id'         => (string) $r['id'],
                    'title'      => (string) ($r['title'] ?? ''),
                    'updated_at' => (string) ($r['updated_at'] ?? ''),
                    'count'      => is_array($m) ? count($m) : 0,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[embed-store] list: ' . $e->getMessage());
        }

        return $rows;
    }

    /**
     * @param list<array{role?: string, content?: string}> $messages
     */
    private static function deriveTitle(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $text = trim((string) ($m['content'] ?? ''));
                if ($text !== '') {
                    return mb_strimwidth($text, 0, 80, '…');
                }
            }
        }

        return 'Nova conversa';
    }
}
