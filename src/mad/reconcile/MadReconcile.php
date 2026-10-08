<?php
namespace Mad\Reconcile;

use Illuminate\Support\Facades\DB;
use Mad\Component\MadComponent;
use Mad\Database\DataScope;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;

/**
 * MadReconcile — conciliação two-panel (<mad-reconcile>).
 *
 * Dois conjuntos de registros (ex.: extrato bancário importado × lançamentos
 * do razão) são pareados em GRUPOS de conciliação N:M persistidos em
 * mad_reconcile_group/mad_reconcile_item (conexão da própria conciliação):
 *
 *   - Auto-match (MatchEngine): valor exato + documento, valor + data na
 *     tolerância, e N:1 por soma — vira grupo `suggested` (confirmar/rejeitar).
 *   - Match manual: usuário seleciona linhas dos dois painéis; soma L = soma R
 *     (dentro de `tolerance`) → grupo `confirmed` direto.
 *   - Desfazer libera os registros de volta pros painéis.
 *
 * ┌─ Como usar (declarativo) ───────────────────────────────────────────────┐
 * │                                                                         │
 * │  <mad-reconcile left-model="BankStatementLine" right-model="LedgerEntry"│
 * │      database="business" match-on="amount,date:3,document"              │
 * │      left-amount="valor"  right-amount="valor"                          │
 * │      left-date="data"     right-date="data_lancamento"                  │
 * │      left-doc="documento" right-doc="numero_doc"                        │
 * │      left-label="descricao" right-label="historico" />                  │
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Segurança:
 *   - models resolvidos via ModelOptionsLoader (registry);
 *   - todo find/whereIn passa pelas queries ESCOPADAS (leftQuery/rightQuery
 *     hooks) — registro fora do escopo não pode ser conciliado (anti-IDOR);
 *   - operações de grupo validam o `context` da conciliação E, com
 *     Multi-unidade/tenant em pool ligados, a unidade/tenant do grupo
 *     (colunas unit_id/tenant_id, carimbadas na criação — ver _scopeFilter());
 *   - nomes de campo validados como identificadores SQL.
 */
class MadReconcile extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    // ── Configuração ──────────────────────────────────────────────────────

    protected string $leftModel  = '';
    protected string $rightModel = '';
    protected string $database   = '';

    protected string $leftAmount  = 'valor';
    protected string $rightAmount = 'valor';
    protected string $leftDate    = '';
    protected string $rightDate   = '';
    protected string $leftDoc     = '';
    protected string $rightDoc    = '';
    protected string $leftLabel   = '';
    protected string $rightLabel  = '';

    /** Títulos dos painéis (default: nome curto do model). */
    protected string $leftTitle  = '';
    protected string $rightTitle = '';

    /** Regras do auto-match: "amount,date:3,document" (ver MatchEngine). */
    protected string $matchOn = 'amount';

    /** Tolerância de diferença de soma no match manual. */
    protected float $tolerance = 0.0;

    /** Cap de linhas não conciliadas carregadas por painel. */
    protected int $maxRows = 500;

    /**
     * Namespace da conciliação — separa conciliações diferentes sobre os
     * mesmos models. Default: hash de leftModel|rightModel.
     */
    protected string $context = '';

    /** Chave do cache de sessão da config inline (padrão kbCfgKey). */
    public string $rcCfgKey = '';

    // ── Dados de render (não serializados) ────────────────────────────────

    protected array $_leftRows   = [];
    protected array $_rightRows  = [];
    protected array $_suggested  = [];
    protected array $_confirmed  = [];
    protected bool  $_dataLoaded = false;

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        if ($this->leftModel !== '' && $this->rightModel !== '') {
            $this->loadData();
        }
    }

    /**
     * `rcCfgKey` aponta para a config desta conciliação guardada na sessão e viaja no
     * estado cifrado: só o servidor a escreve. Como prop pública, ela também
     * aceitava valor mandado pelo navegador junto dos campos — e a requisição
     * seguinte montava esta tela com a config de OUTRA aberta na mesma sessão.
     */
    protected function _lockedStateProps(): array
    {
        return array_merge(parent::_lockedStateProps(), ['rcCfgKey']);
    }

    public function hydrate(): void
    {
        $cfg = $this->rcCfgKey !== ''
            ? session('mad_rc_cfg.' . $this->rcCfgKey)
            : null;
        if (!is_array($cfg)) {
            $latest = session('mad_rc_cfg_latest.' . static::class);
            $cfg    = is_string($latest) && $latest !== '' ? session('mad_rc_cfg.' . $latest) : null;
        }
        if (is_array($cfg)) {
            $this->_applyInlineConfig($cfg);
        }
    }

    protected function view(): string|array
    {
        return ['components.reconcile', ['__component' => $this]];
    }

    // ── Render inline (MadReconcileCompiler) ──────────────────────────────

    public function _renderInlineReconcile(array $config): string
    {
        $this->_applyInlineConfig($config);

        $this->rcCfgKey = md5(static::class . '|' . json_encode($config));
        session([
            'mad_rc_cfg.' . $this->rcCfgKey        => $config,
            'mad_rc_cfg_latest.' . static::class    => $this->rcCfgKey,
        ]);

        if (!$this->_dataLoaded) {
            $this->loadData();
        }

        return \Mad\View\MadBlade::render('components.reconcile', ['__component' => $this]);
    }

    protected function _applyInlineConfig(array $config): void
    {
        foreach (['leftModel', 'rightModel', 'database',
                  'leftAmount', 'rightAmount', 'leftDate', 'rightDate',
                  'leftDoc', 'rightDoc', 'leftLabel', 'rightLabel',
                  'leftTitle', 'rightTitle', 'matchOn', 'context'] as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (string) $config[$k];
            }
        }
        if (isset($config['tolerance'])) {
            $this->tolerance = max(0.0, (float) $config['tolerance']);
        }
        if (isset($config['maxRows'])) {
            $this->maxRows = max(1, (int) $config['maxRows']);
        }
    }

    // ── Hooks (subclasse) ─────────────────────────────────────────────────

    /** Escopo do painel esquerdo (tenant/unit/período). */
    protected function leftQuery(\Illuminate\Database\Eloquent\Builder $q): void
    {
    }

    /** Escopo do painel direito. */
    protected function rightQuery(\Illuminate\Database\Eloquent\Builder $q): void
    {
    }

    /** Chamado após grupos serem confirmados (ids dos grupos). */
    protected function afterConfirm(array $groupIds): void
    {
    }

    // ── Data loading ──────────────────────────────────────────────────────

    public function loadData(): void
    {
        $this->_assertIdentifier($this->leftAmount, 'left-amount');
        $this->_assertIdentifier($this->rightAmount, 'right-amount');
        foreach (['leftDate', 'rightDate', 'leftDoc', 'rightDoc', 'leftLabel', 'rightLabel'] as $prop) {
            if ($this->$prop !== '') {
                $this->_assertIdentifier($this->$prop, $prop);
            }
        }

        $this->_leftRows  = $this->_loadUnmatched('L');
        $this->_rightRows = $this->_loadUnmatched('R');
        $this->_loadGroups();
        $this->_dataLoaded = true;
    }

    private function _loadUnmatched(string $side): array
    {
        $modelClass = $this->_resolveModelFqcn($side === 'L' ? $this->leftModel : $this->rightModel);
        if ($modelClass === '') {
            return [];
        }

        $q = $modelClass::query();
        $side === 'L' ? $this->leftQuery($q) : $this->rightQuery($q);

        $matchedIds = DB::connection($this->_db())
            ->table('mad_reconcile_item as i')
            ->join('mad_reconcile_group as g', 'g.id', '=', 'i.group_id')
            ->where('g.context', $this->_context())
            ->where('i.side', $side)
            ->pluck('i.record_id');

        if ($matchedIds->isNotEmpty()) {
            $q->whereNotIn((new $modelClass())->getKeyName(), $this->_keys($matchedIds));
        }

        $dateField = $side === 'L' ? $this->leftDate : $this->rightDate;
        $q->orderBy($dateField !== '' ? $dateField : (new $modelClass())->getKeyName());

        return $q->limit($this->maxRows)->get()->all();
    }

    private function _loadGroups(): void
    {
        $db = DB::connection($this->_db());

        $groups = $this->_groupQuery()
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $items = $db->table('mad_reconcile_item')
            ->whereIn('group_id', $groups->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('group_id');

        $this->_suggested = [];
        $this->_confirmed = [];
        foreach ($groups as $g) {
            $g->items = ($items[$g->id] ?? collect())->values()->all();
            if ($g->status === 'suggested') {
                $this->_suggested[] = $g;
            } elseif (count($this->_confirmed) < 30) {
                $this->_confirmed[] = $g;
            }
        }
        // sugestões em ordem de criação (score já embutido na ordem dos passes)
        $this->_suggested = array_reverse($this->_suggested);
    }

    // ── Getters do blade ──────────────────────────────────────────────────

    public function getLeftRows(): array   { return $this->_leftRows; }
    public function getRightRows(): array  { return $this->_rightRows; }
    public function getSuggested(): array  { return $this->_suggested; }
    public function getConfirmed(): array  { return $this->_confirmed; }
    public function getTolerance(): float  { return $this->tolerance; }

    public function getPanelTitle(string $side): string
    {
        $explicit = $side === 'L' ? $this->leftTitle : $this->rightTitle;
        if ($explicit !== '') {
            return $explicit;
        }
        $model = $side === 'L' ? $this->leftModel : $this->rightModel;
        $short = (string) strrchr($model, '\\');
        return $short !== '' ? substr($short, 1) : $model;
    }

    /** Normaliza um registro pro painel/engine: id/amount/date/doc/label. */
    public function rowData(object $record, string $side): array
    {
        $amountField = $side === 'L' ? $this->leftAmount : $this->rightAmount;
        $dateField   = $side === 'L' ? $this->leftDate : $this->rightDate;
        $docField    = $side === 'L' ? $this->leftDoc : $this->rightDoc;
        $labelField  = $side === 'L' ? $this->leftLabel : $this->rightLabel;

        return [
            'id'     => $record->getKey(),
            'amount' => (float) ($record->{$amountField} ?? 0),
            'date'   => $dateField !== '' ? $this->_dateString($record->{$dateField} ?? null) : null,
            'doc'    => $docField !== '' ? (string) ($record->{$docField} ?? '') : null,
            'label'  => $labelField !== '' ? (string) ($record->{$labelField} ?? '') : '',
        ];
    }

    /** Soma dos itens de um grupo em um dos lados (pro card do grupo). */
    public function groupSum(object $group, string $side): float
    {
        $sum = 0.0;
        foreach ($group->items as $item) {
            if ($item->side === $side) {
                $sum += (float) $item->amount;
            }
        }
        return $sum;
    }

    public function formatAmount(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    // ── Actions (wire) ────────────────────────────────────────────────────

    /** Roda o MatchEngine sobre os não conciliados e grava grupos `suggested`. */
    public function onAutoMatch(): MadResponse
    {
        $this->loadData();

        $engine = new MatchEngine($this->matchOn);
        $left   = array_map(fn ($r) => $this->rowData($r, 'L'), $this->_leftRows);
        $right  = array_map(fn ($r) => $this->rowData($r, 'R'), $this->_rightRows);

        $groups = $engine->match($left, $right);

        if (empty($groups)) {
            $this->forceFullRender();
            return (new MadResponse())->toast(mad_t('mad.reconcile.no_matches'), 'info');
        }

        $leftById  = $this->_indexById($left);
        $rightById = $this->_indexById($right);

        DB::connection($this->_db())->transaction(function () use ($groups, $leftById, $rightById) {
            foreach ($groups as $group) {
                $groupId = $this->_insertGroup('suggested', (int) $group['score']);
                $this->_insertItems($groupId, 'L', $group['left'], $leftById);
                $this->_insertItems($groupId, 'R', $group['right'], $rightById);
            }
        });

        $this->loadData();
        $this->forceFullRender();

        $resp = (new MadResponse())->toast(mad_t('mad.reconcile.suggested', ['count' => count($groups)]), 'success');
        if ($engine->truncated) {
            $resp->toast(mad_t('mad.reconcile.truncated'), 'warning');
        }
        return $resp;
    }

    /** Match manual: seleção dos dois painéis → grupo `confirmed` direto. */
    public function onManualMatch(string $leftIdsJson, string $rightIdsJson): MadResponse
    {
        $leftIds  = $this->_decodeIds($leftIdsJson);
        $rightIds = $this->_decodeIds($rightIdsJson);
        if (empty($leftIds) || empty($rightIds)) {
            return (new MadResponse())->toast(mad_t('mad.reconcile.select_both'), 'warning');
        }

        // Anti-IDOR: os ids têm que estar no ESCOPO e não conciliados —
        // busca via _loadUnmatched-equivalente (query escopada + whereIn).
        $leftRecords  = $this->_scopedByIds('L', $leftIds);
        $rightRecords = $this->_scopedByIds('R', $rightIds);
        if (count($leftRecords) !== count($leftIds) || count($rightRecords) !== count($rightIds)) {
            return (new MadResponse())->toast(mad_t('mad.reconcile.out_of_scope'), 'error');
        }

        $left  = array_map(fn ($r) => $this->rowData($r, 'L'), $leftRecords);
        $right = array_map(fn ($r) => $this->rowData($r, 'R'), $rightRecords);

        $sumL = array_sum(array_column($left, 'amount'));
        $sumR = array_sum(array_column($right, 'amount'));
        if (abs($sumL - $sumR) > $this->tolerance + 0.005) {
            return (new MadResponse())->toast(
                mad_t('mad.reconcile.sum_mismatch', [
                    'left'  => $this->formatAmount($sumL),
                    'right' => $this->formatAmount($sumR),
                ]),
                'error'
            );
        }

        DB::connection($this->_db())->transaction(function () use ($left, $right) {
            $groupId = $this->_insertGroup('confirmed', 100);
            $this->_insertItems($groupId, 'L', array_column($left, 'id'), $this->_indexById($left));
            $this->_insertItems($groupId, 'R', array_column($right, 'id'), $this->_indexById($right));
        });

        $this->loadData();
        $this->forceFullRender();
        return (new MadResponse())->toast(mad_t('mad.reconcile.matched'), 'success');
    }

    public function onConfirmGroup(int $groupId): MadResponse
    {
        $updated = $this->_groupQuery()->where('id', $groupId)->where('status', 'suggested')->update([
            'status'     => 'confirmed',
            'matched_by' => $this->_userId(),
            'matched_at' => now(),
            'updated_at' => now(),
        ]);
        if ($updated) {
            $this->afterConfirm([$groupId]);
        }

        $this->loadData();
        $this->forceFullRender();
        return (new MadResponse())->toast(
            $updated ? mad_t('mad.reconcile.confirmed', ['count' => 1]) : mad_t('mad.reconcile.group_missing'),
            $updated ? 'success' : 'warning'
        );
    }

    /** Confirma TODAS as sugestões pendentes da conciliação. */
    public function onConfirmAll(): MadResponse
    {
        $ids = $this->_groupQuery()->where('status', 'suggested')->pluck('id')->all();
        if (!empty($ids)) {
            $this->_groupQuery()->whereIn('id', $ids)->update([
                'status'     => 'confirmed',
                'matched_by' => $this->_userId(),
                'matched_at' => now(),
                'updated_at' => now(),
            ]);
            $this->afterConfirm($ids);
        }

        $this->loadData();
        $this->forceFullRender();
        return (new MadResponse())->toast(mad_t('mad.reconcile.confirmed', ['count' => count($ids)]), 'success');
    }

    /** Rejeita uma sugestão (apaga o grupo; registros voltam pros painéis). */
    public function onRejectGroup(int $groupId): MadResponse
    {
        $removed = $this->_deleteGroup($groupId, 'suggested');

        $this->loadData();
        $this->forceFullRender();
        return (new MadResponse())->toast(
            $removed ? mad_t('mad.reconcile.rejected') : mad_t('mad.reconcile.group_missing'),
            $removed ? 'info' : 'warning'
        );
    }

    /** Desfaz uma conciliação confirmada. */
    public function onUnmatch(int $groupId): MadResponse
    {
        $removed = $this->_deleteGroup($groupId, 'confirmed');

        $this->loadData();
        $this->forceFullRender();
        return (new MadResponse())->toast(
            $removed ? mad_t('mad.reconcile.unmatched') : mad_t('mad.reconcile.group_missing'),
            $removed ? 'info' : 'warning'
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function _insertGroup(string $status, int $score): int
    {
        return (int) DB::connection($this->_db())->table('mad_reconcile_group')->insertGetId(array_merge([
            'context'    => $this->_context(),
            'status'     => $status,
            'score'      => $score,
            'matched_by' => $status === 'confirmed' ? $this->_userId() : null,
            'matched_at' => $status === 'confirmed' ? now() : null,
            'tenant_id'  => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->_scopeStamp('mad_reconcile_group')));
    }

    /**
     * @param list<int|string> $ids chaves primárias dos registros do lado
     */
    private function _insertItems(int $groupId, string $side, array $ids, array $byId): void
    {
        $table = $side === 'L' ? $this->_tableFor($this->leftModel) : $this->_tableFor($this->rightModel);
        $stamp = $this->_scopeStamp('mad_reconcile_item');
        $rows  = [];
        foreach ($ids as $id) {
            $rows[] = array_merge([
                'group_id'     => $groupId,
                'side'         => $side,
                'record_table' => $table,
                'record_id'    => (string) $id,
                'amount'       => $byId[$id]['amount'] ?? 0,
                'created_at'   => now(),
            ], $stamp);
        }
        DB::connection($this->_db())->table('mad_reconcile_item')->insert($rows);
    }

    private function _deleteGroup(int $groupId, string $expectedStatus): bool
    {
        $db    = DB::connection($this->_db());
        $found = $this->_groupQuery()->where('id', $groupId)->where('status', $expectedStatus)->exists();
        if (!$found) {
            return false;
        }
        $db->transaction(function () use ($db, $groupId) {
            $db->table('mad_reconcile_item')->where('group_id', $groupId)->delete();
            $db->table('mad_reconcile_group')->where('id', $groupId)->delete();
        });
        return true;
    }

    /**
     * Grupos DESTA conciliação no escopo corrente — toda leitura e toda ação
     * de grupo (listar, confirmar, confirmar todas, rejeitar, desfazer) passa
     * por aqui.
     */
    private function _groupQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::connection($this->_db())
            ->table('mad_reconcile_group')
            ->where('context', $this->_context());
        foreach ($this->_scopeFilter() as $col => $value) {
            $q->where($col, $value);
        }

        return $q;
    }

    /**
     * Unidade/tenant do grupo exigidos pelo escopo do app: com Multi-unidade
     * (ou tenant em pool) ligado e uma unidade (tenant) corrente — a mesma
     * regra do BelongsToUnit/BelongsToTenant dos registros. Os grupos não
     * tinham dono: a unidade 2 listava e mexia nas conciliações da unidade 1.
     *
     * Sem a migration 2026_09_27_000001 (ex.: só o vendor atualizado, sem
     * republicar) fica como antes e avisa no log: os grupos antigos não têm
     * dono, filtrar esconderia todos — e os registros deles seguem fora dos
     * painéis.
     *
     * @return array<string, int>
     */
    private function _scopeFilter(): array
    {
        $unit   = DataScope::unitScopeActive() ? UnitContext::id() : null;
        $tenant = DataScope::tenantScopeActive() ? TenantContext::id() : null;
        if ($unit === null && $tenant === null) {
            return [];
        }
        if (! $this->_hasColumn('mad_reconcile_group', 'unit_id')) {
            $this->_warnMissingScopeColumns();

            return [];
        }

        $out = [];
        if ($unit !== null) {
            $out['unit_id'] = $unit;
        }
        if ($tenant !== null) {
            $out['tenant_id'] = $tenant;
        }

        return $out;
    }

    /**
     * Carimbo de unidade/tenant correntes na gravação de grupo/item (quando a
     * tabela tem a coluna) — o grupo fica com dono mesmo se o Multi-unidade
     * for ligado depois.
     *
     * @return array<string, int>
     */
    private function _scopeStamp(string $table): array
    {
        $out = [];
        if (($unit = UnitContext::id()) !== null && $this->_hasColumn($table, 'unit_id')) {
            $out['unit_id'] = $unit;
        }
        if (($tenant = TenantContext::id()) !== null && $this->_hasColumn($table, 'tenant_id')) {
            $out['tenant_id'] = $tenant;
        }

        return $out;
    }

    private function _hasColumn(string $table, string $column): bool
    {
        $db   = $this->_db();
        $cols = DataScope::memo('reconcile.columns', $db . '|' . $table, static function () use ($db, $table): array {
            try {
                return array_map('strtolower', \Illuminate\Support\Facades\Schema::connection($db)->getColumnListing($table));
            } catch (\Throwable) {
                return [];
            }
        });

        return in_array(strtolower($column), $cols, true);
    }

    private function _warnMissingScopeColumns(): void
    {
        DataScope::memo('reconcile.warned', $this->_db(), static function (): bool {
            error_log('[MadReconcile] Multi-unidade ligado mas mad_reconcile_group sem unit_id — rode as migrations (republique o projeto); as conciliações seguem SEM filtro por unidade até lá.');

            return true;
        });
    }

    /** Registros de um lado, ESCOPADOS e ainda não conciliados, por id. */
    private function _scopedByIds(string $side, array $ids): array
    {
        $modelClass = $this->_resolveModelFqcn($side === 'L' ? $this->leftModel : $this->rightModel);
        if ($modelClass === '') {
            return [];
        }
        $q = $modelClass::query();
        $side === 'L' ? $this->leftQuery($q) : $this->rightQuery($q);

        $matchedIds = DB::connection($this->_db())
            ->table('mad_reconcile_item as i')
            ->join('mad_reconcile_group as g', 'g.id', '=', 'i.group_id')
            ->where('g.context', $this->_context())
            ->where('i.side', $side)
            ->pluck('i.record_id');
        if ($matchedIds->isNotEmpty()) {
            $q->whereNotIn((new $modelClass())->getKeyName(), $this->_keys($matchedIds));
        }

        return $q->whereIn((new $modelClass())->getKeyName(), $ids)->get()->all();
    }

    private function _indexById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['id']] = $row;
        }
        return $out;
    }

    /**
     * `record_id` é coluna de TEXTO — o que volta do banco vem string, mesmo
     * pra tabela de PK serial. Serial volta a int pra casar com a coluna
     * inteira do model no whereIn/whereNotIn; chave de texto passa intacta.
     *
     * @param iterable<mixed> $ids
     * @return list<int|string>
     */
    private function _keys(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '') continue;
            $v = (string) $id;
            $out[] = preg_match('/^(?:0|[1-9][0-9]*)$/', $v) ? (int) $v : $v;
        }
        return $out;
    }

    /**
     * Ids vindos do painel (JSON do client). A chave primária pode ser TEXTO
     * (UUID/ULID/código) — `intval()` zerava tudo que não fosse serial e a
     * conciliação manual simplesmente não achava nenhum registro.
     *
     * @return list<int|string>
     */
    private function _decodeIds(string $json): array
    {
        $ids = json_decode($json, true);
        if (!is_array($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (is_int($id)) {
                if ($id > 0) $out[] = $id;
                continue;
            }
            if (!is_string($id)) continue;
            $id = trim($id);
            if ($id === '' || $id === '0') continue;
            // Serial digitado como string continua entrando como int (chave
            // idêntica à do banco, e o anti-IDOR compara por valor).
            $out[] = preg_match('/^(?:[1-9][0-9]*)$/', $id) ? (int) $id : $id;
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    public function _context(): string
    {
        if ($this->context !== '') {
            return $this->context;
        }
        return md5($this->leftModel . '|' . $this->rightModel);
    }

    private function _tableFor(string $model): string
    {
        $fqcn = $this->_resolveModelFqcn($model);
        if ($fqcn === '' || !class_exists($fqcn)) {
            return $model;
        }
        return (new $fqcn())->getTable();
    }

    private function _userId(): ?int
    {
        try {
            $id = auth()->id();
            return $id !== null ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function _dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    private function _assertIdentifier(string $field, string $what): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $field)) {
            throw new \InvalidArgumentException(
                "MadReconcile: {$what} '{$field}' não é um identificador SQL válido."
            );
        }
        return $field;
    }

    protected function _db(): string
    {
        if (!empty($this->database)) {
            return $this->database;
        }
        return defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business';
    }

    protected function _resolveModelFqcn(string $model): string
    {
        if ($model === '') {
            return '';
        }
        try {
            return ModelOptionsLoader::resolveModelClass($model);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
