<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;
use Mad\Database\TenantContext;
use PDO;

/**
 * DashboardStore — dashboards do usuário final (mad_ai_dashboard) +
 * compartilhamento por usuário/grupo (mad_ai_dashboard_share).
 *
 * layout_json = lista [{widgetId, x, y, w, h}] no grid de 12 colunas do viewer.
 * Dono edita; destinatário de share só visualiza (canEdit=false). is_home é
 * exclusivo por usuário (setHome zera os demais). Fail-safe como os stores
 * irmãos.
 */
final class DashboardStore
{
    public function __construct(private string $db)
    {
    }

    private function pdo(): PDO
    {
        EmbedSchema::ensure($this->db);

        return DB::connection($this->db)->getPdo();
    }

    private const TENANT_SQL = '(d.tenant_id = ? OR d.tenant_id IS NULL)';

    public static function newId(): string
    {
        return 'dash-' . substr(md5(uniqid('', true)), 0, 12);
    }

    /** @param list<array{widgetId: string, x: int, y: int, w: int, h: int}> $layout */
    public function create(int $userId, string $title, array $layout = []): ?string
    {
        try {
            $id  = self::newId();
            $now = date('Y-m-d H:i:s');
            $this->pdo()->prepare(
                'INSERT INTO mad_ai_dashboard (id, user_id, title, layout_json, is_home, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
            )->execute([
                $id,
                $userId,
                $title !== '' ? $title : 'Novo dashboard',
                json_encode(self::sanitizeLayout($layout), JSON_UNESCAPED_UNICODE) ?: '[]',
                $now,
                $now,
                TenantContext::id(),
            ]);

            return $id;
        } catch (\Throwable $e) {
            error_log('[dash-store] create: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Dashboards visíveis: meus + compartilhados comigo (user/grupo).
     *
     * @param  list<int|string> $groupIds
     * @return list<array{id: string, title: string, isHome: bool, shared: bool, canEdit: bool, updatedAt: string}>
     */
    public function listVisible(int $userId, array $groupIds): array
    {
        $out = [];

        try {
            $tid = TenantContext::id();

            $st = $this->pdo()->prepare(
                'SELECT d.id, d.title, d.is_home, d.user_id, d.updated_at FROM mad_ai_dashboard d WHERE d.user_id = ? AND ' . self::TENANT_SQL . ' ORDER BY d.updated_at DESC'
            );
            $st->execute([$userId, $tid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[] = self::rowMeta($r, false, true);
            }

            // compartilhados comigo (user OU um dos meus grupos), sem duplicar os meus
            $groupIds = array_values(array_map('intval', $groupIds));
            $inGroups = $groupIds !== [] ? ' OR (s.kind = \'group\' AND s.ref_id IN (' . implode(',', $groupIds) . '))' : '';
            $st = $this->pdo()->prepare(
                'SELECT DISTINCT d.id, d.title, d.is_home, d.user_id, d.updated_at FROM mad_ai_dashboard d'
                . ' JOIN mad_ai_dashboard_share s ON s.dashboard_id = d.id'
                . ' WHERE d.user_id <> ? AND ' . self::TENANT_SQL
                . ' AND ((s.kind = \'user\' AND s.ref_id = ?)' . $inGroups . ')'
                . ' ORDER BY d.updated_at DESC'
            );
            $st->execute([$userId, $tid, $userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[] = self::rowMeta($r, true, false);
            }
        } catch (\Throwable $e) {
            error_log('[dash-store] list: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Dashboard visível pro viewer (dono OU share). null = 404.
     *
     * @param  list<int|string> $groupIds
     * @return array{id: string, title: string, isHome: bool, shared: bool, canEdit: bool, updatedAt: string, layout: list<array<string, mixed>>, ownerId: int}|null
     */
    public function getVisible(string $id, int $userId, array $groupIds): ?array
    {
        try {
            $st = $this->pdo()->prepare(
                'SELECT d.id, d.title, d.is_home, d.user_id, d.updated_at, d.layout_json, d.filters_json FROM mad_ai_dashboard d WHERE d.id = ? AND ' . self::TENANT_SQL
            );
            $st->execute([$id, TenantContext::id()]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (! $r) {
                return null;
            }

            $ownerId = (int) ($r['user_id'] ?? 0);
            $isOwner = $ownerId === $userId;
            if (! $isOwner && ! $this->isSharedWith($id, $userId, $groupIds)) {
                return null;
            }

            $layout  = json_decode((string) ($r['layout_json'] ?? '[]'), true);
            $filters = json_decode((string) ($r['filters_json'] ?? '[]'), true);
            $meta    = self::rowMeta($r, ! $isOwner, $isOwner);
            $meta['layout']  = is_array($layout) ? self::sanitizeLayout($layout) : [];
            $meta['filters'] = is_array($filters) ? self::sanitizeFilters($filters) : [];
            $meta['ownerId'] = $ownerId;

            return $meta;
        } catch (\Throwable $e) {
            error_log('[dash-store] get: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Update de título/layout/filtros — só o dono.
     *
     * @param list<array<string, mixed>>|null $layout
     * @param list<array<string, mixed>>|null $filters filtros globais (null = não mexe; [] = limpa)
     */
    public function update(string $id, int $userId, ?string $title, ?array $layout, ?array $filters = null): bool
    {
        $sets   = [];
        $params = [];
        if ($title !== null && trim($title) !== '') {
            $sets[]   = 'title = ?';
            $params[] = trim($title);
        }
        if ($layout !== null) {
            $sets[]   = 'layout_json = ?';
            $params[] = json_encode(self::sanitizeLayout($layout), JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if ($filters !== null) {
            $sets[]   = 'filters_json = ?';
            $params[] = json_encode(self::sanitizeFilters($filters), JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        if ($sets === []) {
            return false;
        }
        $sets[]   = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        array_push($params, $id, $userId, TenantContext::id());

        try {
            $st = $this->pdo()->prepare(
                'UPDATE mad_ai_dashboard SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ? AND (tenant_id = ? OR tenant_id IS NULL)'
            );
            $st->execute($params);

            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[dash-store] update: ' . $e->getMessage());

            return false;
        }
    }

    public function delete(string $id, int $userId): bool
    {
        try {
            $conn = $this->pdo();
            $st   = $conn->prepare('DELETE FROM mad_ai_dashboard WHERE id = ? AND user_id = ? AND (tenant_id = ? OR tenant_id IS NULL)');
            $st->execute([$id, $userId, TenantContext::id()]);
            if ($st->rowCount() === 0) {
                return false;
            }
            $conn->prepare('DELETE FROM mad_ai_dashboard_share WHERE dashboard_id = ?')->execute([$id]);

            return true;
        } catch (\Throwable $e) {
            error_log('[dash-store] delete: ' . $e->getMessage());

            return false;
        }
    }

    /** Marca como home EXCLUSIVO do usuário (zera os demais dele). */
    public function setHome(string $id, int $userId): bool
    {
        try {
            $conn = $this->pdo();
            $tid  = TenantContext::id();
            $st   = $conn->prepare('SELECT 1 FROM mad_ai_dashboard WHERE id = ? AND user_id = ? AND (tenant_id = ? OR tenant_id IS NULL)');
            $st->execute([$id, $userId, $tid]);
            if ($st->fetchColumn() === false) {
                return false;
            }
            $conn->prepare('UPDATE mad_ai_dashboard SET is_home = 0 WHERE user_id = ?')->execute([$userId]);
            $conn->prepare('UPDATE mad_ai_dashboard SET is_home = 1, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([date('Y-m-d H:i:s'), $id, $userId]);

            return true;
        } catch (\Throwable $e) {
            error_log('[dash-store] home: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Substitui os shares do dashboard (dono only).
     *
     * @param list<int|string> $users
     * @param list<int|string> $groups
     */
    public function setShares(string $id, int $userId, array $users, array $groups): bool
    {
        try {
            $conn = $this->pdo();
            $st   = $conn->prepare('SELECT 1 FROM mad_ai_dashboard WHERE id = ? AND user_id = ? AND (tenant_id = ? OR tenant_id IS NULL)');
            $st->execute([$id, $userId, TenantContext::id()]);
            if ($st->fetchColumn() === false) {
                return false;
            }

            $conn->prepare('DELETE FROM mad_ai_dashboard_share WHERE dashboard_id = ?')->execute([$id]);

            $now = date('Y-m-d H:i:s');
            $ins = $conn->prepare(
                'INSERT INTO mad_ai_dashboard_share (id, dashboard_id, kind, ref_id, created_at)'
                . ' VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM mad_ai_dashboard_share), ?, ?, ?, ?)'
            );
            foreach (array_unique(array_map('intval', $users)) as $u) {
                if ($u > 0) {
                    $ins->execute([$id, 'user', $u, $now]);
                }
            }
            foreach (array_unique(array_map('intval', $groups)) as $g) {
                if ($g > 0) {
                    $ins->execute([$id, 'group', $g, $now]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            error_log('[dash-store] shares: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Shares atuais do dashboard.
     *
     * @return array{users: list<int>, groups: list<int>}
     */
    public function shares(string $id): array
    {
        $out = ['users' => [], 'groups' => []];

        try {
            $st = $this->pdo()->prepare('SELECT kind, ref_id FROM mad_ai_dashboard_share WHERE dashboard_id = ?');
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = ($r['kind'] ?? '') === 'group' ? 'groups' : 'users';
                $out[$key][] = (int) $r['ref_id'];
            }
        } catch (\Throwable $e) {
            error_log('[dash-store] shares get: ' . $e->getMessage());
        }

        return $out;
    }

    /** O widget aparece em algum dashboard compartilhado com o viewer? */
    public function widgetVisibleViaShare(string $widgetId, int $userId, array $groupIds): bool
    {
        try {
            $tid      = TenantContext::id();
            $groupIds = array_values(array_map('intval', $groupIds));
            $inGroups = $groupIds !== [] ? ' OR (s.kind = \'group\' AND s.ref_id IN (' . implode(',', $groupIds) . '))' : '';
            // layout_json LIKE — pragmático e portável; o id é um token
            // aleatório com aspas em volta, sem falso-positivo prático.
            $st = $this->pdo()->prepare(
                'SELECT 1 FROM mad_ai_dashboard d JOIN mad_ai_dashboard_share s ON s.dashboard_id = d.id'
                . ' WHERE ' . self::TENANT_SQL
                . ' AND d.layout_json LIKE ?'
                . ' AND ((s.kind = \'user\' AND s.ref_id = ?)' . $inGroups . ')'
            );
            $st->execute([$tid, '%"' . $widgetId . '"%', $userId]);

            return $st->fetchColumn() !== false;
        } catch (\Throwable $e) {
            error_log('[dash-store] widget share: ' . $e->getMessage());

            return false;
        }
    }

    /** É compartilhado com o viewer (user direto ou via grupo)? */
    private function isSharedWith(string $id, int $userId, array $groupIds): bool
    {
        $groupIds = array_values(array_map('intval', $groupIds));
        $inGroups = $groupIds !== [] ? ' OR (kind = \'group\' AND ref_id IN (' . implode(',', $groupIds) . '))' : '';
        $st = $this->pdo()->prepare(
            'SELECT 1 FROM mad_ai_dashboard_share WHERE dashboard_id = ? AND ((kind = \'user\' AND ref_id = ?)' . $inGroups . ')'
        );
        $st->execute([$id, $userId]);

        return $st->fetchColumn() !== false;
    }

    /**
     * Layout defensivo: só entradas {widgetId, x, y, w, h} com tipos certos.
     *
     * @param  array<int, mixed> $layout
     * @return list<array{widgetId: string, x: int, y: int, w: int, h: int}>
     */
    private static function sanitizeLayout(array $layout): array
    {
        $out = [];
        foreach ($layout as $item) {
            if (! is_array($item)) {
                continue;
            }
            $wid = $item['widgetId'] ?? null;
            if (! is_string($wid) || $wid === '') {
                continue;
            }
            $out[] = [
                'widgetId' => $wid,
                'x'        => max(0, min(11, (int) ($item['x'] ?? 0))),
                'y'        => max(0, (int) ($item['y'] ?? 0)),
                'w'        => max(1, min(12, (int) ($item['w'] ?? 4))),
                'h'        => max(1, min(24, (int) ($item['h'] ?? 4))),
            ];
        }

        return $out;
    }

    /**
     * Filtros globais defensivos. Cada def: {id, name, label, kind, options,
     * default, bindings?}. `name` casa por auto-bind com o param homônimo dos
     * widgets; `bindings` mapeia explicitamente filtro→param por widget (para
     * casar nomes diferentes). Valores de filtro são sessão-only no viewer —
     * aqui só persiste a DEFINIÇÃO (com default).
     *
     * @param  array<int, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private static function sanitizeFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $f) {
            if (! is_array($f)) {
                continue;
            }
            $name = (string) ($f['name'] ?? '');
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                continue;
            }
            $id = (string) ($f['id'] ?? '');
            if (! preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $id)) {
                $id = 'flt-' . substr(md5(uniqid('', true)), 0, 8);
            }
            $options = [];
            foreach ((array) ($f['options'] ?? []) as $o) {
                if (is_scalar($o) && (string) $o !== '') {
                    $options[] = (string) $o;
                }
            }
            $bindings = [];
            foreach ((array) ($f['bindings'] ?? []) as $b) {
                if (! is_array($b)) {
                    continue;
                }
                $wid   = $b['widgetId'] ?? null;
                $param = (string) ($b['param'] ?? '');
                if (is_string($wid) && $wid !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $param)) {
                    $bindings[] = ['widgetId' => $wid, 'param' => $param];
                }
            }
            $label = trim((string) ($f['label'] ?? ''));
            $def   = [
                'id'      => $id,
                'name'    => $name,
                'label'   => $label !== '' ? $label : $name,
                'kind'    => ($f['kind'] ?? '') === 'select' ? 'select' : 'search',
                'options' => array_slice(array_values(array_unique($options)), 0, 100),
                'default' => (string) ($f['default'] ?? ''),
            ];
            if ($bindings !== []) {
                $def['bindings'] = array_slice($bindings, 0, 50);
            }
            $out[] = $def;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function rowMeta(array $r, bool $shared, bool $canEdit): array
    {
        return [
            'id'        => (string) $r['id'],
            'title'     => (string) ($r['title'] ?? ''),
            // is_home é conceito do DONO — num dashboard compartilhado o flag
            // não vaza pro viewer (a estrela dele marca os dashboards DELE).
            'isHome'    => ! $shared && (int) ($r['is_home'] ?? 0) === 1,
            'shared'    => $shared,
            'canEdit'   => $canEdit,
            'updatedAt' => (string) ($r['updated_at'] ?? ''),
        ];
    }
}
