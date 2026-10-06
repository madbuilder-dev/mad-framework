<?php

namespace Mad\Grid;

use Illuminate\Support\Facades\DB;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;

/**
 * GridSavedFilterStore — filtros avançados SALVOS de uma listagem
 * (`<mad-custom-filters>`), por usuário, na tabela `mad_grid_filter` (conexão
 * de controle `iam`).
 *
 * ┌─ Visibilidade ─────────────────────────────────────────────────────────┐
 * │                                                                        │
 * │  • Meus filtros: os do usuário da sessão, no tenant atual.             │
 * │  • Compartilhados: shared='Y' de OUTROS usuários, no mesmo tenant e,   │
 * │    quando a unidade é conhecida, na mesma unidade (ou sem unidade).    │
 * │  • Linha sem tenant/unidade (app sem tenancy) vale para todos — mesma  │
 * │    regra do DashboardStore.                                            │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Padrão (is_default) ──────────────────────────────────────────────────┐
 * │                                                                        │
 * │  UM padrão por usuário por grid (marcar um desmarca os outros dele).   │
 * │  Filtro compartilhado marcado como padrão vale para quem NÃO tem um    │
 * │  padrão pessoal. Só o dono marca/desmarca: o padrão de um filtro       │
 * │  compartilhado atinge todo mundo, então não é decisão de terceiros.    │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * O payload guarda o ESTADO do filtro ({match, rules:[{k,op,v,d}]}) sanitizado
 * só na estrutura: a validação semântica (a coluna ainda existe? o operador
 * ainda vale?) é feita pelo grid na hora de aplicar, contra as defs ATUAIS —
 * um filtro salvo sobrevive a mudanças na tela, perdendo só o que caducou.
 *
 * Fail-safe como os stores irmãos: erro de banco (tabela ausente num app que
 * ainda não migrou) loga e devolve vazio/false — a listagem nunca cai por
 * causa de um filtro salvo.
 */
final class GridSavedFilterStore
{
    public const TABLE = 'mad_grid_filter';

    /** Teto do nome (coluna varchar(120)). */
    public const NAME_MAX = 120;

    /** Teto de condições gravadas (mesmo teto duro do grid). */
    public const RULES_MAX = 20;

    /** Grupo "Administrador" do IAM (id estável do seed de referência). */
    private const ADMIN_GROUP_ID = \Mad\Security\OwnerAdmin::GROUP_ID;

    public function __construct(private string $db = 'iam')
    {
    }

    // ── Sessão ────────────────────────────────────────────────────────────

    /**
     * Usuário logado — `session('userid')`. No app gerado `auth()->id()` é
     * null: quem carrega o usuário é a sessão MAD. null = sem usuário (filtros
     * salvos desligados).
     */
    public static function currentUserId(): ?int
    {
        try {
            $v = function_exists('session') ? session('userid') : null;
        } catch (\Throwable $e) {
            return null;
        }
        $id = is_numeric($v) ? (int) $v : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * Administrador para fins de filtro compartilhado: grupo Administrador do
     * IAM ou admin da unidade ativa (`unit_admin='Y'`, injetado pelo
     * PermissionResolver). O login `admin` sozinho NÃO basta: é texto do
     * cadastro, e o administrador do dono já está no grupo 1
     * ({@see \Mad\Security\OwnerAdmin}).
     */
    public static function isAdmin(): bool
    {
        try {
            if (!function_exists('session')) return false;
            $groups = array_map('intval', (array) (session('usergroupids') ?: []));
            if (in_array(self::ADMIN_GROUP_ID, $groups, true)) return true;

            return (string) session('unit_admin') === 'Y';
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Leitura ───────────────────────────────────────────────────────────

    /**
     * Filtros visíveis do grid: `mine` (do usuário) e `shared` (de outros).
     *
     * @return array{mine: list<array<string, mixed>>, shared: list<array<string, mixed>>}
     */
    public function list(string $gridKey, int $userId, bool $isAdmin = false): array
    {
        $out = ['mine' => [], 'shared' => []];
        $gridKey = self::gridKey($gridKey);

        try {
            $mine = $this->ownQuery($gridKey, $userId)
                ->orderBy('name')->limit(200)->get();
            foreach ($mine as $r) {
                $out['mine'][] = self::rowMeta((array) $r, $userId, $isAdmin);
            }

            $shared = $this->sharedQuery($gridKey)
                ->where('user_id', '<>', $userId)
                ->orderBy('name')->limit(200)->get();
            foreach ($shared as $r) {
                $out['shared'][] = self::rowMeta((array) $r, $userId, $isAdmin);
            }
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] list: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Um filtro VISÍVEL ao usuário (dele ou compartilhado com ele), com o
     * payload decodificado. null = não existe / não é visível.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, string $gridKey, int $userId): ?array
    {
        if ($id <= 0) return null;
        $gridKey = self::gridKey($gridKey);

        try {
            $row = $this->ownQuery($gridKey, $userId)->where('id', $id)->first()
                ?? $this->sharedQuery($gridKey)->where('id', $id)->first();

            return $row ? self::decodeRow((array) $row) : null;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] find: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Filtro padrão efetivo do usuário: o PESSOAL (dele, is_default) vence; sem
     * ele, o compartilhado marcado como padrão (o mais recente).
     *
     * @return array<string, mixed>|null
     */
    public function defaultFor(string $gridKey, int $userId): ?array
    {
        $gridKey = self::gridKey($gridKey);

        try {
            $row = $this->ownQuery($gridKey, $userId)
                ->where('is_default', 'Y')
                ->orderByDesc('updated_at')->orderByDesc('id')
                ->first();
            if (!$row) {
                $row = $this->sharedQuery($gridKey)
                    ->where('is_default', 'Y')
                    ->orderByDesc('updated_at')->orderByDesc('id')
                    ->first();
            }

            return $row ? self::decodeRow((array) $row) : null;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] default: ' . $e->getMessage());

            return null;
        }
    }

    // ── Escrita ───────────────────────────────────────────────────────────

    /**
     * Salva o filtro do usuário. Mesmo nome no mesmo grid SOBRESCREVE (é o
     * "Salvar filtro atual…" sobre um existente), em vez de duplicar.
     *
     * @param array<string, mixed> $payload {match, rules}
     * @return int|null id gravado; null = nome inválido ou falha
     */
    public function save(string $gridKey, int $userId, string $name, array $payload, bool $shared = false, bool $default = false): ?int
    {
        $name = self::cleanName($name);
        if ($name === null || $userId <= 0) return null;
        $gridKey = self::gridKey($gridKey);

        try {
            $conn = DB::connection($this->db);
            $now  = date('Y-m-d H:i:s');
            $json = json_encode(self::sanitizePayload($payload), JSON_UNESCAPED_UNICODE) ?: '{"match":"all","rules":[]}';

            $existing = $this->ownQuery($gridKey, $userId)->where('name', $name)->value('id');

            if ($default) {
                $this->clearDefaults($gridKey, $userId);
            }

            if ($existing) {
                $id  = (int) $existing;
                $set = [
                    'payload'    => $json,
                    'shared'     => $shared ? 'Y' : 'N',
                    'updated_at' => $now,
                ];
                // Regravar sem marcar "Usar como padrão" não DESMARCA o padrão
                // que o filtro já tinha — só marcar muda.
                if ($default) $set['is_default'] = 'Y';
                $conn->table(self::TABLE)->where('id', $id)->update($set);

                return $id;
            }

            return (int) $conn->table(self::TABLE)->insertGetId([
                'grid_key'   => $gridKey,
                'user_id'    => $userId,
                'unit_id'    => UnitContext::id(),
                'tenant_id'  => TenantContext::id(),
                'name'       => $name,
                'payload'    => $json,
                'shared'     => $shared ? 'Y' : 'N',
                'is_default' => $default ? 'Y' : 'N',
                'created_at' => $now,
                'updated_at' => $now,
            ]) ?: null;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] save: ' . $e->getMessage());

            return null;
        }
    }

    /** Renomeia — dono, ou admin quando o filtro é compartilhado. */
    public function rename(int $id, string $gridKey, int $userId, string $name, bool $isAdmin = false): bool
    {
        $name = self::cleanName($name);
        if ($name === null) return false;

        $row = $this->editable($id, $gridKey, $userId, $isAdmin);
        if ($row === null) return false;

        try {
            return DB::connection($this->db)->table(self::TABLE)->where('id', $row['id'])
                ->update(['name' => $name, 'updated_at' => date('Y-m-d H:i:s')]) > 0;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] rename: ' . $e->getMessage());

            return false;
        }
    }

    /** Exclui — dono, ou admin quando o filtro é compartilhado. */
    public function delete(int $id, string $gridKey, int $userId, bool $isAdmin = false): bool
    {
        $row = $this->editable($id, $gridKey, $userId, $isAdmin);
        if ($row === null) return false;

        try {
            return DB::connection($this->db)->table(self::TABLE)->where('id', $row['id'])->delete() > 0;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] delete: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Liga/desliga o padrão de um filtro DO usuário. Ligar desmarca os outros
     * padrões dele no grid (um por usuário por grid).
     *
     * @return bool|null novo estado (true = é o padrão); null = não pode
     */
    public function toggleDefault(int $id, string $gridKey, int $userId): ?bool
    {
        $gridKey = self::gridKey($gridKey);

        try {
            $row = $this->ownQuery($gridKey, $userId)->where('id', $id)->first();
            if (!$row) return null;

            $conn = DB::connection($this->db);
            $now  = date('Y-m-d H:i:s');
            if (((array) $row)['is_default'] === 'Y') {
                $conn->table(self::TABLE)->where('id', $id)->update(['is_default' => 'N', 'updated_at' => $now]);

                return false;
            }

            $this->clearDefaults($gridKey, $userId);
            $conn->table(self::TABLE)->where('id', $id)->update(['is_default' => 'Y', 'updated_at' => $now]);

            return true;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] default toggle: ' . $e->getMessage());

            return null;
        }
    }

    // ── Sanitização ───────────────────────────────────────────────────────

    /**
     * Payload defensivo — só ESTRUTURA ({match, rules:[{k,op,v,d}]}) com tipos
     * e tetos. O significado de cada regra é revalidado pelo grid ao aplicar.
     *
     * @param  array<string, mixed> $payload
     * @return array{match: string, rules: list<array{k: string, op: string, v: mixed, d: string}>}
     */
    public static function sanitizePayload(array $payload): array
    {
        $match = ($payload['match'] ?? '') === 'any' ? 'any' : 'all';
        $rules = [];
        foreach ((array) ($payload['rules'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $k  = $r['k'] ?? '';
            $op = $r['op'] ?? '';
            if (!is_string($k) || $k === '' || strlen($k) > 200 || !is_string($op) || $op === '' || strlen($op) > 20) {
                continue;
            }
            $v = $r['v'] ?? null;
            if (is_array($v)) {
                $list = [];
                foreach ($v as $item) {
                    if (is_scalar($item)) $list[] = mb_substr((string) $item, 0, 200);
                    if (count($list) >= 500) break;
                }
                $v = $list;
            } elseif (is_scalar($v)) {
                $v = mb_substr((string) $v, 0, 200);
            } else {
                $v = null;
            }
            $d = $r['d'] ?? '';
            $rules[] = [
                'k'  => $k,
                'op' => $op,
                'v'  => $v,
                'd'  => is_scalar($d) ? mb_substr(trim((string) $d), 0, 300) : '',
            ];
            if (count($rules) >= self::RULES_MAX) break;
        }

        return ['match' => $match, 'rules' => $rules];
    }

    /** Nome aparado, 1..120 caracteres; null = inválido. */
    public static function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) return null;

        return $name;
    }

    /** Chave do grid cabe na coluna (varchar 190). */
    public static function gridKey(string $gridKey): string
    {
        return strlen($gridKey) > 190 ? substr($gridKey, 0, 157) . '~' . md5($gridKey) : $gridKey;
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /** Filtros DO usuário no tenant atual. */
    private function ownQuery(string $gridKey, int $userId)
    {
        $q = DB::connection($this->db)->table(self::TABLE)
            ->where('grid_key', $gridKey)
            ->where('user_id', $userId);
        $this->scopeTenant($q);

        return $q;
    }

    /** Compartilhados visíveis: mesmo tenant e, com unidade conhecida, mesma unidade. */
    private function sharedQuery(string $gridKey)
    {
        $q = DB::connection($this->db)->table(self::TABLE)
            ->where('grid_key', $gridKey)
            ->where('shared', 'Y');
        $this->scopeTenant($q);

        $unit = UnitContext::id();
        if ($unit !== null) {
            $q->where(function ($w) use ($unit) {
                $w->where('unit_id', $unit)->orWhereNull('unit_id');
            });
        }

        return $q;
    }

    private function scopeTenant($q): void
    {
        $tid = TenantContext::id();
        $q->where(function ($w) use ($tid) {
            if ($tid === null) {
                $w->whereNull('tenant_id');
            } else {
                $w->where('tenant_id', $tid)->orWhereNull('tenant_id');
            }
        });
    }

    /** Linha que o usuário pode alterar: a dele, ou compartilhada se admin. */
    private function editable(int $id, string $gridKey, int $userId, bool $isAdmin): ?array
    {
        if ($id <= 0) return null;
        $gridKey = self::gridKey($gridKey);

        try {
            $row = $this->ownQuery($gridKey, $userId)->where('id', $id)->first();
            if (!$row && $isAdmin) {
                $row = $this->sharedQuery($gridKey)->where('id', $id)->first();
            }

            return $row ? (array) $row : null;
        } catch (\Throwable $e) {
            @error_log('[grid-filter-store] editable: ' . $e->getMessage());

            return null;
        }
    }

    private function clearDefaults(string $gridKey, int $userId): void
    {
        $this->ownQuery($gridKey, $userId)->where('is_default', 'Y')
            ->update(['is_default' => 'N']);
    }

    /** @param array<string, mixed> $r */
    private static function decodeRow(array $r): array
    {
        $payload = json_decode((string) ($r['payload'] ?? ''), true);

        return [
            'id'        => (int) $r['id'],
            'name'      => (string) ($r['name'] ?? ''),
            'userId'    => (int) ($r['user_id'] ?? 0),
            'shared'    => ($r['shared'] ?? 'N') === 'Y',
            'isDefault' => ($r['is_default'] ?? 'N') === 'Y',
            'payload'   => self::sanitizePayload(is_array($payload) ? $payload : []),
        ];
    }

    /**
     * Item da lista para a view (sem o payload — a UI aplica pelo id).
     *
     * @param array<string, mixed> $r
     */
    private static function rowMeta(array $r, int $userId, bool $isAdmin): array
    {
        $own     = (int) ($r['user_id'] ?? 0) === $userId;
        $shared  = ($r['shared'] ?? 'N') === 'Y';
        $payload = json_decode((string) ($r['payload'] ?? ''), true);

        return [
            'id'        => (int) $r['id'],
            'name'      => (string) ($r['name'] ?? ''),
            'shared'    => $shared,
            'isDefault' => ($r['is_default'] ?? 'N') === 'Y',
            'own'       => $own,
            // Editar/excluir: dono sempre; admin só nos compartilhados.
            'canEdit'   => $own || ($shared && $isAdmin),
            // Padrão: só o dono (ver docblock da classe).
            'canDefault' => $own,
            'count'     => is_array($payload) && is_array($payload['rules'] ?? null) ? count($payload['rules']) : 0,
        ];
    }
}
