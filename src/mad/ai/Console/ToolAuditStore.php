<?php

namespace Mad\Ai\Console;

use Illuminate\Support\Facades\DB;
use Mad\Ai\EmbedSchema;

/**
 * ToolAuditStore — trilha de auditoria de cada system tool call (params, quem
 * aprovou/quando, backup_ref, status, resultado). Tabela mad_ai_tool_audit
 * (EmbedSchema). Fail-safe: erro NUNCA quebra o chat (try/catch + log).
 *
 * Ciclo: record(...) no confirm (status 'pending') → finalize(confirmId, ...)
 * pós-execução (ok|failed|rolled_back|cancelled). id portável (MAX(id)+1) —
 * roda em pgsql/mysql/sqlite (sem AUTOINCREMENT).
 */
final class ToolAuditStore
{
    public function __construct(private readonly string $db)
    {
    }

    /**
     * Insere uma linha de auditoria. Espera as chaves: conversation_id, user_id,
     * login, tool, params (array), risk_level, requires_backup (bool),
     * confirm_id, status, backup_ref, result (array|null).
     *
     * @param array<string,mixed> $row
     */
    public function record(array $row): void
    {
        try {
            EmbedSchema::ensure($this->db);
            $pdo = DB::connection($this->db)->getPdo();

            $id = (int) ($pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM mad_ai_tool_audit')->fetchColumn() ?: 1);

            $stmt = $pdo->prepare(
                'INSERT INTO mad_ai_tool_audit'
                . ' (id, conversation_id, user_id, login, tool, params_json, risk_level,'
                . '  requires_backup, confirm_id, confirmed, confirmed_at, backup_ref, status, result_json, created_at)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                $id,
                (string) ($row['conversation_id'] ?? ''),
                (int) ($row['user_id'] ?? 0),
                (string) ($row['login'] ?? ''),
                (string) ($row['tool'] ?? ''),
                json_encode($row['params'] ?? [], JSON_UNESCAPED_UNICODE),
                (string) ($row['risk_level'] ?? ''),
                ! empty($row['requires_backup']) ? 1 : 0,
                (string) ($row['confirm_id'] ?? ''),
                ! empty($row['confirmed']) ? 1 : 0,
                ! empty($row['confirmed']) ? $now : null,
                (string) ($row['backup_ref'] ?? ''),
                (string) ($row['status'] ?? 'pending'),
                isset($row['result']) ? json_encode($row['result'], JSON_UNESCAPED_UNICODE) : null,
                $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[agent-console-audit] record: ' . $e->getMessage());
        }
    }

    /** Atualiza a linha do confirm com o desfecho determinístico. @param array<string,mixed> $result */
    public function finalize(string $confirmId, string $status, string $backupRef, array $result, bool $confirmed = true): void
    {
        if ($confirmId === '') {
            return;
        }
        try {
            EmbedSchema::ensure($this->db);
            $pdo = DB::connection($this->db)->getPdo();
            $stmt = $pdo->prepare(
                'UPDATE mad_ai_tool_audit SET status = ?, backup_ref = ?, confirmed = ?, confirmed_at = ?,'
                . ' result_json = ? WHERE confirm_id = ?'
            );
            $stmt->execute([
                $status,
                $backupRef,
                $confirmed ? 1 : 0,
                $confirmed ? date('Y-m-d H:i:s') : null,
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $confirmId,
            ]);
        } catch (\Throwable $e) {
            error_log('[agent-console-audit] finalize: ' . $e->getMessage());
        }
    }

    /**
     * Lista a auditoria do usuário (timeline do Command Center), mais recente 1º.
     *
     * @return list<array<string,mixed>>
     */
    public function listByUser(int $userId, int $limit = 100): array
    {
        try {
            EmbedSchema::ensure($this->db);
            $pdo = DB::connection($this->db)->getPdo();
            $stmt = $pdo->prepare(
                'SELECT id, conversation_id, login, tool, params_json, risk_level, requires_backup,'
                . ' confirm_id, confirmed, confirmed_at, backup_ref, status, result_json, created_at'
                . ' FROM mad_ai_tool_audit WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit
            );
            $stmt->execute([$userId]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[agent-console-audit] listByUser: ' . $e->getMessage());

            return [];
        }
    }
}
