<?php

namespace Mad\Grid;

use Mad\Http\MadResponse;
use Mad\I18n\MadLang;
use Mad\Ui\MadToast;

/**
 * MadGridSavedViews — visões salvas da listagem (`<mad-grid saved-views>`).
 *
 * Uma visão guarda, com nome, o jeito como o usuário deixou a grade: valores
 * do bloco de filtros (inclusive período e listas), condições do filtro
 * avançado, busca rápida, ordenação, "por página", colunas escondidas e
 * Tabela/Cartões. Menu "Visões" na barra: aplicar, salvar, renomear, excluir,
 * marcar padrão e voltar à visão do sistema.
 *
 * ┌─ Armazenamento ────────────────────────────────────────────────────────┐
 * │  Mesma tabela e regras dos filtros salvos (GridSavedFilterStore,      │
 * │  mad_grid_filter): por usuário, compartilhada por admin, padrão por   │
 * │  usuário, escopo de tenant/unidade. A chave da grade ganha `#views`   │
 * │  — a lista de visões não se mistura com a de filtros avançados.       │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Tudo é revalidado ao aplicar, contra a tela de AGORA: filtro pelo mesmo
 * coerce da query string, ordenação só em coluna ordenável, condição do filtro
 * avançado só com def viva, coluna escondida só se a coluna existe, "por
 * página" no teto da grade. Visão velha perde só o que caducou.
 *
 * Colunas escondidas e Tabela/Cartões são estado do NAVEGADOR (localStorage):
 * ao salvar, o madDataGrid manda o que está na tela; ao aplicar, o render
 * devolve `gridViews.client` e o madDataGrid regrava o que o usuário vê.
 */
trait MadGridSavedViews
{
    /** `saved-views` do Blade: '' (desligado) | user | shared | everyone. */
    public string $savedViewsMode = '';

    /** Visão aplicada agora (null = a do sistema ou mexida à mão). */
    public ?int $gridViewId = null;

    /** Padrão do usuário já considerado nesta abertura. */
    public bool $gridViewTouched = false;

    /** "Por página" do Blade, para a visão do sistema voltar a ele. */
    public int $gridViewBasePerPage = 0;

    /** Estado do navegador a regravar neste render (só no request que aplicou). */
    protected ?array $_svClientPending = null;

    /** Abertura trouxe filtro no link/params: o padrão não passa por cima. */
    protected bool $_svExplicitOpen = false;

    protected ?array $_svListMemo = null;

    /** Visão padrão com os filtros já postos no mount; o resto espera as colunas (render). */
    protected ?array $_svPendingRow = null;

    /** Props do mecanismo — o navegador não as escreve (ver _isModelAssignable). */
    public const SAVED_VIEW_PROPS = ['savedViewsMode', 'gridViewId', 'gridViewTouched', 'gridViewBasePerPage'];

    // ── Ações ────────────────────────────────────────────────────────────

    /**
     * Salva a visão atual com nome. `$client` = o que só o navegador sabe:
     * {hidden: [campos], mode: table|card}. Mesmo nome sobrescreve.
     */
    public function onGridViewSave(string $name, bool $shared = false, bool $default = false, array $client = []): ?MadResponse
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return null;
        }
        $this->forceFullRender();
        if (GridSavedFilterStore::cleanName($name) === null) {
            return MadToast::warning(MadLang::t('mad.gridview.name_invalid'));
        }

        $id = $ctx['store']->save(
            $ctx['gridKey'],
            $ctx['userId'],
            $name,
            $this->_svSnapshot($client),
            $shared && $ctx['canShare'],
            $default
        );
        if ($id === null) {
            return MadToast::danger(MadLang::t('mad.gridview.save_failed'));
        }
        $this->gridViewId  = $id;
        $this->_svListMemo = null;

        return MadToast::success(MadLang::t('mad.gridview.saved_ok'));
    }

    /** Aplica uma visão visível ao usuário (minha ou compartilhada). */
    public function onGridViewApply(int $id): void
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return;
        }
        $row = $ctx['store']->find($id, $ctx['gridKey'], $ctx['userId']);
        if ($row === null) {
            return;
        }
        $this->forceFullRender();
        $this->_svApply($row);
    }

    /** Volta à visão do sistema: filtros limpos, ordenação e colunas do Blade. */
    public function onGridViewReset(): void
    {
        if ($this->savedViewsMode === '') {
            return;
        }
        $this->forceFullRender();
        $this->gridViewId = null;
        if ($this->gridViewBasePerPage > 0) {
            $this->perPage = $this->_clampPerPage($this->gridViewBasePerPage);
        }
        $this->perPageLocked    = false;
        $this->_svClientPending = ['hidden' => [], 'mode' => ''];
        $this->onLimpar();
    }

    public function onGridViewDelete(int $id): ?MadResponse
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return null;
        }
        $this->forceFullRender();
        if (!$ctx['store']->delete($id, $ctx['gridKey'], $ctx['userId'], $ctx['isAdmin'])) {
            return MadToast::warning(MadLang::t('mad.gridview.not_allowed'));
        }
        if ($this->gridViewId === $id) {
            $this->gridViewId = null;
        }
        $this->_svListMemo = null;

        return MadToast::success(MadLang::t('mad.gridview.deleted_ok'));
    }

    public function onGridViewDefault(int $id): void
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return;
        }
        $this->forceFullRender();
        $ctx['store']->toggleDefault($id, $ctx['gridKey'], $ctx['userId']);
        $this->_svListMemo = null;
    }

    public function onGridViewRename(int $id, string $name): ?MadResponse
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return null;
        }
        $this->forceFullRender();
        if (!$ctx['store']->rename($id, $ctx['gridKey'], $ctx['userId'], $name, $ctx['isAdmin'])) {
            return MadToast::warning(MadLang::t('mad.gridview.name_invalid'));
        }
        $this->_svListMemo = null;

        return null;
    }

    // ── Config / abertura ───────────────────────────────────────────────

    /** `savedViews` do config compilado (inline e MadGrid, mount e hydrate). */
    protected function _applySavedViewsConfig(array $config): void
    {
        $mode = (string) ($config['savedViews'] ?? '');
        if (!in_array($mode, ['user', 'shared', 'everyone'], true)) {
            return;
        }
        $this->savedViewsMode = $mode;
        if ($this->gridViewBasePerPage === 0) {
            $this->gridViewBasePerPage = max(0, (int) ($config['perPage'] ?? $this->perPage));
        }
    }

    /** Marca a abertura que veio com filtro no link (o padrão não a sobrescreve). */
    protected function _svNoteOpenSource(array ...$sources): void
    {
        $names = array_merge(['mes', 'ano', 'dtIni', 'dtFim', 'preset'], $this->discoverFilterProps());
        foreach ($sources as $src) {
            foreach ($names as $n) {
                $v = $src[$n] ?? null;
                if ($v !== null && $v !== '' && $v !== []) {
                    $this->_svExplicitOpen = true;
                    return;
                }
            }
        }
    }

    /**
     * Visão PADRÃO do usuário (minha > compartilhada), uma vez por abertura e
     * só quando o link não trouxe filtro.
     *
     * @return bool true = visão aplicada (o caller recarrega)
     */
    protected function _svApplyDefault(): bool
    {
        // <mad-grid self>: a config do Blade só chega no render, mas o bloco de
        // filtros da tela é desenhado ANTES da grade. A cópia da config que o
        // último render deixou na sessão diz se há visões — com ela, os filtros
        // da visão padrão entram já no mount e a barra nasce com eles.
        if ($this->savedViewsMode === '') {
            $cached = session(static::class . '_dg_cfg');
            if (is_array($cached)) {
                $this->_applySavedViewsConfig($cached);
            }
        }
        if ($this->savedViewsMode === '' || $this->gridViewTouched) {
            return false;
        }
        $this->gridViewTouched = true;
        if ($this->_svExplicitOpen) {
            return false;
        }
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return false;
        }
        $row = $ctx['store']->defaultFor($ctx['gridKey'], $ctx['userId']);
        if ($row === null) {
            return false;
        }
        if ($this->_svColumnFields() === []) {
            // Mount de <mad-grid self>: colunas ainda desconhecidas.
            $this->_svApplyFilters(is_array($row['payload'] ?? null) ? $row['payload'] : []);
            $this->gridViewId    = (int) ($row['id'] ?? 0) ?: null;
            $this->_svPendingRow = $row;
            $this->loadRequested = true;
            $this->_dataLoaded   = false;

            return true;
        }
        $this->_svApply($row, false);

        return true;
    }

    /**
     * Render da grade: completa a visão padrão posta no mount (ordenação,
     * colunas, filtro avançado) ou, na 1ª abertura sem config em sessão,
     * aplica-a inteira e pede ao navegador um redesenho — a barra de filtros
     * já tinha sido desenhada sem ela.
     */
    protected function _svOnRender(): void
    {
        if ($this->_svPendingRow !== null) {
            $row = $this->_svPendingRow;
            $this->_svPendingRow = null;
            $this->_svApplyGrid(is_array($row['payload'] ?? null) ? $row['payload'] : [], $row);
            $this->_dataLoaded = false;

            return;
        }
        if ($this->_svApplyDefault()) {
            if ($this->_svPendingRow !== null) {
                // Sem colunas nem no render (não acontece com config): só filtros.
                $this->_svPendingRow = null;
            }
            $this->_svClientPending = ($this->_svClientPending ?? ['hidden' => [], 'mode' => '']) + ['refresh' => true];
        }
    }

    /**
     * Redesenho pedido pelo navegador depois da visão padrão da 1ª abertura
     * (sessão sem a config da grade). O estado que volta do navegador é o de
     * antes do render da grade — a visão padrão é reaplicada aqui, agora com
     * a config em sessão, e a tela inteira sai com ela.
     */
    public function onGridViewSync(): void
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return;
        }
        $this->forceFullRender();
        $row = $ctx['store']->defaultFor($ctx['gridKey'], $ctx['userId']);
        if ($row !== null) {
            $this->gridViewTouched = true;
            $this->_svApply($row);
        }
    }

    // ── Núcleo ──────────────────────────────────────────────────────────

    /** @return array{store: GridSavedFilterStore, gridKey: string, userId: int, isAdmin: bool, canShare: bool}|null */
    protected function _svContext(): ?array
    {
        if ($this->savedViewsMode === '') {
            return null;
        }
        $uid = GridSavedFilterStore::currentUserId();
        if ($uid === null) {
            return null;
        }
        $isAdmin = GridSavedFilterStore::isAdmin();

        return [
            'store'    => $this->_cfStore(),
            'gridKey'  => $this->_cfGridKey() . '#views',
            'userId'   => $uid,
            'isAdmin'  => $isAdmin,
            'canShare' => $this->savedViewsMode === 'everyone' || ($this->savedViewsMode === 'shared' && $isAdmin),
        ];
    }

    /** Estado atual da grade como payload de visão. */
    protected function _svSnapshot(array $client): array
    {
        $cf = $this->_cfActiveRules();

        return [
            'kind'    => 'view',
            'filters' => $this->filterStateSnapshot(),
            'cf'      => [
                'match' => $this->_cfState()['match'] ?? 'all',
                'rules' => array_map(fn ($pair) => $pair[1], $cf),
            ],
            'search'  => $this->search,
            'sort'    => ['by' => $this->sortBy, 'dir' => $this->sortDir],
            'perPage' => (int) $this->perPage,
            'hidden'  => is_array($client['hidden'] ?? null) ? $client['hidden'] : [],
            'mode'    => is_string($client['mode'] ?? null) ? $client['mode'] : '',
        ];
    }

    /** Aplica o payload de uma visão (já sanitizado pelo store), revalidando tudo. */
    protected function _svApply(array $row, bool $load = true): void
    {
        $p = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $this->_svApplyFilters($p);
        $this->_svApplyGrid($p, $row);

        if ($load) {
            // Aplicar uma visão é ação explícita: numa grade adiada, carrega
            // (o loadData() ainda barra require-filter sem filtro).
            $this->loadData();
        } else {
            // Padrão na abertura: o render carrega (também na grade adiada —
            // a visão escolhida como padrão é a 1ª ação do usuário).
            $this->loadRequested = true;
            $this->_dataLoaded   = false;
        }
    }

    /** Parte da visão que o bloco de filtros mostra (vale já no mount). */
    protected function _svApplyFilters(array $p): void
    {
        // Filtros: a visão é o estado COMPLETO — o que ela não tem fica vazio.
        $this->_cfClearRules();
        $this->_resetNavigationFilters();
        foreach (['mes', 'ano', 'dtIni', 'dtFim', 'preset'] as $k) {
            $this->$k = '';
        }
        foreach ($this->discoverFilterProps() as $name) {
            $this->$name = is_array($this->$name) ? [] : '';
        }
        $this->applyFilterStateSnapshot(is_array($p['filters'] ?? null) ? $p['filters'] : []);
        $this->syncFormFields();
        $this->search = is_string($p['search'] ?? null) ? mb_substr($p['search'], 0, 200) : '';
        $pp = (int) ($p['perPage'] ?? 0);
        if ($pp > 0 && $this->perPage > 0) {
            $this->perPage       = $this->_clampPerPage($pp);
            $this->perPageLocked = true;
        }
        $this->page = 1;
        $this->saveFilterSession();
    }

    /** Parte da visão que depende das colunas e das defs (render ou ação). */
    protected function _svApplyGrid(array $p, array $row): void
    {

        // Filtro avançado: regras contra as defs vivas.
        if ($this->customFilterDefs !== [] && is_array($p['cf'] ?? null)) {
            [$rules, $dropped] = $this->_cfValidateRules(is_array($p['cf']['rules'] ?? null) ? $p['cf']['rules'] : []);
            $this->_cfDropped = $dropped;
            $state = $this->_cfState();
            $state['match']   = ($p['cf']['match'] ?? '') === 'any' ? 'any' : 'all';
            $state['rules']   = $rules;
            $state['viewId']  = null;
            $state['touched'] = true;
            $this->customFilterState = $state;
        }

        $by = is_string($p['sort']['by'] ?? null) ? $p['sort']['by'] : '';
        if ($by !== '' && $this->_isSortableField($by)) {
            $this->sortBy  = $by;
            $this->sortDir = ($p['sort']['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        }

        $fields = $this->_svColumnFields();
        $this->_svClientPending = [
            'hidden' => array_values(array_intersect(is_array($p['hidden'] ?? null) ? $p['hidden'] : [], $fields)),
            'mode'   => in_array($p['mode'] ?? '', ['table', 'card'], true) ? $p['mode'] : '',
        ];
        $this->gridViewId = (int) ($row['id'] ?? 0) ?: null;
    }

    /** Campos das colunas declaradas (allowlist das colunas escondidas). */
    protected function _svColumnFields(): array
    {
        $configs = $this->exportColConfigs;
        if (!empty($configs)) {
            return array_values(array_filter(array_map(fn ($c) => (string) ($c['field'] ?? ''), $configs), 'strlen'));
        }

        return array_values(array_filter(array_map(fn ($c) => (string) $c->field, $this->_effectiveColumns()), 'strlen'));
    }

    /** Dados do menu "Visões" para o Blade da grade. */
    protected function _savedViewsViewData(): array
    {
        $ctx = $this->_svContext();
        if ($ctx === null) {
            return ['gridViews' => null];
        }
        $list = $this->_svListMemo ??= $ctx['store']->list($ctx['gridKey'], $ctx['userId'], $ctx['isAdmin']);
        $current = null;
        foreach (array_merge($list['mine'], $list['shared']) as $v) {
            if ($v['id'] === $this->gridViewId) {
                $current = $v['name'];
            }
        }

        return ['gridViews' => [
            'mine'     => $list['mine'],
            'shared'   => $list['shared'],
            'current'  => $this->gridViewId,
            'name'     => $current,
            'canShare' => $ctx['canShare'],
            'client'   => $this->_svClientPending,
        ]];
    }
}
