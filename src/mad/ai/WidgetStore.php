<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;
use Mad\Database\TenantContext;
use PDO;

/**
 * WidgetStore — widgets de BI salvos pelo agente (mad_ai_widget).
 *
 * Cada widget é uma estrutura determinística {type, sql, map, style} do
 * WidgetBlockBuilder, privada por usuário (+ tenant, padrão ConversationStore).
 * Fail-safe: erro loga e devolve vazio/false — nunca derruba o chat/tela.
 */
final class WidgetStore
{
    public function __construct(private string $db)
    {
    }

    private function pdo(): PDO
    {
        EmbedSchema::ensure($this->db);

        return DB::connection($this->db)->getPdo();
    }

    /** Predicado de tenant compartilhado (linhas legadas sem tenant contam). */
    private const TENANT_SQL = '(tenant_id = ? OR tenant_id IS NULL)';

    public static function newId(): string
    {
        return 'wid-' . substr(md5(uniqid('', true)), 0, 12);
    }

    /**
     * Upsert. $id vazio = cria (retorna o id novo); com $id, só o dono altera.
     *
     * @param array<string, mixed> $spec
     */
    public function save(string $id, int $userId, string $title, array $spec): ?string
    {
        try {
            $conn = $this->pdo();
            $now  = date('Y-m-d H:i:s');
            $sj   = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $tid  = TenantContext::id();

            if ($id !== '') {
                $st = $conn->prepare('SELECT 1 FROM mad_ai_widget WHERE id = ? AND user_id = ? AND ' . self::TENANT_SQL);
                $st->execute([$id, $userId, $tid]);
                if ($st->fetchColumn() !== false) {
                    $conn->prepare('UPDATE mad_ai_widget SET title = ?, spec_json = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                        ->execute([$title, $sj, $now, $id, $userId]);

                    return $id;
                }
            }

            $id = self::newId();
            $conn->prepare('INSERT INTO mad_ai_widget (id, user_id, title, spec_json, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $userId, $title, $sj, $now, $now, $tid]);

            return $id;
        } catch (\Throwable $e) {
            error_log('[widget-store] save: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Widgets do usuário — metadados (sem SQL, o front não precisa).
     *
     * @return list<array{id: string, title: string, type: string, updatedAt: string}>
     */
    public function listByUser(int $userId, int $limit = 200): array
    {
        $out   = [];
        $limit = max(1, min(500, $limit));

        try {
            $st = $this->pdo()->prepare(
                'SELECT id, title, spec_json, updated_at FROM mad_ai_widget WHERE user_id = ? AND ' . self::TENANT_SQL
                . ' ORDER BY updated_at DESC LIMIT ' . $limit
            );
            $st->execute([$userId, TenantContext::id()]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $spec  = json_decode((string) ($r['spec_json'] ?? '{}'), true);
                $out[] = [
                    'id'        => (string) $r['id'],
                    'title'     => (string) ($r['title'] ?? ''),
                    'type'      => is_array($spec) ? (string) ($spec['type'] ?? '') : '',
                    'updatedAt' => (string) ($r['updated_at'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[widget-store] list: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Widget do dono (spec completo).
     *
     * @return array{id: string, title: string, spec: array<string, mixed>, updatedAt: string}|null
     */
    public function get(string $id, int $userId): ?array
    {
        return $this->fetchOne('SELECT id, title, spec_json, updated_at FROM mad_ai_widget WHERE id = ? AND user_id = ? AND ' . self::TENANT_SQL, [$id, $userId, TenantContext::id()]);
    }

    /**
     * Widget SEM checagem de dono — usado só depois do controller validar que
     * ele pertence a um dashboard compartilhado com o viewer.
     *
     * @return array{id: string, title: string, spec: array<string, mixed>, updatedAt: string}|null
     */
    public function getAny(string $id): ?array
    {
        return $this->fetchOne('SELECT id, title, spec_json, updated_at FROM mad_ai_widget WHERE id = ? AND ' . self::TENANT_SQL, [$id, TenantContext::id()]);
    }

    public function rename(string $id, int $userId, string $title): bool
    {
        try {
            $st = $this->pdo()->prepare('UPDATE mad_ai_widget SET title = ?, updated_at = ? WHERE id = ? AND user_id = ? AND ' . self::TENANT_SQL);
            $st->execute([$title, date('Y-m-d H:i:s'), $id, $userId, TenantContext::id()]);

            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[widget-store] rename: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Grava (ou remove, com null) o override de exibição em spec_json.display
     * — só o dono. Resto do spec (type/sql/map/style) fica intacto.
     *
     * @param array<string, mixed>|null $display já sanitizado (WidgetDisplay::sanitize)
     */
    public function setDisplay(string $id, int $userId, ?array $display): bool
    {
        try {
            $widget = $this->get($id, $userId);
            if ($widget === null) {
                return false;
            }

            $spec = $widget['spec'];
            if ($display === null) {
                unset($spec['display']);
            } else {
                $spec['display'] = $display;
            }

            $sj = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $st = $this->pdo()->prepare('UPDATE mad_ai_widget SET spec_json = ?, updated_at = ? WHERE id = ? AND user_id = ? AND ' . self::TENANT_SQL);
            $st->execute([$sj, date('Y-m-d H:i:s'), $id, $userId, TenantContext::id()]);

            return true;
        } catch (\Throwable $e) {
            error_log('[widget-store] setDisplay: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Grava campos pontuais do spec (ex.: style, params) preservando o resto
     * (type/sql/map/source/display intactos) — só o dono. Valor null remove a
     * chave; lista/array substitui por inteiro (sanitização é do chamador).
     *
     * @param array<string, mixed> $fields chave do spec => valor novo (ou null)
     */
    public function setSpecFields(string $id, int $userId, array $fields): bool
    {
        try {
            $widget = $this->get($id, $userId);
            if ($widget === null) {
                return false;
            }

            $spec = $widget['spec'];
            foreach ($fields as $key => $value) {
                if (! in_array($key, ['style', 'params'], true)) {
                    continue;
                }
                if ($value === null || $value === []) {
                    unset($spec[$key]);
                } else {
                    $spec[$key] = $value;
                }
            }

            $sj = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $st = $this->pdo()->prepare('UPDATE mad_ai_widget SET spec_json = ?, updated_at = ? WHERE id = ? AND user_id = ? AND ' . self::TENANT_SQL);
            $st->execute([$sj, date('Y-m-d H:i:s'), $id, $userId, TenantContext::id()]);

            return true;
        } catch (\Throwable $e) {
            error_log('[widget-store] setSpecFields: ' . $e->getMessage());

            return false;
        }
    }

    public function delete(string $id, int $userId): bool
    {
        try {
            $st = $this->pdo()->prepare('DELETE FROM mad_ai_widget WHERE id = ? AND user_id = ? AND ' . self::TENANT_SQL);
            $st->execute([$id, $userId, TenantContext::id()]);

            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[widget-store] delete: ' . $e->getMessage());

            return false;
        }
    }

    /** @return array{id: string, title: string, spec: array<string, mixed>, updatedAt: string}|null */
    private function fetchOne(string $sql, array $params): ?array
    {
        try {
            $st = $this->pdo()->prepare($sql);
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (! $r) {
                return null;
            }
            $spec = json_decode((string) ($r['spec_json'] ?? '{}'), true);

            return [
                'id'        => (string) $r['id'],
                'title'     => (string) ($r['title'] ?? ''),
                'spec'      => is_array($spec) ? $spec : [],
                'updatedAt' => (string) ($r['updated_at'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log('[widget-store] get: ' . $e->getMessage());

            return null;
        }
    }
}
