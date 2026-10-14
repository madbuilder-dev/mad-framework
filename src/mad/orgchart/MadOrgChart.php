<?php
namespace Mad\OrgChart;

use Mad\Component\MadComponent;
use Mad\Form\FieldListColumn;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;
use Mad\Support\SessionClosure;

/**
 * MadOrgChart — hierarquia visual em árvore (<mad-org-chart>).
 *
 * Organograma/estrutura de centros de custo/cadeia de aprovação: cards em
 * árvore top-down com conectores CSS, pan/zoom, collapse por nó, busca com
 * auto-centralização, lazy-load de subárvores e drag re-parent opcional.
 *
 * ┌─ Como usar (declarativo) ───────────────────────────────────────────────┐
 * │                                                                         │
 * │  <mad-org-chart model="Employee" parent-field="manager_id"              │
 * │      title="nome" subtitle="cargo" avatar-field="foto"                  │
 * │      metric="equipe_count" metric-label="equipe"                        │
 * │      draggable lazy-depth="2"                                            │
 * │      click-target="EmployeeForm::onShow({id})" />                        │
 * │                                                                         │
 * │  Card custom (renderizado por nó, com $item/$id em escopo):             │
 * │  <mad-org-chart>                                                         │
 * │      <mad-org-chart-card>                                                │
 * │          <strong>{{ $item->nome }}</strong>                              │
 * │      </mad-org-chart-card>                                               │
 * │  </mad-org-chart>                                                        │
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Segurança:
 *   - model via ModelOptionsLoader (registry);
 *   - TODO acesso a nó passa pela query ESCOPADA (hook query()) — nó fora do
 *     escopo não expande nem re-parenta (anti-IDOR, padrão MadKanban);
 *   - onReparent só com draggable ligado + canReparent() + cycle-check
 *     (novo pai não pode ser descendente do nó);
 *   - campos validados como identificadores SQL.
 */
class MadOrgChart extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    // ── Configuração ──────────────────────────────────────────────────────

    /** Model Eloquent dos nós (short name ou FQCN). */
    protected string $model = '';

    /** Connection. Default: MAIN_DATABASE. */
    protected string $database = '';

    /** FK auto-referente da hierarquia. */
    protected string $parentField = 'parent_id';

    /** Campo do título do card. */
    protected string $titleField = 'nome';

    /** Campo do subtítulo (cargo/código). Vazio = sem subtítulo. */
    protected string $subtitleField = '';

    /** Campo com URL/path da foto. Vazio = iniciais do título. */
    protected string $avatarField = '';

    /** Campo numérico exibido como métrica no card. Vazio = sem métrica. */
    protected string $metricField = '';

    /** Rótulo da métrica (ex: "equipe"). */
    protected string $metricLabel = '';

    /** Ordenação dos irmãos. Vazio = chave primária. */
    protected string $orderField = '';

    /** Habilita drag re-parent. */
    protected bool $draggable = false;

    /** Profundidade carregada no render inicial. 0 = árvore inteira (até maxNodes). */
    protected int $lazyDepth = 0;

    /** Guard de payload: máximo de nós carregados por request. */
    protected int $maxNodes = 1000;

    /** Control::método aberto ao clicar no card (ex: "EmployeeForm::onShow({id})"). */
    protected string $clickTarget = '';

    /** Id do nó raiz. 0 = raízes são parentField NULL/0. */
    protected int $rootId = 0;

    /** Template custom do card (base64 de Blade), via <mad-org-chart-card>. */
    protected string $cardTemplate = '';

    /** Filtro DSL estático dos nós ("ativo=1|tipo=P"). */
    protected string $where = '';

    /** Filtro rico (:where="$filter_x" do builder) — Closure fn($q) => ... */
    protected ?\Closure $whereClosure = null;

    /** Chave do cache de sessão da config inline (padrão kbCfgKey). */
    public string $ocCfgKey = '';

    // ── Dados de render (não serializados) ────────────────────────────────

    protected array $_roots      = [];
    protected bool  $_dataLoaded = false;
    protected bool  $_truncated  = false;

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        if ($this->model !== '') {
            $this->loadData();
        }
    }

    /**
     * `ocCfgKey` aponta para a config deste organograma guardada na sessão e viaja no
     * estado cifrado: só o servidor a escreve. Como prop pública, ela também
     * aceitava valor mandado pelo navegador junto dos campos — e a requisição
     * seguinte montava esta tela com a config de OUTRA aberta na mesma sessão.
     */
    protected function _lockedStateProps(): array
    {
        return array_merge(parent::_lockedStateProps(), ['ocCfgKey']);
    }

    public function hydrate(): void
    {
        $cfg = $this->ocCfgKey !== ''
            ? session('mad_oc_cfg.' . $this->ocCfgKey)
            : null;

        // A sessão veio sem a config DESTA tela (outra aba gravou por último):
        // a tela é desenhada uma vez e reentrega a sua. A "última config" da
        // classe pode ser a de outra aba — com outro filtro.
        if (!is_array($cfg) && $this->ocCfgKey !== '' && $this->_askScreenForConfig(true)) {
            return;
        }
        if (!is_array($cfg)) {
            $latest = session('mad_oc_cfg_latest.' . static::class);
            $cfg    = is_string($latest) && $latest !== '' ? session('mad_oc_cfg.' . $latest) : null;
        }
        if (is_array($cfg)) {
            $this->_applyInlineConfig($cfg);
            if ($this->_whereToRestore) {
                $this->_askScreenForConfig(false);
                if ($this->_whereToRestore) {
                    error_log('[MadOrgChart] filtro em closure não recuperado em ' . static::class . ': o organograma fica sem nós nas ações');
                }
            }
        }
    }

    /** O `:where` em closure veio da sessão como marcador e ainda não voltou vivo. */
    private bool $_whereToRestore = false;

    /** Desenho "a seco" em curso ({@see _askScreenForConfig()}). */
    private bool $_probingConfig = false;

    /** A config desta tela sumiu da sessão e é regravada no desenho a seco. */
    private bool $_recoveringCfg = false;

    /**
     * Desenha a tela "a seco" para o `<mad-org-chart>` reentregar a config —
     * com a closure do `:where` viva, ligada a ESTA tela e ao estado desta
     * requisição. Closure não atravessa a sessão (em JSON, o padrão do app, ela
     * voltava como `{}` e o filtro sumia nas ações). Com `$recover`, a config
     * inteira volta para a sessão; true quando a tela entregou.
     */
    private function _askScreenForConfig(bool $recover): bool
    {
        $this->_probingConfig = true;
        $this->_recoveringCfg = $recover;
        $delivered            = false;
        try {
            $this->render();
            $delivered = $recover ? $this->_recoveringCfg === false : !$this->_whereToRestore;
        } catch (\Throwable $e) {
            error_log('[MadOrgChart] a tela ' . static::class . ' não pôde ser redesenhada para recuperar a configuração: ' . $e->getMessage());
        } finally {
            $this->_probingConfig = false;
            $this->_recoveringCfg = false;
        }

        return $delivered;
    }

    protected function view(): string|array
    {
        return ['components.org-chart', ['__component' => $this]];
    }

    // ── Render inline (MadOrgChartCompiler) ───────────────────────────────

    public function _renderInlineOrgChart(array $config): string
    {
        $this->_applyInlineConfig($config);

        // Desenho "a seco" pedido pelo hydrate() só para a closure voltar viva.
        if ($this->_probingConfig && !$this->_recoveringCfg) {
            return '';
        }

        // Closure não vai para a sessão: no padrão do app ela é gravada em JSON
        // e o objeto voltava como `{}` — as ações (expandir, mover) rodavam sem
        // o filtro. Vai um marcador com o resumo ESTÁVEL dela (o do
        // `serialize()` levava o número do objeto na memória e trocava a chave
        // a cada render); nas ações a tela reentrega a closure viva.
        if (($config['where'] ?? null) instanceof \Closure) {
            $config['where'] = SessionClosure::marker($config['where'], $this);
        }
        $cfgSession = $config;

        // Redesenho (depois de uma ação): a tela do navegador segue com a chave
        // com que foi desenhada, e é para ela que os eventos são despachados.
        if ($this->ocCfgKey === '') {
            $this->ocCfgKey = md5(static::class . '|' . json_encode($config));
        }

        // Config recuperada (a sessão a perdeu): volta para a sessão, sem desenhar.
        if ($this->_recoveringCfg) {
            session(['mad_oc_cfg.' . $this->ocCfgKey => $cfgSession]);
            $this->_recoveringCfg = false;
            return '';
        }
        session([
            'mad_oc_cfg.' . $this->ocCfgKey        => $cfgSession,
            'mad_oc_cfg_latest.' . static::class    => $this->ocCfgKey,
        ]);

        if (!$this->_dataLoaded) {
            $this->loadData();
        }

        return \Mad\View\MadBlade::render('components.org-chart', ['__component' => $this]);
    }

    protected function _applyInlineConfig(array $config): void
    {
        foreach (['model', 'database', 'parentField', 'titleField', 'subtitleField',
                  'avatarField', 'metricField', 'metricLabel', 'orderField',
                  'clickTarget', 'cardTemplate'] as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (string) $config[$k];
            }
        }
        if (isset($config['draggable'])) {
            $this->draggable = (bool) $config['draggable'];
        }
        if (isset($config['lazyDepth'])) {
            $this->lazyDepth = max(0, (int) $config['lazyDepth']);
        }
        if (isset($config['maxNodes'])) {
            $this->maxNodes = max(1, (int) $config['maxNodes']);
        }
        if (isset($config['rootId'])) {
            $this->rootId = max(0, (int) $config['rootId']);
        }
        // where: string DSL OU Closure (:where="$filter_x") — sem string-cast.
        if (array_key_exists('where', $config)) {
            $w = $config['where'];
            if ($w instanceof \Closure) {
                $this->whereClosure   = $w;
                $this->where          = '';
                $this->_whereToRestore = false;
            } elseif (is_string($w) && !SessionClosure::isMarker($w)) {
                $this->where           = $w;
                $this->whereClosure    = null;
                $this->_whereToRestore = false;
            } elseif (SessionClosure::isMarker($w) || is_array($w) || is_object($w)) {
                // Veio da sessão no lugar de uma closure (o marcador; ou o que
                // sobrou de uma guardada por versão anterior). Até a tela
                // devolver a de verdade, o filtro é FECHADO: perder o filtro
                // nunca pode virar "mostrar tudo".
                $this->whereClosure    = SessionClosure::denyAll();
                $this->where           = '';
                $this->_whereToRestore = true;
            }
        }
    }

    // ── Hooks (subclasse) ─────────────────────────────────────────────────

    /** Escopo da árvore (tenant/unit/ativos). Aplica em TODO acesso a nó. */
    protected function query(\Illuminate\Database\Eloquent\Builder $q): void
    {
    }

    /**
     * Autorização do re-parent. Default: permitido quando draggable.
     * Sobrescreva pra regra fina (ex: só RH move pessoas).
     */
    protected function canReparent(object $node, ?object $newParent): bool
    {
        return $this->draggable;
    }

    /** Chamado após re-parent persistido. */
    protected function afterReparent(object $node, int $oldParentId, int $newParentId): void
    {
    }

    // ── Data loading ──────────────────────────────────────────────────────

    public function loadData(): void
    {
        $this->_assertIdentifier($this->parentField, 'parent-field');
        if ($this->orderField !== '') {
            $this->_assertIdentifier($this->orderField, 'order-field');
        }

        $this->_truncated = false;
        $this->_roots     = $this->_loadLevels();
        $this->_dataLoaded = true;
    }

    /**
     * BFS por nível: 1 query por profundidade (whereIn nos pais) + 1 query
     * agregada de contagem de filhos — sem N+1 por nó.
     *
     * @return array<int,array> nós raiz no formato _nodeArray (children aninhados)
     */
    private function _loadLevels(): array
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        if ($modelClass === '' || !class_exists($modelClass)) {
            return [];
        }
        $keyName = (new $modelClass())->getKeyName();

        // Raízes
        $q = $this->_scopedQuery();
        if ($this->rootId > 0) {
            $q->whereKey($this->rootId);
        } else {
            $q->where(fn ($w) => $w->whereNull($this->parentField)->orWhere($this->parentField, 0));
        }
        $records = $q->orderBy($this->orderField !== '' ? $this->orderField : $keyName)->get()->all();

        $total    = count($records);
        $depth    = 0;
        $levels   = [$records];

        // Desce por níveis até lazyDepth (0 = sem limite) ou maxNodes
        while (!empty($records)) {
            $depth++;
            if (($this->lazyDepth > 0 && $depth >= $this->lazyDepth) || $total >= $this->maxNodes) {
                break;
            }
            $parentIds = array_map(fn ($r) => $r->getKey(), $records);
            $records   = $this->_childrenOf($parentIds);
            if ($total + count($records) > $this->maxNodes) {
                $records          = array_slice($records, 0, max(0, $this->maxNodes - $total));
                $this->_truncated = true;
            }
            $total   += count($records);
            $levels[] = $records;
        }

        // Contagem de filhos do ÚLTIMO nível carregado (pros badges hasMore)
        $lastLevel = end($levels) ?: [];
        $counts    = $this->_childCounts(array_map(fn ($r) => $r->getKey(), $lastLevel));

        // Monta a árvore de baixo pra cima
        $byParent = [];
        for ($i = count($levels) - 1; $i >= 1; $i--) {
            $nextByParent = [];
            foreach ($levels[$i] as $record) {
                $children = $byParent[$record->getKey()] ?? [];
                $isLeafLevel = ($i === count($levels) - 1);
                $nextByParent[(int) $record->{$this->parentField}][] = $this->_nodeArray(
                    $record,
                    $children,
                    $isLeafLevel ? (int) ($counts[$record->getKey()] ?? 0) : 0
                );
            }
            $byParent = $nextByParent;
        }

        $roots = [];
        foreach ($levels[0] as $record) {
            $children = $byParent[$record->getKey()] ?? [];
            $count    = (count($levels) === 1) ? (int) ($counts[$record->getKey()] ?? 0) : 0;
            $roots[]  = $this->_nodeArray($record, $children, $count);
        }
        return $roots;
    }

    /** @param array $parentIds @return object[] */
    private function _childrenOf(array $parentIds): array
    {
        if (empty($parentIds)) {
            return [];
        }
        $modelClass = $this->_resolveModelFqcn($this->model);
        $keyName    = (new $modelClass())->getKeyName();
        $q          = $this->_scopedQuery();
        return $q->whereIn($this->parentField, $parentIds)
            ->orderBy($this->orderField !== '' ? $this->orderField : $keyName)
            ->limit($this->maxNodes)
            ->get()
            ->all();
    }

    /** Contagem de filhos por pai (1 query agregada). @return array<int,int> */
    private function _childCounts(array $parentIds): array
    {
        if (empty($parentIds)) {
            return [];
        }
        $rows = $this->_scopedQuery()
            ->toBase()
            ->selectRaw("{$this->parentField} as __pid, count(*) as __cnt")
            ->whereIn($this->parentField, $parentIds)
            ->groupBy($this->parentField)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->__pid] = (int) $row->__cnt;
        }
        return $out;
    }

    /** Nó no formato do blade/JS. */
    private function _nodeArray(object $record, array $children, int $pendingChildren): array
    {
        return [
            'record'   => $record,
            'children' => $children,
            'hasMore'  => empty($children) && $pendingChildren > 0,
            'count'    => $pendingChildren > 0 ? $pendingChildren : count($children),
        ];
    }

    private function _scopedQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        $q          = $modelClass::query();
        $this->query($q);
        $this->_applyWhere($q);
        return $q;
    }

    /**
     * Filtro declarativo da tag (Closure do builder vence a string DSL).
     * Aplicado junto do hook query() — incide em raízes, filhos, counts,
     * busca e re-parent de uma vez.
     */
    protected function _applyWhere(\Illuminate\Database\Eloquent\Builder $q): void
    {
        if ($this->whereClosure !== null) {
            ($this->whereClosure)($q);
            return;
        }
        if ($this->where !== '') {
            FieldListColumn::applyWhereStringToQuery($q, $this->where);
        }
    }

    // ── Getters do blade ──────────────────────────────────────────────────

    public function getRoots(): array      { return $this->_roots; }
    public function isDraggable(): bool    { return $this->draggable; }
    public function isTruncated(): bool    { return $this->_truncated; }
    public function getClickTarget(): string { return $this->clickTarget; }

    /** Dados do card de um nó (escapados no blade/render). */
    public function cardData(object $record): array
    {
        $title = (string) ($record->{$this->titleField} ?? ('#' . $record->getKey()));
        return [
            'id'       => $record->getKey(),
            'title'    => $title,
            'subtitle' => $this->subtitleField !== '' ? (string) ($record->{$this->subtitleField} ?? '') : '',
            'avatar'   => $this->avatarField !== '' ? (string) ($record->{$this->avatarField} ?? '') : '',
            'metric'   => $this->metricField !== '' ? (string) ($record->{$this->metricField} ?? '') : '',
            'initials' => $this->_initials($title),
        ];
    }

    public function getMetricLabel(): string { return $this->metricLabel; }

    /** HTML custom do card (template do <mad-org-chart-card>), ou '' pro default. */
    public function renderCustomCard(object $record): string
    {
        if ($this->cardTemplate === '') {
            return '';
        }
        $template = base64_decode($this->cardTemplate);
        try {
            return \Mad\View\MadBlade::renderString($template, [
                'item'  => $record,
                'id'    => $record->getKey(),
                'chart' => $this,
            ]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function _initials(string $title): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($title)) ?: []));
        if (empty($words)) {
            return '';
        }
        $ini = mb_strtoupper(mb_substr($words[0], 0, 1));
        if (count($words) > 1) {
            $ini .= mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1));
        }
        return $ini;
    }

    // ── Actions (wire) ────────────────────────────────────────────────────

    /** Lazy-load: filhos de um nó → HTML injetado no container do nó. */
    public function onLoadChildren(int $nodeId): MadResponse
    {
        // Escopo: nó tem que estar visível pro usuário (anti-IDOR).
        $node = $this->_scopedQuery()->find($nodeId);
        if (!$node) {
            return (new MadResponse())->toast(mad_t('mad.orgchart.node_missing'), 'warning');
        }

        $children = $this->_childrenOf([$nodeId]);
        $counts   = $this->_childCounts(array_map(fn ($r) => $r->getKey(), $children));

        $html = '';
        foreach ($children as $child) {
            $html .= \Mad\View\MadBlade::render('components.org-chart-node', [
                '__component' => $this,
                'node'        => $this->_nodeArray($child, [], (int) ($counts[$child->getKey()] ?? 0)),
            ]);
        }

        return (new MadResponse())
            ->html('[data-oc-children="' . (int) $nodeId . '"]', $html)
            ->script($this->_chartEvent('mad-orgchart:children', ['nodeId' => $nodeId, 'count' => count($children)]));
    }

    /** Drag re-parent com cycle-check + authz. */
    public function onReparent(int $nodeId, int $newParentId): MadResponse
    {
        if (!$this->draggable) {
            return (new MadResponse())->toast(mad_t('mad.orgchart.drag_off'), 'warning');
        }
        if ($nodeId === $newParentId) {
            return (new MadResponse())->toast(mad_t('mad.orgchart.reparent_invalid'), 'warning');
        }

        $node = $this->_scopedQuery()->find($nodeId);
        $newParent = $newParentId > 0 ? $this->_scopedQuery()->find($newParentId) : null;
        if (!$node || ($newParentId > 0 && !$newParent)) {
            return (new MadResponse())->toast(mad_t('mad.orgchart.node_missing'), 'warning');
        }
        if (!$this->canReparent($node, $newParent)) {
            return (new MadResponse())->toast(mad_t('mad.orgchart.reparent_denied'), 'error');
        }

        // Cycle-check: sobe do novo pai até a raiz; se passar pelo nó, é ciclo.
        if ($newParent && $this->_isDescendantOrSelf($newParentId, $nodeId)) {
            return (new MadResponse())->toast(mad_t('mad.orgchart.reparent_cycle'), 'error');
        }

        $oldParentId = (int) $node->{$this->parentField};
        $node->{$this->parentField} = $newParentId > 0 ? $newParentId : null;
        $node->save();

        $this->afterReparent($node, $oldParentId, $newParentId);

        $this->loadData();
        $this->forceFullRender();
        return (new MadResponse())->toast(mad_t('mad.orgchart.reparented'), 'success');
    }

    /**
     * True se $candidateId é o próprio $nodeId ou descendente dele.
     * Sobe via parentField com guard de profundidade (ciclo pré-existente
     * no dado não trava o loop).
     */
    private function _isDescendantOrSelf(int $candidateId, int $nodeId): bool
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        $current    = $candidateId;
        $guard      = 0;

        while ($current > 0 && $guard++ < 100) {
            if ($current === $nodeId) {
                return true;
            }
            // caminhada crua (sem escopo): integridade estrutural > visibilidade
            $parent = $modelClass::query()->toBase()
                ->where((new $modelClass())->getKeyName(), $current)
                ->value($this->parentField);
            $current = (int) $parent;
        }
        return false;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** JS que despacha um CustomEvent no container deste chart. */
    private function _chartEvent(string $name, array $detail): string
    {
        $key   = json_encode($this->ocCfgKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $event = json_encode($name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $data  = json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        return "document.querySelectorAll('[data-mad-orgchart=' + JSON.stringify({$key}) + ']')"
            . ".forEach(function (el) { el.dispatchEvent(new CustomEvent({$event}, { detail: {$data} })); });";
    }

    private function _assertIdentifier(string $field, string $what): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            throw new \InvalidArgumentException(
                "MadOrgChart: {$what} '{$field}' não é um identificador SQL válido."
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
