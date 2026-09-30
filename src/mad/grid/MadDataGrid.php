<?php
namespace Mad\Grid;
use Mad\Component\MadComponent;
use Mad\Component\MadComponentHandler;
use Mad\Filters\MadFilterable;
use Mad\Filters\MadFiltersTrait;
use Mad\Http\MadStateCrypt;
use Mad\View\MadBlade;


/**
 * MadDataGrid — Componente de tabela reativa com paginação, sort, filtros,
 * edição inline e ações por linha.
 *
 * ┌─ Como usar ─────────────────────────────────────────────────────────────┐
 * │                                                                         │
 * │  class ClienteGrid extends MadDataGrid                                  │
 * │  {                                                                      │
 * │      protected static string $wrapper = self::INTERNAL;                 │
 * │      protected string $model   = 'Cliente';                             │
 * │      protected int    $perPage = 15;                                    │
 * │                                                                         │
 * │      protected function columns(): array                                │
 * │      {                                                                  │
 * │          return [                                                        │
 * │              GridColumn::make('id',   'Cód.')->width(60)->sortable(),   │
 * │              GridColumn::make('nome', 'Nome')->sortable()->filter(),    │
 * │          ];                                                             │
 * │      }                                                                  │
 * │                                                                         │
 * │      protected function actions(): array                                │
 * │      {                                                                  │
 * │          return [                                                        │
 * │              GridAction::make('onEdit')->icon('edit')->label('Editar'), │
 * │          ];                                                             │
 * │      }                                                                  │
 * │                                                                         │
 * │      public function onEdit(int $id): void { ... }                      │
 * │  }                                                                      │
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
abstract class MadDataGrid extends MadComponent implements MadFilterable
{
    use MadFiltersTrait {
        // onLimpar é sobrescrito abaixo (no-auto-load volta ao vazio); o alias
        // preserva o comportamento da trait pro caso padrão.
        MadFiltersTrait::onLimpar as protected _traitOnLimpar;
        // Sobrescritos abaixo para o filtro avançado: a contagem soma as
        // condições do usuário, e a descoberta tira as props dele (ver
        // discoverFilterProps()).
        MadFiltersTrait::totalActiveFilters as protected _traitTotalActiveFilters;
        MadFiltersTrait::discoverFilterProps as protected _traitDiscoverFilterProps;
    }

    // Filtro avançado do usuário final (<mad-custom-filters>).
    use MadGridCustomFilters;

    // ── Props de estado (serializadas e criptografadas) ───────────────────

    public int    $page      = 1;
    public int    $perPage   = 15;
    /**
     * True quando o usuário escolheu explicitamente o tamanho de página via
     * o seletor "por página" (onPerPage). Enquanto false, o per-page declarado
     * no Blade (<mad-grid per-page="N">) é a autoridade do default — inclusive
     * no PRIMEIRO render, em que o loadData() do mount() já rodou com o
     * default 15 antes de o _renderInlineGrid() ver o config do Blade.
     * Serializado no mad_state, então sobrevive ao ciclo AJAX.
     */
    public bool   $perPageLocked = false;
    /**
     * Carga adiada — <mad-grid no-auto-load> ("Carregar registros ao abrir = Não"
     * do 4.0). Com $autoLoad=false o grid abre VAZIO sem tocar o banco; a
     * primeira ação explícita do usuário (Buscar/busca rápida/filtro de coluna/
     * sort/refresh) chama loadData(), que arma $loadRequested — daí em diante
     * paginar/ordenar recarrega normalmente. Limpar filtros volta ao vazio.
     * Ambas serializadas no mad_state: o Blade só é visto no primeiro render
     * (_renderInlineGrid) e o hydrate() dos AJAX seguintes precisa da decisão.
     * $loadRequested NÃO vai pra sessão (_saveToSession): toda abertura da
     * página começa adiada. Subclasse manual que chama parent::mount() pode
     * declarar `public bool $autoLoad = false;` pra pular a query do mount.
     */
    public bool   $autoLoad      = true;
    public bool   $loadRequested = false;
    /**
     * Filtro obrigatório — <mad-grid require-filter>. Implica $autoLoad=false e
     * vai além: NENHUMA ação (Carregar registros, Aplicar/Atualizar da sidebar,
     * busca rápida, sort, paginação, por página) consulta o banco sem ao menos
     * um filtro do usuário preenchido — a guarda mora em loadData(), por onde
     * todos os caminhos passam. $requireFilterFields (require-filter-fields=
     * "nome,cpf") restringe quais campos liberam a consulta; basta um deles.
     * Públicas porque as ações AJAX decidem sem ver o Blade.
     */
    public bool   $requireFilter       = false;
    public array  $requireFilterFields = [];
    public string $sortBy    = '';
    public string $sortDir   = 'asc';
    public array  $filters   = [];
    public array  $filterOps = [];  // operador por campo: '=', 'like', '>=', etc.
    public string $search    = '';
    public array  $colFilters = [];  // filtros de coluna criptografados: [field => {field, op, value, sub?}]

    // ── Seleção de linhas (<mad-grid selectable>) ─────────────────────────

    /**
     * Coluna de checkbox + barra de seleção. Vem do Blade no primeiro render
     * (`selectable`) e fica no state: os AJAX seguintes decidem sem o Blade.
     * Subclasse com columns() pode declarar `public bool $selectable = true;`.
     */
    public bool $selectable = false;

    /**
     * Ids marcados (LISTA de strings — lista de propósito: as chaves de prop
     * array pública são achatadas no contexto da view, e um id textual viraria
     * variável). Fonte da verdade no servidor: o campo oculto `__mad_grid_sel`
     * que viaja em toda requisição do grid (ver _syncSelection). Leia com
     * selectedIds(), que devolve o formato [id => id].
     */
    public array $selection = [];

    /**
     * Ações em lote (`<mad-bulk-action>`): label/icon/variant/confirm/min e
     * `method` OU `target`+`targetMethod`. No state porque o onBulkOpen() do
     * AJAX resolve o alvo pelo ÍNDICE — o alvo nunca vem do navegador.
     */
    public array $bulkActions = [];

    /** A seleção desta requisição já foi lida (campo oculto ou sessão)? */
    private bool $_selectionSynced = false;

    // ── Inline config (definida via <mad-grid self> no Blade) ─────────────

    /** Config de colunas/ações injetada pelo Blade (<mad-grid self>). */
    protected ?array $_inlineConfig = null;

    /** True quando esta requisição tentou carregar sem o filtro exigido (aviso no Blade). */
    protected bool $_filterMissing = false;

    // ── Configurações da subclasse (não serializadas no state) ───────────

    /** Classe do model (repositório). Obrigatório se não sobrescrever query(). */
    protected string $model = '';

    /**
     * Relações Eloquent pra eager-load na query da página (anti-N+1).
     * Transforms que navegam relação ($row['__record']->cliente->nome)
     * leem do cache do eager load em vez de 1 SELECT por linha.
     */
    protected array $with = [];

    /** Campo para quebra de grupo. */
    protected string|array $groupBy = '';

    /** Template de exibição do grupo. Padrão: valor do campo. */
    protected string|array $groupMask = '';

    /** Totalizar por grupo. */
    protected bool $groupTotal = false;

    /**
     * Banda da quebra: 'inline' (label + totais em texto corrido, default
     * histórico) ou 'cells' (cada total sob a sua coluna, rótulo em colspan).
     */
    protected string $groupBand = '';

    /**
     * Rótulo do sub-total da quebra. Aceita `{group}` (o label do grupo) e a
     * mesma sintaxe de máscara do group-mask. Vazio = rótulo histórico
     * ("TOTAL: <grupo>").
     */
    protected string $groupTotalLabel = '';

    /**
     * Máscara da 2ª linha descritiva por registro ('{descricao} conf. {doc}').
     * Vazio = sem linha extra. Vale na tela e no PDF; CSV/XLSX ignoram (linha
     * a mais quebraria tabela dinâmica / importação).
     */
    protected string $rowDetail = '';

    /** Barra de busca rápida. */
    protected bool $searchable = false;

    /** Campos para busca global. Se vazio, detecta automaticamente das colunas. */
    public array $searchColumns = [];

    /** Botões de exportação (CSV, XLSX, PDF). */
    protected bool $exportable = true;

    /** Botão de refresh na toolbar. Opt-in via <mad-grid refreshable>. Chama onReload(). */
    protected bool $refreshable = false;

    /** Título da exportação (aparece no header do PDF e no sheet name do XLSX).
     *  Se vazio, usa $title ou nome da classe. */
    protected string $exportTitle = '';

    /** Nome-base do arquivo exportado (sem extensao).
     *  Se vazio, usa $exportTitle. Ex: 'relatorio-clientes' → relatorio-clientes.csv/xlsx/pdf. */
    protected string $exportFilename = '';

    /** Subtítulo do relatório exportado — alimenta o {SUBTITLE} das bandas PDF. */
    protected string $exportSubtitle = '';

    /**
     * Metadados do relatório que o AJAX de export precisa: título, nome do
     * arquivo, subtítulo, período e filtros já RESOLVIDOS em texto, e as
     * opções de quebra/linha descritiva.
     *
     * Pública pelo mesmo motivo do exportColConfigs/exportGroupConfig: prop
     * protegida não é serializada no mad_state, e o handler de export não
     * re-renderiza o Blade — sem isto o PDF sairia sem período nem filtros.
     */
    public array $exportMeta = [];

    /** Sticky para thead e linhas de quebra ao rolar. */
    protected bool $sticky = false;

    /** Lado das ações: 'left' | 'right' */
    protected string $actionSide = 'right';

    /** Conexão (database) do grid. Padrão: MAIN_DATABASE ou 'business'. */
    protected string $database = '';

    /** Persistir filtros, sort e paginação na sessão entre page loads. */
    protected bool $rememberFilters = false;

    // ── MadFiltersTrait — props que a trait espera no host ──────────────
    // Em listagens, o periodType default e 'none' (sem filtro automatico de periodo).
    // Subclasse seta 'date-range' / 'preset' / 'month-year' quando quiser usar.

    /** Tipo do filtro de periodo: 'month-year' | 'date-range' | 'preset' | 'none'. */
    protected string $periodType = 'none';

    /** Campo de data usado em modos date-range e preset. */
    protected string $dateField = '';

    /** Colunas denormalizadas pra mes/ano (modo 'month-year'). */
    protected array $periodFields = ['mes' => 'mes', 'ano' => 'ano'];

    /** Mes/ano atuais como default no mount(). */
    protected bool $defaultToCurrentPeriod = false;

    /** Habilita dropdown de presets dentro do period-filter blade. */
    protected bool $usePresets = false;

    /** Aplica filtro automatico por unit (multi-tenant). */
    protected bool $applyUnitFilter = false;

    /** Nome da coluna de unit no model alvo. */
    protected string $unitField = 'unit_id';

    /** Per-model override do unitField. */
    protected array $unitFields = [];

    /** Props excluidas da auto-discovery — subclasse handle em onSearch(). */
    protected array $skipAutoFilter = [];

    /**
     * Auto-merge dos filtros declarados via <mad-dash-filters> na query.
     * Quando true, _buildQuery() aplica os auto-filters no builder antes do onSearch().
     * Listagens que ja fazem tudo no onSearch() podem desligar.
     */
    protected bool $autoMergeDashFilters = true;

    /** Config de colunas serializado para uso na exportação (preenchido no primeiro render). */
    public array $exportColConfigs = [];

    /**
     * Config de agrupamento serializada (by/mask/total) para a exportação.
     * groupBy/groupMask/groupTotal são protegidos (não serializam no state), e o
     * AJAX de export NÃO chama _renderInlineGrid — sem isto a quebra some do
     * CSV/XLSX/PDF. Espelha o exportColConfigs: preenchido no render, restaurado
     * no _loadAllForExport.
     */
    public array $exportGroupConfig = [];

    /**
     * ORDER BY declarativo do Blade (<mad-grid order-by="data_venda desc">).
     * Substitui o :query closure em grids paginados: closure não serializa no
     * mad_state e era silenciosamente ignorada. Público → serializado (o state é
     * criptografado) → sobrevive ao AJAX de paginação e ao export. Sintaxe
     * "campo [asc|desc]" (a mesma do defaultSort); validado pelo OrderGuard no
     * _buildQuery — nunca vai cru pro SQL. Precedência: clique do usuário
     * (sortBy) > baseOrder (Blade) > defaultSort (subclasse).
     */
    public string $baseOrder = '';

    /**
     * Filtros declarativos do Blade (:filters="[['status','=','pago']]") — a
     * mesma DSL do mad-db-metric-card/mad-db-chart. Público → serializado →
     * sobrevive ao AJAX. Aplicado no _buildQuery via QuerySource com subselect
     * DESABILITADO: a prop round-tripa pelo client dentro do mad_state; mesmo
     * criptografado, fail-closed — filtro declarativo não precisa de subselect.
     */
    public array $baseFilters = [];

    // ── Dados de render (não serializados) ───────────────────────────────

    protected array $_rows         = [];
    protected array $_rawItems     = [];  // items originais (objetos do model) para re-normalize
    protected int   $_total        = 0;
    private   bool  $_dataLoaded   = false;
    protected array $_renderFields = [];  // campos {relacao->campo} detectados das colunas
    /** Relacoes derivadas das chains (colunas + quebra) para o with() da consulta. */
    protected array $_autoWith = [];
    private bool $_renderFieldsDetected = false;

    /** @internal Sinaliza ao MadComponentHandler que dados internos foram carregados. */
    /** Quando true, _needsFullRender retorna false mesmo com _dataLoaded — usado por onInlineSave. */
    private bool $_skipFullRender = false;

    public function _needsFullRender(): bool {
        return $this->_forceFullRender || ($this->_dataLoaded && !$this->_skipFullRender);
    }

    /**
     * Ação que só devolve ops (abrir drawer/modal, toast, html parcial) SEM
     * redesenhar a grid. Sem isto o re-render completo substitui o DOM alvo
     * das ops (ex.: `<div id="…">` dentro de um `<mad-drawer>`) DEPOIS de
     * `->html()` ter aplicado — o drawer abre vazio.
     */
    protected function skipFullRender(): void
    {
        $this->_skipFullRender = true;
    }

    // ── Carga adiada (no-auto-load) ──────────────────────────────────────

    /** True quando o grid pode consultar o banco sem ação explícita do usuário. */
    protected function _autoLoadAllowed(): bool
    {
        return $this->autoLoad || $this->loadRequested;
    }

    /** True enquanto o grid no-auto-load ainda não recebeu a primeira ação. */
    protected function _isDeferred(): bool
    {
        return !$this->_autoLoadAllowed();
    }

    /**
     * Volta ao estado "abre vazio": descarta as linhas em memória, desarma o
     * loadRequested e força re-render (sem _dataLoaded o handler não redesenha).
     */
    protected function _resetToDeferred(): void
    {
        $this->_rows      = [];
        $this->_rawItems  = [];
        $this->_total     = 0;
        $this->loadRequested = false;
        $this->page = 1;
        $this->forceFullRender();
    }

    /**
     * True quando o usuário preencheu ao menos um filtro (require-filter).
     *
     * Com require-filter-fields, só os campos listados contam. Sem lista, conta
     * qualquer filtro do USUÁRIO: busca rápida, filtro de coluna, período e as
     * props/campos de formulário da tela. As props do próprio grid (sortDir,
     * gridConfig, perPage…) ficam de fora — é por isso que totalActiveFilters()
     * não serve aqui: ele as conta e nunca chega a zero. Regras de carregamento
     * (:filters / baseFilters) também não contam: não foram escolha do usuário.
     */
    protected function _hasUserFilter(): bool
    {
        $formData = (isset($this->form) && $this->form instanceof \Mad\Form\MadForm)
            ? (array) $this->form->getDataRaw()
            : [];

        if (!empty($this->requireFilterFields)) {
            foreach ($this->requireFilterFields as $name) {
                $name = (string) $name;
                $candidates = [
                    property_exists($this, $name) && !str_starts_with($name, '_')
                        ? ($this->{$name} ?? null) : null,
                    $formData[$name] ?? null,
                    $this->colFilters[$name]['value'] ?? null,
                    $this->filters[$name] ?? null,
                ];
                foreach ($candidates as $v) {
                    if (!static::_filterValueIsEmpty($v)) return true;
                }
            }
            // Condição do filtro avançado sobre um dos campos listados também
            // libera (casa pela chave/campo da def: `nome`, `cidade->nome`).
            foreach ($this->_cfActiveRules() as [$def]) {
                if (in_array($def['key'], $this->requireFilterFields, true)
                    || in_array($def['field'], $this->requireFilterFields, true)) {
                    return true;
                }
            }
            return false;
        }

        if (trim($this->search) !== '') return true;
        // Filtro avançado: condição VÁLIDA (revalidada contra as defs) conta
        // como filtro do usuário — "está vazio" não tem valor e mesmo assim
        // restringe a consulta.
        if ($this->_cfActiveRules() !== []) return true;
        foreach ($this->colFilters as $f) {
            if (!static::_filterValueIsEmpty($f['value'] ?? null)) return true;
        }
        foreach ($this->filters as $v) {
            if (!static::_filterValueIsEmpty($v)) return true;
        }
        if ($this->_periodIsActive()) return true;

        $own = static::_gridOwnPublicProps();
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $name = $p->getName();
            if ($p->isStatic() || $name === '' || $name[0] === '_' || isset($own[$name])) continue;
            if (!$p->isInitialized($this)) continue;
            $v = $p->getValue($this);
            // Mesmo recorte do discoverFilterProps: filtro de tela é string/array.
            if (!is_string($v) && !is_array($v)) continue;
            if (!static::_filterValueIsEmpty($v)) return true;
        }
        foreach ($formData as $name => $v) {
            if (isset($own[$name]) || in_array($name, ['mes', 'ano', 'dtIni', 'dtFim', 'preset'], true)) continue;
            if (!static::_filterValueIsEmpty($v)) return true;
        }
        return false;
    }

    /** Config do Blade vista no render: inline (<mad-grid self>) ou standalone (gridConfig). */
    protected function _bladeGridConfig(): array
    {
        if (is_array($this->_inlineConfig)) return $this->_inlineConfig;
        return (property_exists($this, 'gridConfig') && is_array($this->gridConfig)) ? $this->gridConfig : [];
    }

    /** Botão "Carregar registros" do estado vazio: some com no-load-button e sempre com require-filter. */
    protected function _showLoadButton(): bool
    {
        return !$this->requireFilter && (($this->_bladeGridConfig()['loadButton'] ?? true) !== false);
    }

    /** Texto personalizado da listagem vazia (load-hint); '' = texto traduzido padrão. */
    protected function _loadHint(): string
    {
        return trim((string)($this->_bladeGridConfig()['loadHint'] ?? ''));
    }

    /**
     * Props públicas que pertencem ao framework (MadDataGrid, MadGrid e
     * ancestrais) — nunca são filtro do usuário. Props declaradas pela
     * subclasse da tela (ex.: bloco grid-filter-props da listagem gerada) são.
     *
     * @return array<string, true>
     */
    protected static function _gridOwnPublicProps(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        foreach ([self::class, MadGrid::class] as $cls) {
            if (!class_exists($cls)) continue;
            foreach ((new \ReflectionClass($cls))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                $cache[$p->getName()] = true;
            }
        }
        return $cache;
    }

    // ── Configuração de sort padrão ────────────────────────────────────────

    /** Sort padrão quando o usuário não clicou em nenhuma coluna. Ex: 'ano DESC, mes ASC' */
    protected string $defaultSort = '';

    // ── Critério de busca (montado pelo onSearch, não serializado) ──────

    /**
     * Filtro de busca Eloquent: closure fn(Builder $q) montada pelo onSearch().
     * Aplicada no caminho Eloquent do _autoQuery(). Não serializada (reconstruída
     * por request).
     * @var callable|null
     */
    protected $searchQuery = null;

    // ── API — sobrescreva nas subclasses ─────────────────────────────────

    /**
     * Retorna a lista de GridColumn para a tabela.
     * Pode ser omitido se as colunas forem declaradas via <mad-grid self> no Blade.
     * @return GridColumn[]
     */
    protected function columns(): array { return []; }

    /**
     * Retorna ações simples por linha.
     * @return GridAction[]
     */
    protected function actions(): array { return []; }

    /**
     * Retorna grupos de ações dropdown por linha.
     * @return GridActionGroup[]
     */
    protected function actionGroups(): array { return []; }

    /**
     * Monta os filtros de busca no builder, via closure em $this->searchQuery.
     *
     * Chamado automaticamente pelo _buildQuery() a cada load/reload.
     * O botão "Buscar" do form chama onReload() (mad:submit="onReload"),
     * que reseta a página e recarrega os dados.
     *
     * Subclasses sobrescrevem para montar filtros a partir das props de busca:
     *
     *   public function onSearch(): void
     *   {
     *       $this->searchQuery = function ($q) {
     *           $q->where('ativo', '=', '1');
     *           if ($this->busca) $q->where('nome', 'like', "%{$this->busca}%");
     *       };
     *   }
     */
    public function onSearch(): void
    {
        // Default: sem filtro de busca customizado.
        // Subclasses montam $this->searchQuery (closure no builder) aqui.
    }

    /**
     * Recarrega o grid resetando a página para 1.
     * Use como action do botão "Buscar": mad:submit="onReload"
     */
    public function onReload(): void
    {
        $this->page = 1;
        $this->loadData();
    }

    /**
     * Customiza a query. Monte e execute o builder com filtros e paginação,
     * e retorne ['items' => [...], 'total' => int].
     *
     * Se não sobrescrever, usa _autoQuery() com $this->model automaticamente.
     */
    protected function query(): array
    {
        return [];  // hook de extensão — vazio sinaliza usar _autoQuery (builder-native)
    }

    /**
     * Decora UM registro para a linha reapresentada pelo `manageRow`.
     *
     * Existe porque os dois caminhos de render não passam pelo mesmo lugar: a
     * listagem inteira nasce do `query()` do control (que é onde a subclasse
     * carimba coluna VIRTUAL — a que não existe no banco), enquanto o
     * `renderSingleRow()` refaz o registro sozinho, com `find($id)`, e pulava
     * essa decoração. Resultado: a linha voltava do `manageRow` com as colunas
     * virtuais VAZIAS — badge em branco, `when-field` lendo '' e anunciando o
     * rótulo errado na ação — e só se corrigia no F5. O sintoma era discreto
     * (uma linha certa, a que acabou de mudar errada) e não havia erro nem log.
     *
     * Quem carimba no `query()` sobrescreve aqui com a MESMA regra, sobre um
     * item só:
     *
     *     protected function decorateRow(array|object $record): array|object
     *     {
     *         return $this->withPasswordStatus([$record])[0] ?? $record;
     *     }
     *
     * Default: no-op. Grid que não sobrescreve renderiza exatamente como antes.
     *
     * @param  array|object  $record  registro recém-carregado (Eloquent, em regra)
     * @return array|object           o mesmo registro decorado, ou um array de
     *                                atributos que prevalece sobre os do banco
     */
    protected function decorateRow(array|object $record): array|object
    {
        return $record;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        // MadFiltersTrait — init form + carrega state de filtros
        $this->_initFiltersForm();

        // Period default — so se subclasse nao seedou
        if ($this->defaultToCurrentPeriod && $this->mes === '' && $this->ano === '') {
            $this->mes = date('m');
            $this->ano = date('Y');
        }

        // Filter session (so se rememberFilters=true)
        $this->loadFilterSession();

        // Request hydrate
        $req = array_merge($_GET ?? [], $_POST ?? []);
        $this->hydrateFiltersFromArray($req);
        $this->hydrateFiltersFromArray($params);

        $this->syncFormFields();

        // Grid pagination/sort session + load (adiado com no-auto-load)
        $this->_restoreFromSession();
        // Filtro avançado padrão do usuário: só tem efeito quando as defs já
        // são conhecidas aqui (grid standalone — o MadGrid as aplica antes do
        // parent::mount()). No <mad-grid self> quem aplica é o render.
        $this->_cfApplyDefaultFilter();
        if ($this->_autoLoadAllowed()) {
            $this->loadData();
        }
    }

    public function hydrate(): void
    {
        // Antes de qualquer ação: o método chamado (ação em lote, botão do
        // cabeçalho, ação de linha) já enxerga a seleção que o usuário vê.
        $this->_syncSelection();
        if ($this->_autoLoadAllowed()) {
            $this->loadData();
        }
    }

    // ── Seleção de linhas ────────────────────────────────────────────────

    /**
     * Ids marcados no grid — formato `[id => id]` (strings), como o
     * TCheckGroup/sessão do 4.0: percorra com foreach, passe a whereIn/count,
     * teste com isset($ids[$id]).
     *
     * É entrada do usuário (veio do navegador): autorize e filtre no método
     * que processa, como faria com qualquer parâmetro.
     */
    public function selectedIds(): array
    {
        $this->_syncSelection(true);

        return MadGridSelection::normalize($this->selection);
    }

    /** Substitui a seleção (vale para a tela e para quem lê de outra tela). */
    public function setSelectedIds(mixed $ids): void
    {
        $this->_selectionSynced = true;
        $norm = MadGridSelection::normalize($ids);
        $this->selection = array_values($norm);
        MadGridSelection::put($this->_selectionKey(), $norm);
    }

    /** Desmarca tudo (ex.: depois de processar o lote). */
    public function clearSelection(): void
    {
        $this->setSelectedIds([]);
    }

    /**
     * Ação em lote com `target=`: abre a tela passando `ids` ("1,2,3").
     *
     * Passa pelo servidor de propósito: esta requisição traz a seleção no
     * campo oculto e a grava na sessão ANTES de abrir o destino — quem lê por
     * MadGridSelection::get() na tela aberta vê exatamente o que foi marcado.
     * O alvo vem do state (índice), nunca do navegador.
     */
    public function onBulkOpen(int $index): \Mad\Http\MadResponse
    {
        $cfg = $this->bulkActions[$index] ?? null;
        $target = is_array($cfg) ? (string) ($cfg['target'] ?? '') : '';
        if ($target === '') {
            return (new \Mad\Http\MadResponse())->toast(__('grid.bulk_unavailable'), 'warning');
        }

        $ids = $this->selectedIds();
        $min = (int) ($cfg['min'] ?? 1);
        if (count($ids) < $min) {
            return (new \Mad\Http\MadResponse())->toast(trans_choice('grid.bulk_min', $min, ['count' => $min]), 'warning');
        }

        return \Mad\Http\MadResponse::open(
            $target,
            [MadGridSelection::PARAM => implode(',', $ids)],
            (string) ($cfg['targetMethod'] ?? 'show') ?: 'show'
        );
    }

    /**
     * Chave da seleção na sessão: a classe do grid (a que outra tela passa
     * para MadGridSelection::get()). O MadGrid declarativo usa a tela dona.
     */
    protected function _selectionKey(): string
    {
        return static::class;
    }

    /**
     * Lê a seleção UMA vez por requisição:
     *  - AJAX do grid: o campo oculto `__mad_grid_sel[<chave>]` manda (JSON)
     *    e é espelhado na sessão — outra tela passa a enxergá-lo;
     *  - primeira abertura (GET): restaura da sessão — marcar, sair e voltar
     *    mantém a seleção (como o modo "session" do 4.0).
     */
    protected function _syncSelection(bool $force = false): void
    {
        if ($this->_selectionSynced) {
            return;
        }

        $field = $_POST[MadGridSelection::REQUEST_FIELD] ?? null;
        $raw   = is_array($field) ? ($field[$this->_selectionStorageKey()] ?? null) : null;
        if (is_string($raw)) {
            $this->_selectionSynced = true;
            $ids = MadGridSelection::normalize($raw);
            $this->selection = array_values($ids);
            MadGridSelection::put($this->_selectionKey(), $ids);
            return;
        }

        // Grid sem seleção não paga a leitura da sessão. No primeiro render o
        // `selectable` do Blade só chega no _renderInlineGrid — por isso aqui
        // não se marca "lido": o render lê depois de conhecer a flag.
        if (!$this->selectable && !$force) {
            return;
        }
        $this->_selectionSynced = true;

        // Sem o campo (primeira abertura): a sessão manda — inclusive quando
        // outra tela esvaziou a seleção (MadGridSelection::clear) depois de
        // processar o lote.
        $this->selection = array_values(MadGridSelection::get($this->_selectionKey()));
    }

    /** Nome do campo oculto no DOM (sem barra invertida, igual ao storageKey da view). */
    protected function _selectionStorageKey(): string
    {
        return str_replace('\\', '_', static::class);
    }

    /** Config de seleção para a view (data-grid.blade.php / data-grid-row). */
    protected function _selectionViewData(): array
    {
        if (!$this->selectable) {
            return ['selectable' => false, 'selected' => [], 'bulkActions' => [], 'selectionField' => ''];
        }
        $this->_syncSelection();

        // Ação em lote de método barrada pelo perfil: o botão fica cinza com o
        // motivo (mesma regra das ações de linha) — o gate recusaria o clique.
        $bulk = array_values($this->bulkActions);
        foreach ($bulk as &$b) {
            $b['deny'] = '';
            $method = (string) ($b['method'] ?? '');
            if ($method === '') continue;
            try {
                $key = \Mad\Security\PermissionGate::deniedActionKey($this, $method);
                if ($key !== null) $b['deny'] = \Mad\Security\ActionVocab::denyTitle($key);
            } catch (\Throwable $e) {
                // sem sessão de permissões: liberado, como as ações de linha
            }
        }
        unset($b);

        return [
            'selectable'     => true,
            'selected'       => array_values(array_map('strval', $this->selection)),
            'bulkActions'    => $bulk,
            'selectionField' => MadGridSelection::REQUEST_FIELD . '[' . $this->_selectionStorageKey() . ']',
        ];
    }

    /**
     * Normaliza a config de <mad-bulk-action> (compilador) — só as chaves
     * conhecidas, `method` XOR `target`.
     */
    protected static function _normalizeBulkActions(array $raw): array
    {
        $out = [];
        foreach ($raw as $b) {
            if (!is_array($b)) continue;
            $method = (string) ($b['method'] ?? '');
            $target = (string) ($b['target'] ?? '');
            if ($method === '' && $target === '') continue;
            $out[] = [
                'label'        => (string) ($b['label'] ?? ($method ?: $target)),
                'icon'         => (string) ($b['icon'] ?? ''),
                'variant'      => (string) ($b['variant'] ?? 'primary'),
                'confirm'      => (string) ($b['confirm'] ?? ''),
                'min'          => max(0, (int) ($b['min'] ?? 1)),
                'method'       => $method,
                'target'       => $method === '' ? $target : '',
                'targetMethod' => $method === '' ? (string) ($b['targetMethod'] ?? 'show') : '',
            ];
        }
        return $out;
    }

    /**
     * Props de configuração da seleção não vêm do cliente: o alvo das ações em
     * lote e a própria chave de ligar a coluna só mudam pelo Blade/servidor.
     * A seleção em si chega pelo campo oculto dedicado, não por mad:model.
     */
    protected function _isModelAssignable(string $prop): bool
    {
        if (in_array(strtolower($prop), ['selectable', 'selection', 'bulkactions'], true)) {
            return false;
        }
        return parent::_isModelAssignable($prop);
    }

    /**
     * MadComponent hook — espelha mudancas em form->fields automaticamente.
     */
    public function updated(string $prop, mixed $value): void
    {
        parent::updated($prop, $value);
        $this->_filtersUpdatedHook($prop, $value);
    }

    /**
     * MadFiltersTrait hook — filtros mudaram (onShow/onLimpar/setProp/etc).
     * Default da trait e forceFullRender(); pra grid resetamos pagina + recarregamos.
     */
    protected function applyFiltersChanged(): void
    {
        $this->page = 1;
        $this->loadData();
    }

    // ── Persistência de filtros na sessão ────────────────────────────────

    /** Salva o estado público (filtros, sort, page) na sessão. */
    protected function _saveToSession(): void
    {
        if (!$this->rememberFilters) return;

        $key   = static::class . '_grid_state';
        $state = $this->_getState(); // herda de MadComponent — todas as props públicas

        // Remove props gerenciadas pela MadFiltersTrait (persistidas em filter session separada)
        if (is_array($state)) {
            foreach (['form', 'mes', 'ano', 'dtIni', 'dtFim', 'preset'] as $k) {
                unset($state[$k]);
            }
            // Toda abertura de um grid no-auto-load começa adiada.
            unset($state['loadRequested']);
        }

        session([$key => $state]);
    }

    /** Props reservadas pelo MadFiltersTrait — restauradas via loadFilterSession(). */
    private const _GRID_RESERVED_FILTER_PROPS = ['form', 'mes', 'ano', 'dtIni', 'dtFim', 'preset'];

    /** Restaura o estado da sessão no mount (primeira carga). */
    protected function _restoreFromSession(): void
    {
        if (!$this->rememberFilters) return;

        $key   = static::class . '_grid_state';
        $state = session($key);

        if (!is_array($state) || empty($state)) return;

        $this->_restoreProps($state);
    }

    /** Aplica um estado salvo nas props públicas de tipo escalar/array do grid. */
    private function _restoreProps(array $state): void
    {
        foreach ($state as $name => $value) {
            if (!is_string($name) || !property_exists($this, $name)) continue;
            // Skip props gerenciadas pela trait (form + period state) — restauradas via loadFilterSession()
            if (in_array($name, self::_GRID_RESERVED_FILTER_PROPS, true)) continue;
            $rp = new \ReflectionProperty($this, $name);
            if (!$rp->isPublic() || $rp->isStatic()) continue;
            // Skip props com tipo de classe (ex: MadForm) — nao restauraveis via array
            $type = $rp->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) continue;
            try {
                $this->$name = $value;
            } catch (\TypeError) {
                // Sessão de uma versão anterior da classe (prop que mudou de
                // tipo): mantém o default em vez de derrubar a tela.
            }
        }
    }

    // ── Volta de formulário (?_mad_return=) ──────────────────────────────

    /**
     * Parâmetro da VOLTA: a listagem reabre na página, ordem, busca e filtros
     * em que o usuário estava, e destaca a linha cujo id veio no valor.
     *
     *   return (new MadResponse())
     *       ->toast('Registro salvo com sucesso!', 'success')
     *       ->redirect('ClienteList', ['_mad_return' => $this->recordId]);
     *
     * É o "voltar" do formulário de PÁGINA INTEIRA (INTERNAL): ele substitui a
     * listagem na tela (Mad.go → _injectFull), então não há grid montado pra
     * receber um manageRow(). Formulário em gaveta/modal continua com
     * closeDrawer() + manageRow() — a listagem nunca saiu da tela.
     *
     * Só a navegação que traz o parâmetro restaura: abrir a listagem pelo menu
     * continua começando do zero (diferente do $rememberFilters, que vale pra
     * toda abertura). `?_mad_return=` sem valor = volta sem destaque.
     */
    public const RETURN_PARAM = '_mad_return';

    /**
     * O que a volta restaura: o estado de NAVEGAÇÃO do usuário. Config que vem
     * do Blade/código (modelo, colunas, defs do filtro avançado) fica de fora —
     * o render reaplica e uma versão velha na sessão não pode vencer o deploy.
     */
    private const _RETURN_PROPS = [
        'page', 'perPage', 'perPageLocked', 'loadRequested',
        'sortBy', 'sortDir', 'search', 'filters', 'filterOps', 'colFilters',
        'customFilterState',
    ];

    /** A requisição corrente é uma volta — capturado no show(), consumido 1×. */
    private bool $_returnPending = false;

    /** Suspende o snapshot da volta (o export carrega tudo com perPage=0). */
    private bool $_returnSnapshotSuspended = false;

    /**
     * Linha a destacar na volta (id do registro salvo). Só vale no render da
     * própria volta: não é pública, então não viaja no mad_state.
     */
    protected int|string|null $focusRowId = null;

    public function show(array $params = []): void
    {
        $this->_captureReturn(array_merge($_REQUEST, $params));
        parent::show($params);
    }

    /** Lê `?_mad_return=`: presença = volta; valor não vazio = linha a destacar. */
    protected function _captureReturn(array $request): void
    {
        if (!array_key_exists(self::RETURN_PARAM, $request)) return;

        $this->_returnPending = true;
        $id = $request[self::RETURN_PARAM];
        if ((is_int($id) || is_string($id)) && $id !== '' && strlen((string) $id) <= 64) {
            $this->focusRowId = $id;
        }
    }

    /** Estado de navegação da última carga — vale pra toda listagem, sem opt-in. */
    protected function _saveReturnSnapshot(): void
    {
        if ($this->_returnSnapshotSuspended) return;

        $grid = [];
        foreach (self::_RETURN_PROPS as $name) {
            if (property_exists($this, $name)) {
                $grid[$name] = $this->$name;
            }
        }

        session([static::class . '_grid_return' => [
            'grid'    => $grid,
            'filters' => $this->filterStateSnapshot(),
        ]]);
    }

    /**
     * Aplica o snapshot da volta UMA vez, antes da carga do primeiro render.
     * True quando aplicou — o chamador recarrega e roda _afterReturnLoad().
     */
    protected function _applyReturnSnapshot(): bool
    {
        if (!$this->_returnPending) return false;
        $this->_returnPending = false;

        $snap = session(static::class . '_grid_return');
        if (!is_array($snap)) return false;

        if (is_array($snap['filters'] ?? null)) {
            $this->applyFilterStateSnapshot($snap['filters']);
            $this->syncFormFields();
        }
        $grid = is_array($snap['grid'] ?? null) ? $snap['grid'] : [];
        $this->_restoreProps(array_intersect_key($grid, array_flip(self::_RETURN_PROPS)));

        $this->_dataLoaded = false;
        return true;
    }

    /**
     * Depois da carga da volta: a página guardada pode não existir mais (o
     * registro saiu do filtro, alguém excluiu linhas) — cai na última.
     */
    protected function _afterReturnLoad(): void
    {
        if (!$this->_dataLoaded) return;

        if ($this->perPage > 0 && $this->page > 1) {
            $last = max(1, (int) ceil($this->_total / $this->perPage));
            if ($this->page > $last) {
                $this->page = $last;
                $this->loadData();
            }
        }

        $this->_ensureFocusRow();
    }

    /**
     * Registro salvo que não está na página restaurada (novo, ou a ordenação o
     * levou pra outra página) entra no TOPO — o mesmo que o manageRow() faz no
     * formulário em gaveta. Busca com a MESMA consulta do grid: registro que não
     * casa com os filtros não aparece.
     *
     * Fica de fora quando a posição da linha é parte do dado (quebra, saldo
     * acumulado) e quando a subclasse monta a própria consulta (query()).
     */
    protected function _ensureFocusRow(): void
    {
        $id = $this->focusRowId;
        if ($id === null || empty($this->model) || !empty($this->groupBy)) return;

        $idField = $this->rowIdField();
        foreach ($this->_rows as $row) {
            if ((string) ($row[$idField] ?? $row['id'] ?? '') === (string) $id) return;
        }

        if ((new \ReflectionMethod($this, 'query'))->getDeclaringClass()->getName() !== self::class) return;

        $columns = $this->_effectiveColumns();
        foreach ($columns as $col) {
            if ($col instanceof GridColumn && $col->isRunning()) return;
        }

        $q = $this->_buildQuery();
        if (!method_exists($q, 'whereKey')) return;
        $q->whereKey($id);

        [$page, $perPage] = [$this->page, $this->perPage];
        [$this->page, $this->perPage] = [1, 1];
        try {
            $items = $this->_runQuery($q)['items'] ?? [];
        } finally {
            [$this->page, $this->perPage] = [$page, $perPage];
        }
        if ($items === []) return;

        $evaluates = [];
        foreach ($columns as $col) {
            if ($col->evaluate !== '') $evaluates[$col->field] = $col->evaluate;
        }
        if ($evaluates !== []) $this->_applyEvaluates($items, $evaluates);

        $this->_rawItems = array_merge(array_values($items), $this->_rawItems);
        $this->_rows     = array_merge(array_values($this->_normalizeRows($items)), $this->_rows);
        $this->_applyRowDetail();
    }

    // ── Actions ──────────────────────────────────────────────────────────

    public function onPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->loadData();
    }

    public function onSort(string $field): void
    {
        // Segurança: só aceita ordenar por coluna declarada como sortable.
        // Bloqueia injeção via ORDER BY na origem (o $field vem do clique AJAX).
        if (!$this->_isSortableField($field)) {
            return;
        }

        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $field;
            $this->sortDir = 'asc';
        }
        $this->page = 1;
        $this->loadData();
    }

    /**
     * Verifica se $field é uma coluna declarada como sortable.
     * Whitelist anti-injeção para o ORDER BY: usa exportColConfigs (público,
     * sobrevive ao ciclo AJAX) e cai para _effectiveColumns() quando vazio.
     */
    private function _isSortableField(string $field): bool
    {
        // Ordenar por `data|day` é ordenar pela COLUNA `data`: o sufixo só diz
        // como a quebra agrupa, não existe como identificador SQL.
        $field = GridRenderHelpers::groupGrainBase($field);
        if ($field === '') {
            return false;
        }

        $configs = $this->exportColConfigs;
        if (empty($configs)) {
            $configs = array_map(
                fn($c) => ['field' => $c->field, 'sortable' => $c->sortable],
                $this->_effectiveColumns()
            );
        }

        foreach ($configs as $c) {
            if (!empty($c['sortable']) && ($c['field'] ?? '') === $field) {
                return true;
            }
        }
        return false;
    }

    /**
     * $field e uma coluna declarada `editable` nesta grid?
     *
     * Allowlist de ESCRITA do `onInlineSave`, na mesma fonte que a allowlist
     * de ORDER BY (`_isSortableField`) e a de filtro (`_declaredFilter`):
     * `exportColConfigs` (publico, viaja no `mad_state`, que e AES-256-GCM
     * autenticado — o cliente nao forja) com fallback para
     * `_effectiveColumns()`, que cobre a grid declarada em `columns()` na
     * subclasse e o primeiro render da grid do Blade.
     *
     * Estar NA grid nao basta: a coluna precisa ser `editable`. Grid sem
     * coluna alguma (config perdido) recusa tudo — falha FECHADO.
     */
    private function _isEditableField(string $field): bool
    {
        if ($field === '') {
            return false;
        }

        $configs = $this->exportColConfigs;
        if (empty($configs)) {
            $configs = array_map(
                fn($c) => ['field' => $c->field, 'editable' => $c->editable],
                $this->_effectiveColumns()
            );
        }

        foreach ($configs as $c) {
            if (!empty($c['editable']) && ($c['field'] ?? '') === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtro de coluna legado (`filter` / `filter-popover`).
     *
     * O operador NÃO vem do cliente: o thead despacha só {field, value} e o
     * `$op` recebido aqui é o default do JS, não uma escolha declarada. Derivar
     * do servidor é o que faz `filter="select"` gerar `=` em vez de
     * `LIKE '%valor%'` (que casava qualquer registro contendo o valor) e
     * `filter="date"` gerar `whereDate` em vez de `LIKE '%2026-07-28%'`.
     *
     * Mesma whitelist do ORDER BY (`_isSortableField`): coluna que não declarou
     * filtro é ignorada — fecha `onFilter('password_hash', 'a')` em coluna que
     * a grid nunca expôs.
     */
    public function onFilter(string $field, mixed $value, string $op = 'like'): void
    {
        $decl = $this->_declaredFilter($field);
        if ($decl === null) {
            return;
        }

        if (! static::_filterValueIsEmpty($value)) {
            // filter-op-select: o usuário escolhe, mas só dentro da allowlist.
            $this->filters[$field]   = $value;
            $this->filterOps[$field] = $decl['opSelect']
                ? static::_sanitizeOp($op, $decl['op'])
                : $decl['op'];
        } else {
            unset($this->filters[$field]);
            unset($this->filterOps[$field]);
        }
        $this->page = 1;
        $this->loadData();
    }

    public function onClearFilter(string $field): void
    {
        unset($this->filters[$field]);
        unset($this->filterOps[$field]);
        $this->page = 1;
        $this->loadData();
    }

    public function onClearAllFilters(): void
    {
        $this->filters    = [];
        $this->filterOps  = [];
        $this->colFilters = [];
        $this->_cfClearRules();
        $this->page       = 1;
        // no-auto-load: limpar volta ao vazio — recarregar aqui dispararia o
        // COUNT(*) sem filtro que a opção existe pra evitar.
        if (!$this->autoLoad) {
            $this->_resetToDeferred();
            return;
        }
        $this->loadData();
    }

    /**
     * MadFiltersTrait::onLimpar — mesmo reset dos filtros, mas num grid
     * no-auto-load termina no estado vazio em vez de applyFiltersChanged().
     */
    public function onLimpar(): void
    {
        // "Limpar" zera TODOS os filtros da tela — as condições do filtro
        // avançado inclusive (as props dele ficam fora da descoberta da trait,
        // que é quem zera o resto).
        $this->_cfClearRules();
        if ($this->autoLoad) {
            $this->_traitOnLimpar();
            return;
        }
        $this->_seedDefaultPeriod();
        foreach ($this->discoverFilterProps() as $name) {
            $this->$name = is_array($this->$name) ? [] : '';
        }
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->_resetToDeferred();
    }

    /**
     * MadFilterable — filtros ativos da tela + condições do filtro avançado.
     * (O badge "Filtros (N)" de botões manuais conta as duas coisas.)
     */
    public function totalActiveFilters(): int
    {
        return $this->_traitTotalActiveFilters() + count($this->_cfActiveRules());
    }

    /**
     * Descoberta de props-filtro da MadFiltersTrait SEM as props do filtro
     * avançado. Elas são public array/string (a trait as trataria como filtro
     * de tela): o hydrate por request/params sobrescreveria as defs com
     * `?customFilterDefs=x`, o `syncFormFields` as copiaria pro MadForm (estado
     * inflado) e o `applyAutoFiltersToQuery` as veria como coluna.
     */
    protected function discoverFilterProps(): array
    {
        return array_values(array_diff($this->_traitDiscoverFilterProps(), self::CUSTOM_FILTER_PROPS));
    }

    /**
     * Declaração de filtro da coluna `$field`, ou null se ela não declarou uma.
     *
     * Espelha `_isSortableField`: lê `exportColConfigs` (público, sobrevive ao
     * ciclo AJAX) e cai para `_effectiveColumns()` quando vazio.
     *
     * @return array{op: string, opSelect: bool}|null
     */
    private function _declaredFilter(string $field): ?array
    {
        if ($field === '') {
            return null;
        }

        $configs = $this->exportColConfigs;
        if (empty($configs)) {
            $configs = array_map(
                fn($c) => [
                    'field'          => $c->field,
                    'filterable'     => $c->filterable,
                    'filterType'     => $c->filterType,
                    'filterPopover'  => $c->hasFilterPopover ? '__default__' : '',
                    'filterOpSelect' => $c->filterOpSelect,
                ],
                $this->_effectiveColumns()
            );
        }

        foreach ($configs as $c) {
            if (($c['field'] ?? '') !== $field) {
                continue;
            }
            $declared = !empty($c['filterable']) || !empty($c['filterPopover']);
            if (! $declared) {
                continue;
            }

            // `filter-op` explícito vence; senão o operador sai do tipo declarado.
            $op = static::_canonicalOp((string) ($c['filterOp'] ?? ''));
            if ($op === '') {
                $op = match ((string) ($c['filterType'] ?? 'text')) {
                    'select' => '=',
                    'date'   => 'date',
                    default  => 'like',
                };
            }

            return ['op' => $op, 'opSelect' => !empty($c['filterOpSelect'])];
        }
        return null;
    }

    /**
     * Filtro de coluna seguro via token criptografado.
     *
     * `$value` é mixed porque range (['de','ate']) e multi (['A','I']) mandam
     * array. Campo e operador saem do token — o cliente manda só o valor.
     */
    public function onColFilter(string $token, mixed $value, string $display = ''): void
    {
        $def = MadStateCrypt::decrypt($token);
        if (!$def || !isset($def['field'])) return;

        $field = $def['field'];
        $kind  = (string) ($def['kind'] ?? '');
        $value = static::_normalizeFilterValue($value, $kind);

        if (! static::_filterValueIsEmpty($value)) {
            $entry = [
                'field'   => $field,
                'op'      => $def['op'] ?? 'like',
                'kind'    => $kind,
                'value'   => $value,
                'display' => $display !== '' ? $display : static::_filterValueLabel($value),
            ];
            if (!empty($def['sub'])) {
                $entry['sub'] = $def['sub'];
            }
            $this->colFilters[$field] = $entry;
        } else {
            unset($this->colFilters[$field]);
        }

        $this->page = 1;
        $this->loadData();
    }

    /**
     * Coage o valor cru do cliente à forma que o `kind` exige.
     *
     * Sem isso um range que chega como string ("2026-01-01,2026-12-31" de um
     * input serializado) ou um multi que chega escalar (um item só) quebrariam
     * `_rangeParts`/`_scalarList` silenciosamente.
     */
    protected static function _normalizeFilterValue(mixed $value, string $kind): mixed
    {
        $isRange = str_ends_with($kind, '-range');

        if ($isRange) {
            if (is_array($value)) {
                [$a, $b] = static::_rangeParts($value);
                return [$a ?? '', $b ?? ''];
            }
            // "a,b" (input serializado) ou escalar solto = só o início.
            $parts = is_string($value) && str_contains($value, ',')
                ? array_map('trim', explode(',', $value, 2))
                : [(string) $value, ''];
            return [$parts[0] ?? '', $parts[1] ?? ''];
        }

        if ($kind === 'multi') {
            if (is_array($value)) return array_values($value);
            if ($value === '' || $value === null) return [];
            // <select multiple> serializado / valor único.
            return is_string($value) && str_contains($value, ',')
                ? array_map('trim', explode(',', $value))
                : [$value];
        }

        // Escalar: array aqui é lixo do cliente, não um valor.
        return is_array($value) ? '' : $value;
    }

    /** Rótulo do chip quando o cliente não mandou um display pronto. */
    protected static function _filterValueLabel(mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }
        $parts = array_filter(array_map(
            fn($v) => is_scalar($v) ? (string) $v : '',
            $value
        ), fn($v) => $v !== '');

        return implode(' – ', $parts);
    }

    public function onClearColFilter(string $token): void
    {
        $def = MadStateCrypt::decrypt($token);
        if ($def && isset($def['field'])) {
            unset($this->colFilters[$def['field']]);
        }
        $this->page = 1;
        $this->loadData();
    }

    /** Handler da searchbar embutida do grid (mad-ui.js handleSearch). */
    public function onSearchTerm(string $term): void
    {
        $this->search = trim($term);
        $this->page   = 1;
        $this->loadData();
    }

    public function onPerPage(int $perPage): void
    {
        $this->perPage       = max(5, $perPage);
        $this->perPageLocked = true; // escolha explícita do usuário tem prioridade sobre o per-page do Blade
        $this->page          = 1;
        $this->loadData();
    }

    public function onInlineSave(int|string $id, string $field, mixed $value): \Mad\Http\MadResponse
    {
        $resp = new \Mad\Http\MadResponse();
        if (empty($this->model)) return $resp;

        // Allowlist ANTES de qualquer escrita. Este handler e publico, logo
        // despachavel pelo MadWire com `$field` escolhido pelo cliente, e a
        // atribuicao abaixo e direta ($record->$field = ...), que NAO passa
        // pelo $fillable do model. Sem esta guarda, qualquer usuario que
        // alcancasse uma grid gravava QUALQUER coluna da tabela — inclusive
        // `is_admin` ou o hash de senha de outro registro.
        if (! $this->_isEditableField($field)) {
            \Illuminate\Support\Facades\Log::warning(
                'MadDataGrid: onInlineSave recusado — coluna "' . $field . '" nao e editavel em '
                . static::class . ' (registro ' . $id . ').'
            );
            // Devolve a linha do banco: a edicao otimista da tela e desfeita.
            $this->_skipFullRender = true;
            return $resp->manageRow($id, static::class);
        }

        $db = $this->_db();
        \Illuminate\Support\Facades\DB::connection($db)->transaction(function () use ($id, $field, $value) {
            $modelClass = $this->model;
            $record = $modelClass::find($id);
            if ($record) {
                $record->$field = ($value === '') ? null : $value;
                $record->save();
            }
        });

        // Atualiza apenas a linha editada — sem full re-render da listagem.
        // _skipFullRender garante que o handler retorne `partial` (so as ops),
        // sem o html completo da grade. O manageRow gera highlight + re-init.
        $this->_skipFullRender = true;
        return $resp->manageRow($id, static::class);
    }

    /** Força re-render recarregando os dados. */
    public function refresh(): void
    {
        $this->loadData();
    }

    // ── Exportação ──────────────────────────────────────────────────────

    /** HTML customizado para header do PDF (contrato LEGADO — prefira
     *  exportPdfBands). Placeholders: os mesmos de exportPdfBands(). */
    protected function exportPdfHeader(): string { return ''; }

    /** Texto customizado para footer do PDF (contrato LEGADO — prefira
     *  exportPdfBands). Placeholders: {TITLE}, {DATE} */
    protected function exportPdfFooter(): string { return ''; }

    /**
     * Config de bandas do PDF exportado — override gerado pela plataforma
     * (bloco @mad-block:pdf-export-bands). null = sem config de página.
     *
     * Shape: ['header' => ['mode' => 'inherit|custom|none', 'html', 'heightMm'],
     *         'footer' => [idem + 'showPagination'],
     *         'orientation' => 'portrait|landscape',        // opcional
     *         'palette' => ['headerRule' => '#e3e8ef']]      // opcional
     * `orientation`/`palette` ausentes herdam do projeto (pdf-export.json);
     * sem nada, paisagem e a linha azul do cabeçalho padrão.
     * Placeholders no html: {TITLE} {SUBTITLE} {DATE} {PERIOD} {FILTERS}
     * {TOTAL_REGISTER} {APP_NAME} {UNIT_NAME} {USER_NAME} {TENANT_NAME} {LOGO}
     * {LOGO_SMALL} {PAGE_NUM} {PAGE_COUNT} — e os de exportPdfPlaceholders().
     */
    protected function exportPdfBands(): ?array { return null; }

    /**
     * Placeholders PRÓPRIOS da página pras bandas do PDF: `['CNPJ' => '…']` ou
     * `['{CNPJ}' => '…']` (chave com ou sem chaves). Valores são texto puro
     * (escapados na banda). Precedência: página > App\Support\PdfExportPlaceholders
     * ::resolve() (global do app) > built-ins de texto. {LOGO}/{LOGO_SMALL}/
     * {PAGE_NUM}/{PAGE_COUNT} não são sobrescrevíveis.
     *
     * Roda só no export PDF, DENTRO do AJAX do export — só props públicas
     * round-tripam via mad_state; recalcule o que precisar. Escreva fora dos
     * blocos @mad-block (a plataforma reescreve esses).
     */
    protected function exportPdfPlaceholders(): array { return []; }

    /** Fixos do projeto (pdf-export.json) < classe global do app < página; todos normalizados. */
    private function _resolvePdfPlaceholders(): array
    {
        return array_merge(
            MadGridPdfPlaceholders::fromProjectConfig(),
            MadGridPdfPlaceholders::fromGlobalHook(),
            MadGridPdfPlaceholders::normalize($this->exportPdfPlaceholders())
        );
    }

    /**
     * Resolve as bandas efetivas do PDF, POR BANDA, na precedência:
     * página (exportPdfBands) > hooks legados string > global do projeto
     * (MadGridPdfBranding / app/config/pdf-export.json) > default vitrine.
     *
     * @return array{header: string|array, footer: string|array} valores no
     *         formato que MadGridExporter::pdf aceita (band config ou string
     *         legada — o exporter normaliza).
     */
    private function _resolvePdfBands(): array
    {
        $page = $this->exportPdfBands();
        $out  = [];

        foreach (['header', 'footer'] as $which) {
            $band = null;

            // 1. Config da página (custom/none decidem; inherit cai adiante)
            $p = is_array($page) ? ($page[$which] ?? null) : null;
            if (is_array($p) && in_array($p['mode'] ?? 'inherit', ['custom', 'none'], true)) {
                $band = $p;
            }

            // 2. Ponte legado: hooks string sobrescritos na subclasse
            if ($band === null) {
                $legacy = $which === 'header' ? $this->exportPdfHeader() : $this->exportPdfFooter();
                if ($legacy !== '') {
                    $band = $legacy; // string — exporter aplica a semântica legada
                }
            }

            // 3. Global do projeto (app/config/pdf-export.json)
            if ($band === null) {
                $g = MadGridPdfBranding::band($which);
                if (in_array($g['mode'], ['custom', 'none'], true)) {
                    $band = $g;
                }
            }

            // 4. Default vitrine
            $out[$which] = $band ?? ['mode' => 'default'];
        }

        return $out;
    }

    /**
     * Orientação e paleta do PDF definidas pela PÁGINA (exportPdfBands()).
     * Só o que a página define: projeto e default ficam com o exporter
     * (MadGridExporter::pdfOrientation / pdfPalette).
     *
     * @return array{orientation?: 'portrait'|'landscape', palette?: array}
     */
    private function _resolvePdfPage(): array
    {
        $page = $this->exportPdfBands();
        if (! is_array($page)) {
            return [];
        }

        $out = [];
        $orientation = MadGridPdfBranding::normalizeOrientation($page['orientation'] ?? null);
        if ($orientation !== null) {
            $out['orientation'] = $orientation;
        }
        if (is_array($page['palette'] ?? null)) {
            $out['palette'] = $page['palette'];
        }

        return $out;
    }

    /** Título default da exportação (mesma regra de sempre). */
    private function _defaultExportTitle(): string
    {
        return $this->exportTitle ?: (static::$title ?: static::class);
    }

    /**
     * Congela em $exportMeta o que o AJAX de export não consegue recalcular:
     * título/subtítulo/arquivo (atributos do Blade), período e opções de
     * relatório. `filters` NÃO é tocado aqui — quem o preenche é o
     * declareFilterFields() do <mad-dash-filters>, cuja ordem de render varia.
     */
    protected function _fillExportMeta(): void
    {
        $period = $this->reportPeriodLabel();
        // O título aceita {PERIOD} ("Analítico — {PERIOD}"): resolvido aqui, uma
        // vez, para valer igual no header do PDF e no nome do arquivo.
        $sub = fn(string $s): string => $s === '' ? '' : str_replace('{PERIOD}', $period, $s);

        $this->exportMeta = array_merge($this->exportMeta, [
            'title'         => $sub($this->_defaultExportTitle()),
            'filename'      => $sub($this->exportFilename ?: $this->_defaultExportTitle()),
            'subtitle'      => $sub($this->exportSubtitle),
            'period'        => $period,
            'groupBand'     => $this->groupBand,
            'rowDetail'     => $this->rowDetail !== '',
            'rowDetailMask' => $this->rowDetail,
            // `no-export` do Blade só chega no render; o AJAX de export não
            // re-renderiza e veria o default `true`. O exportMeta vai no
            // mad_state criptografado e o cliente não o sobrescreve
            // (_MODEL_STRUCTURAL_BLOCKED).
            'noExport'      => !$this->exportable,
        ]);
    }

    /**
     * Exportação desligada na tela (`no-export` ou `$exportable = false`).
     * Esconder o botão não basta: `onExportCSV` & cia. são ações públicas e
     * uma chamada montada à mão exportava assim mesmo.
     */
    protected function _exportBlocked(): bool
    {
        return !$this->exportable || !empty($this->exportMeta['noExport']);
    }

    private function _exportBlockedToast(): \Mad\Http\MadResponse
    {
        $this->skipFullRender();
        return \Mad\Ui\MadToast::danger(mad_t('mad.grid.export_disabled'));
    }

    /**
     * Rótulo do período ativo — o `{PERIOD}` das bandas do PDF.
     * '' quando a tela não tem filtro de período (periodType 'none').
     */
    public function reportPeriodLabel(): string
    {
        switch ($this->getPeriodType()) {
            case 'date-range':
                return $this->_reportRangeLabel($this->dtIni, $this->dtFim);

            case 'preset':
                if ($this->preset === '') return '';
                if ($this->preset === 'custom') {
                    return $this->_reportRangeLabel($this->dtIni, $this->dtFim);
                }
                [$start, $end] = $this->presetRange($this->preset);
                $range = $this->_reportRangeLabel(substr($start, 0, 10), substr($end, 0, 10));
                $label = (string) ($this->getOpcoesPreset()[$this->preset] ?? '');
                if ($range !== '' && $label !== '') {
                    return __('grid.period_label', ['label' => $label, 'range' => $range]);
                }
                return $range !== '' ? $range : $label;

            case 'month-year':
                $mes = $this->mes !== ''
                    ? (string) ($this->getOpcoesMes()[$this->mes] ?? $this->mes)
                    : '';
                return trim($mes . ' ' . $this->ano);
        }

        return '';
    }

    /** "01/01/2026 a 31/01/2026" / "a partir de …" / "até …" / ''. */
    private function _reportRangeLabel(string $ini, string $fim): string
    {
        $a = $this->_reportDateLabel($ini);
        $b = $this->_reportDateLabel($fim);

        if ($a !== '' && $b !== '') return __('grid.period_range', ['from' => $a, 'to' => $b]);
        if ($a !== '')              return __('grid.filter_range_from', ['from' => $a]);
        if ($b !== '')              return __('grid.filter_range_to',   ['to'   => $b]);

        return '';
    }

    /** Data do state (Y-m-d ou d/m/Y) no formato de exibição do locale. */
    private function _reportDateLabel(string $v): string
    {
        $db = static::_normalizeDateToDb($v);
        if ($db === '') return '';
        $dt = \DateTime::createFromFormat('Y-m-d', $db);

        return $dt ? $dt->format(mad_t('mad.tempo.date_format')) : $v;
    }

    /** Carrega todos os registros (sem paginação) respeitando filtros/sort atuais. */
    /** Grid no-auto-load ainda vazio: exportar carregaria a TABELA INTEIRA. */
    private function _deferredExportToast(): \Mad\Http\MadResponse
    {
        $this->skipFullRender();
        return \Mad\Ui\MadToast::warning(__('grid.load_first_export'));
    }

    /**
     * Colunas da exportação: todas as efetivas (evaluate/quebra leem delas) e
     * as visíveis que vão para o arquivo. Restaura antes o que o round-trip
     * AJAX perde (quebra, row-detail, colunas do inline config).
     *
     * @return array{0: GridColumn[], 1: GridColumn[]} [colunas, visíveis]
     */
    private function _prepareExportColumns(array $hiddenColumns): array
    {
        // Restaura a quebra perdida no round-trip AJAX (groupBy/Mask/Total são
        // protegidos; só exportGroupConfig sobrevive no state). ANTES do loadData
        // pra _resolveGroupLabels resolver a máscara já com os dados carregados.
        if (empty($this->groupBy) && !empty($this->exportGroupConfig['by'])) {
            $this->groupBy         = $this->exportGroupConfig['by'];
            $this->groupMask       = $this->exportGroupConfig['mask']  ?? '';
            $this->groupTotal      = (bool)($this->exportGroupConfig['total'] ?? false);
            $this->groupBand       = (string)($this->exportGroupConfig['band'] ?? '');
            $this->groupTotalLabel = (string)($this->exportGroupConfig['totalLabel'] ?? '');
        }
        // row-detail idem: protegida, só o exportMeta a traz de volta.
        if ($this->rowDetail === '' && !empty($this->exportMeta['rowDetailMask'])) {
            $this->rowDetail = (string) $this->exportMeta['rowDetailMask'];
        }

        // Reconstrói colunas a partir do config salvo (AJAX perde _inlineConfig)
        $columns = $this->_effectiveColumns();
        if (empty($columns) && !empty($this->exportColConfigs)) {
            $columns = array_map(fn($c) => static::_colFromConfig($c), $this->exportColConfigs);
        }

        // O chooser usa fieldKey (inclusive para campos de relacionamento) e
        // guarda a seleção no navegador. O cliente só pode REMOVER colunas.
        $visibleCols = array_values(array_filter($columns, fn($c) =>
            !$c->hidden && $c->checkDisplay() && !in_array($c->fieldKey, $hiddenColumns, true)
        ));
        if (empty($visibleCols)) {
            throw new \RuntimeException(__('grid.export_no_columns'));
        }

        return [$columns, $visibleCols];
    }

    /** Título e nome-base do arquivo exportado (exportMeta > props > título da tela). */
    private function _exportNames(): array
    {
        return [
            (string) ($this->exportMeta['title']    ?? '') ?: $this->_defaultExportTitle(),
            (string) ($this->exportMeta['filename'] ?? '') ?: ($this->exportFilename ?: $this->_defaultExportTitle()),
        ];
    }

    private function _loadAllForExport(array $hiddenColumns = []): array
    {
        [$columns, $visibleCols] = $this->_prepareExportColumns($hiddenColumns);

        $savedPage    = $this->page;
        $savedPerPage = $this->perPage;
        $this->page    = 1;
        $this->perPage = 0; // desativa limit/offset
        $this->_detectRenderFields($columns);

        // Carga de export (perPage=0) não é navegação do usuário: gravá-la
        // faria a próxima volta abrir a listagem com TODAS as linhas.
        $this->_returnSnapshotSuspended = true;
        try {
            $this->loadData();
        } finally {
            $this->_returnSnapshotSuspended = false;
        }

        // Re-normaliza com render fields se necessário
        if (!empty($this->_renderFields) && !empty($this->_rawItems)) {
            $this->_rows = $this->_normalizeRows($this->_rawItems);
            // A re-normalização reconstrói $_rows dos items crus e apagaria o
            // saldo acumulado / a linha descritiva materializados no loadData.
            $this->_groupLabelCache = $this->_resolveGroupLabels();
            $this->_postProcessRows($columns);
        }

        // Período é recalculado (o state dos filtros round-tripa e é a verdade);
        // `filters` vem congelado do render, que é quando o <mad-dash-filters>
        // declara os campos.
        $meta = $this->exportMeta;
        $meta['period']    = $this->reportPeriodLabel();
        $meta['groupBand'] = $this->groupBand;
        $meta['rowDetail'] = $this->rowDetail !== '';

        $result = [
            'columns'        => $visibleCols,
            'rows'           => $this->_rows,
            'exportTitle'    => $this->_exportNames()[0],
            'exportFilename' => $this->_exportNames()[1],
            'pdfBands'       => $this->_resolvePdfBands(),
            // Total geral (rodapé): mesmo cálculo do footer da grade viva.
            'grandTotals'    => $this->_computeTotals($visibleCols),
            'report'         => $meta,
        ];

        // Agrupamento
        if (!empty($this->groupBy)) {
            $result['groupData'] = $this->_computeGroupData($visibleCols);
        }

        // Coluna sem tipo: o Excel só trata "12.50" como número se a coluna do
        // banco for numérica — um varchar "4555.5555" é texto (fórum #47).
        GridExportSourceTypes::resolve($this->_exportModelClass(), $visibleCols);

        // Restaura estado e força re-load com paginação no próximo render
        $this->page        = $savedPage;
        $this->perPage     = $savedPerPage;
        $this->_dataLoaded = false;
        $this->_rows       = [];
        $this->_rawItems   = [];
        $this->_total      = 0;

        return $result;
    }

    /**
     * Model de onde vieram as linhas exportadas: o do 1º item carregado (cobre
     * `query()` sobrescrito que devolve models de outra classe), senão o
     * `$model` declarado. null = linhas de array/query crua — mesmo com
     * `$model` declarado, um alias de join não é coluna da tabela dele.
     */
    private function _exportModelClass(): ?string
    {
        $items = $this->_rawItems;
        $first = is_array($items) && $items !== [] ? reset($items) : null;
        if ($first instanceof \Illuminate\Database\Eloquent\Model) {
            return get_class($first);
        }
        if ($first !== null || empty($this->model)) {
            return null;
        }
        try {
            return class_exists($this->model)
                ? $this->model
                : \Mad\Form\ModelOptionsLoader::resolveModelClass($this->model);
        } catch (\Throwable) {
            return null;
        }
    }

    public function onExportCSV(array $hiddenColumns = []): \Mad\Http\MadResponse
    {
        if ($this->_exportBlocked()) return $this->_exportBlockedToast();
        if ($this->_isDeferred()) return $this->_deferredExportToast();
        $out = $this->_streamExport('csv', $hiddenColumns) ?? $this->_loadedExport('csv', $hiddenColumns);
        return $this->_exportDialog($out['file'], __('grid.export_csv'), $out['filename']);
    }

    public function onExportXLSX(array $hiddenColumns = []): \Mad\Http\MadResponse
    {
        if ($this->_exportBlocked()) return $this->_exportBlockedToast();
        if ($this->_isDeferred()) return $this->_deferredExportToast();
        // App que recebeu o framework novo só pelo canal de atualização ainda
        // não tem a biblioteca: aviso em vez de erro 500.
        if (!GridXlsxWriter::available()) {
            $this->skipFullRender();
            return \Mad\Ui\MadToast::danger(GridXlsxWriter::unavailableMessage());
        }
        $out = $this->_streamExport('xlsx', $hiddenColumns) ?? $this->_loadedExport('xlsx', $hiddenColumns);
        return $this->_exportDialog($out['file'], __('grid.export_xlsx'), $out['filename']);
    }

    /** Caminho carregado: todas as linhas na memória (grid que não pode ir em fluxo). */
    private function _loadedExport(string $format, array $hiddenColumns): array
    {
        $data = $this->_loadAllForExport($hiddenColumns);
        $args = [$data['columns'], $data['rows'], $data['exportTitle'], $data['groupData'] ?? [], $data['grandTotals'] ?? []];

        return [
            'file'     => $format === 'xlsx' ? MadGridExporter::xlsx(...$args) : MadGridExporter::csv(...$args),
            'filename' => $data['exportFilename'],
        ];
    }

    // ── Exportação em fluxo (CSV/XLSX) ───────────────────────────────────

    /**
     * Métodos que, sobrescritos pelo app, mudam o que a exportação lê ou
     * calcula. Com qualquer um deles sobrescrito a exportação carrega tudo,
     * como antes — ela precisa passar pelo código do app.
     */
    private const STREAM_EXPORT_HOOKS = [
        'query', '_autoQuery', '_runQuery', 'loadData', '_normalizeRows', '_applyEvaluates',
        '_computeTotals', '_computeTotalsForRows', '_computeGroupData', '_flattenGroups',
        '_resolveGroupLabels', '_postProcessRows', '_applyRunning',
    ];

    /** Linhas por consulta na exportação em fluxo. */
    protected int $exportChunkSize = 5000;

    /**
     * CSV/XLSX lendo o banco em lotes e escrevendo direto no arquivo: a
     * memória não cresce com o número de linhas. Carregar tudo custava
     * ~4,3 MB por mil linhas antes de escrever o primeiro byte — 100 mil
     * linhas não cabiam no limite de memória de nenhum ambiente.
     *
     * Mesmo resultado do caminho carregado, com uma diferença pedida: com
     * quebra, os grupos saem ordenados pela chave (o banco precisa entregar
     * cada grupo inteiro de uma vez), não pela ordem em que apareceriam.
     *
     * @return array{file:string, filename:string}|null null = esta grid não
     *         pode ir em fluxo (model ausente, `query()`/totais do app, saldo
     *         acumulado, quebra sem ORDER BY possível) — use o carregado.
     */
    private function _streamExport(string $format, array $hiddenColumns): ?array
    {
        [$columns, $visibleCols] = $this->_prepareExportColumns($hiddenColumns);
        if (!$this->_canStreamExport($columns)) {
            return null;
        }

        $savedPage    = $this->page;
        $savedPerPage = $this->perPage;
        $this->page    = 1;
        $this->perPage = 0;

        try {
            $this->_detectRenderFields($columns);
            $q = $this->_exportStreamQuery();
            if ($q === null) {
                return null;
            }

            // Tipo da fonte vem do schema — não precisa das linhas. Coluna sem
            // fonte conhecida fica texto (sem as linhas não há como inferir).
            GridExportSourceTypes::resolve(get_class($q->getModel()), $visibleCols);

            [$title, $filename] = $this->_exportNames();
            $writer = $format === 'xlsx'
                ? GridXlsxWriter::open($visibleCols, $title)
                : GridCsvWriter::open($visibleCols);

            try {
                $totals = $this->_streamExportRows($q, $columns, $visibleCols, $writer);
                $writer->grandTotalRow($totals->rendered(), $totals->raw());
                $file = $writer->finish();
            } catch (\Throwable $e) {
                $writer->abort();
                throw $e;
            }

            return ['file' => $file, 'filename' => $filename];
        } finally {
            // Mesmo reset do _loadAllForExport: o próximo render recarrega paginado.
            $this->page             = $savedPage;
            $this->perPage          = $savedPerPage;
            $this->_dataLoaded      = false;
            $this->_rows            = [];
            $this->_rawItems        = [];
            $this->_total           = 0;
            $this->_groupLabelCache = [];
        }
    }

    /** @param GridColumn[] $columns */
    private function _canStreamExport(array $columns): bool
    {
        if (empty($this->model) || ($this->requireFilter && !$this->_hasUserFilter())) {
            return false;
        }
        foreach (self::STREAM_EXPORT_HOOKS as $method) {
            if ((new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class) {
                return false;
            }
        }
        // Saldo acumulado atravessa lotes e quebras — fica no carregado.
        foreach ($columns as $col) {
            if ($col->isRunning()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A mesma consulta da tela (filtros, busca, ordenação, eager-load das
     * chains) com a chave da quebra na FRENTE da ordenação — cada grupo chega
     * inteiro e contíguo — e a chave primária no fim: ler em lotes só é
     * estável com ordem total. null = não dá para ordenar pela quebra.
     */
    private function _exportStreamQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        $q = $this->_buildQuery();
        if (!$q instanceof \Illuminate\Database\Eloquent\Builder) {
            return null;
        }
        if ($this->searchQuery) { ($this->searchQuery)($q); }

        $with = $this->with;
        foreach ($this->_autoWith as $rel) {
            if (!in_array($rel, $with, true) && !array_key_exists($rel, $with)) {
                $with[] = $rel;
            }
        }
        if (!empty($with)) {
            $q->with($with);
        }

        $groupFields = $this->_groupFields();
        if (!empty($groupFields)) {
            $base   = $this->_queryBuilder($q);
            $orders = $base->orders ?? [];
            $binds  = $base->bindings['order'] ?? [];
            $base->orders = null;
            $base->bindings['order'] = [];

            foreach ($groupFields as $field) {
                [$gBase] = GridRenderHelpers::splitGroupGrain((string) $field);
                if (($chain = static::_chainParts($gBase)) !== null) {
                    if (!$this->_applyChainOrder($q, $chain, 'asc')) {
                        return null;
                    }
                    continue;
                }
                $col = static::_unbrace(trim($gBase));
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                    return null;
                }
                $q->orderBy($q->getModel()->qualifyColumn($col));
            }

            $base->orders = array_merge($base->orders ?? [], $orders);
            $base->bindings['order'] = array_merge($base->bindings['order'] ?? [], $binds);
        }

        return $q->orderBy($q->getModel()->getQualifiedKeyName());
    }

    /**
     * Lê em lotes e escreve. Com quebra, guarda só as linhas do grupo de TOPO
     * corrente: o cabeçalho do grupo precisa da contagem e do total antes das
     * linhas. O achatamento é o mesmo `_flattenGroups()` da tela.
     *
     * @param GridColumn[] $columns
     * @param GridColumn[] $visibleCols
     */
    private function _streamExportRows(
        \Illuminate\Database\Eloquent\Builder $q, array $columns, array $visibleCols, GridXlsxWriter|GridCsvWriter $writer
    ): GridTotalsAccumulator {
        $totals    = new GridTotalsAccumulator($visibleCols);
        $evaluates = [];
        foreach ($columns as $col) {
            if ($col->evaluate !== '') {
                $evaluates[$col->field] = $col->evaluate;
            }
        }

        $groupFields = $this->_groupFields();
        $masks       = empty($groupFields) ? [] : $this->_groupMasks($groupFields);
        $groupKey    = null;
        $groupRows   = [];

        $flush = function () use (&$groupRows, $writer, $groupFields, $masks, $visibleCols): void {
            if ($groupRows === []) {
                return;
            }
            $this->_rows            = $groupRows;
            $this->_groupLabelCache = $this->_resolveGroupLabels();
            $writer->groupItems($this->_flattenGroups($groupRows, $groupFields, $masks, 0, $visibleCols));
            $this->_rows = [];
            $groupRows   = [];
        };

        $this->_withExportSnapshot($q, function () use ($q, $evaluates, $totals, $writer, $groupFields, $flush, &$groupKey, &$groupRows): void {
            $q->chunk(max(100, $this->exportChunkSize), function ($models) use ($evaluates, $totals, $writer, $groupFields, $flush, &$groupKey, &$groupRows): void {
                $items = $models->all();
                if (!empty($evaluates)) {
                    $this->_applyEvaluates($items, $evaluates);
                }
                foreach ($this->_normalizeRows($items) as $row) {
                    $totals->add($row);
                    if (empty($groupFields)) {
                        $writer->dataRow($row);
                        continue;
                    }
                    $key = (string) (static::_rowValue($row, $groupFields[0]) ?? '');
                    if ($key !== $groupKey) {
                        $flush();
                        $groupKey = $key;
                    }
                    $groupRows[] = $row;
                }
            });
            $flush();
        });

        return $totals;
    }

    /**
     * Lotes lidos do MESMO instantâneo do banco: sem isto uma linha incluída
     * ou apagada durante a exportação desloca os lotes seguintes — a mesma
     * linha sai duas vezes ou some. Sem READ ONLY e com commit no fim: um
     * observer do app que grave algo durante a leitura segue funcionando.
     */
    private function _withExportSnapshot(\Illuminate\Database\Eloquent\Builder $q, \Closure $run): void
    {
        $conn   = $q->getModel()->getConnection();
        $driver = $conn->getDriverName();
        if ($conn->transactionLevel() > 0 || !in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            $run();
            return;
        }

        // MySQL: vale para a PRÓXIMA transação. Postgres: primeira instrução DENTRO dela.
        if ($driver !== 'pgsql') {
            $conn->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $conn->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $conn->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $run();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ── Exportação PDF ───────────────────────────────────────────────────

    /**
     * Teto de linhas do PDF. 0 = automático: o que cabe na memória e no tempo
     * que SOBRAM nesta requisição (_pdfRowBudget) — o mesmo sistema gera
     * mais linhas num servidor com 512 MB do que no MadCloud com 128 MB, e
     * menos numa tela que já carregou muita coisa. A tela pode fixar um valor.
     *
     * pdfMaxRows vale para o motor direto (Pdf\GridPdfRenderer); pdfHtmlMaxRows
     * quando o PDF cai no Dompdf (célula com HTML, largura em %…), que monta a
     * tabela inteira em memória e cresce mais que linear.
     */
    protected int $pdfMaxRows = 0;
    protected int $pdfHtmlMaxRows = 0;

    public function onExportPDF(array $hiddenColumns = []): \Mad\Http\MadResponse
    {
        if ($this->_exportBlocked()) return $this->_exportBlockedToast();
        if ($this->_isDeferred()) return $this->_deferredExportToast();

        // O teto é decidido ANTES de carregar: carregar 100 mil linhas só para
        // contar já estoura a memória que o teto protege. Qual teto vale (motor
        // direto ou Dompdf) sai só das colunas e das bandas.
        [, $visibleCols] = $this->_prepareExportColumns($hiddenColumns);
        $bands  = $this->_resolvePdfBands();
        $direct = MadGridExporter::pdfUsesDirectRenderer($visibleCols, $bands['header'], $bands['footer']);
        $max    = $this->_pdfRowLimit($direct, count($visibleCols));
        $count  = $this->_countForExport();
        if ($count !== null && $count > $max) {
            return $this->_pdfTooManyRowsToast($max);
        }

        $data = $this->_loadAllForExport($hiddenColumns);
        // Sem contagem prévia (query() do app, grid de array): conta carregado.
        if ($count === null && MadGridExporter::pdfRowCount($data['rows'], $data['groupData'] ?? []) > $max) {
            return $this->_pdfTooManyRowsToast($max);
        }
        // Só o PDF paga o hook do app (CSV/XLSX não têm bandas).
        $report = ($data['report'] ?? []) + $this->_resolvePdfPage();
        $report['placeholders']   = $this->_resolvePdfPlaceholders();
        // Conteúdo que só o Dompdf desenha igual pode aparecer na medição
        // (badge largo demais…): o exporter aplica este teto nesse desvio —
        // calculado agora, com a memória que sobrou depois de carregar.
        $report['pdfHtmlMaxRows'] = $this->_pdfRowLimit(false, count($visibleCols));
        try {
            $file = MadGridExporter::pdf(
                $data['columns'], $data['rows'], $data['exportTitle'],
                $data['groupData'] ?? [],
                $data['pdfBands']['header'] ?? '',
                $data['pdfBands']['footer'] ?? '',
                $data['grandTotals'] ?? [],
                $report
            );
        } catch (Pdf\GridPdfRowLimitException $e) {
            return $this->_pdfTooManyRowsToast($e->max);
        }
        return $this->_exportDialog($file, __('grid.export_pdf'), $data['exportFilename']);
    }

    /** Teto efetivo: o fixado pela tela ou o orçamento desta requisição, arredondado para o aviso. */
    private function _pdfRowLimit(bool $direct, int $cols): int
    {
        $fixed = $direct ? $this->pdfMaxRows : $this->pdfHtmlMaxRows;
        if ($fixed > 0) {
            return $fixed;
        }

        return static::_roundDownForHumans(
            static::_pdfRowBudget($direct, $cols, static::_freeMemoryBytes(), static::_maxExecutionSeconds())
        );
    }

    /**
     * Quantas linhas de PDF cabem na memória livre e no tempo de execução.
     * Números medidos (scripts/bench/grid-export-bench.php, 12 colunas):
     *
     *  - motor direto: linear — dados carregados + desenho ≈ 450 bytes por
     *    célula (10 mil linhas = 53 MB acima da base). Conta com 600 bytes e
     *    80% da memória livre, pela folga de texto mais longo. Tempo: ~2.300
     *    linhas/s nesta máquina; produção ~2× mais lenta → 800/s.
     *  - Dompdf: ~80 MB para 100 linhas com quebra, e mais que linear (1 mil
     *    linhas ≈ 700 MB, 2 mil ≈ 2,3 GB) — o teto sobe com a memória elevada
     *    a 0,6. Tempo: ~20 s por mil linhas → 20/s.
     *
     * @param int   $cols       colunas do PDF
     * @param float $freeBytes  memória livre (INF = sem memory_limit)
     * @param int   $maxSeconds tempo máximo da requisição (0 = sem limite)
     */
    protected static function _pdfRowBudget(bool $direct, int $cols, float $freeBytes, int $maxSeconds): int
    {
        $cols = max(1, $cols);

        if ($direct) {
            $byMemory = $freeBytes * 0.8 / ($cols * 600);
            $byTime   = $maxSeconds > 0 ? $maxSeconds * 800 : INF;

            return (int) max(100, min(100000, floor(min($byMemory, $byTime))));
        }

        $byMemory = 100 * (($freeBytes * 0.85) / (80 * 1024 ** 2 * $cols / 12)) ** 0.6;
        $byTime   = $maxSeconds > 0 ? $maxSeconds * 20 : INF;

        return (int) max(25, min(1000, floor(min($byMemory, $byTime))));
    }

    /** Memória que ainda cabe nesta requisição: memory_limit menos o que já está em uso. */
    protected static function _freeMemoryBytes(): float
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return INF;
        }
        $bytes = (float) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };

        return $bytes <= 0 ? INF : max(0.0, $bytes - memory_get_usage(true));
    }

    /** max_execution_time — sob Octane o php.ini fica em 0 e quem corta é o octane.max_execution_time. */
    protected static function _maxExecutionSeconds(): int
    {
        $max = (int) ini_get('max_execution_time');
        if ($max <= 0 && !empty($_SERVER['LARAVEL_OCTANE']) && function_exists('config')) {
            $max = (int) config('octane.max_execution_time', 0);
        }

        return max(0, $max);
    }

    /** 10.912 → 10.000, 229 → 220: o aviso mostra um número redondo e estável. */
    protected static function _roundDownForHumans(int $n): int
    {
        if ($n < 100) {
            return $n;
        }
        $step = 10 ** ((int) floor(log10($n)) - 1);

        return intdiv($n, $step) * $step;
    }

    /** Aviso "linhas demais para PDF" — sem redesenhar a grid (só o toast). */
    private function _pdfTooManyRowsToast(int $max): \Mad\Http\MadResponse
    {
        $this->skipFullRender();
        return \Mad\Ui\MadToast::warning(Pdf\GridPdfRowLimitException::userMessage($max));
    }

    /**
     * Quantas linhas a exportação vai ler, SEM carregá-las: a mesma consulta
     * da tela (filtros, busca, `onSearch`) num COUNT — o count do _runQuery.
     * null = não dá para saber antes: grid sem model, ou o app sobrescreve a
     * carga (`query()`, `_runQuery`…) e as linhas podem não ser as da consulta.
     */
    private function _countForExport(): ?int
    {
        if (empty($this->model) || ($this->requireFilter && !$this->_hasUserFilter())) {
            return null;
        }
        foreach (['query', '_autoQuery', '_runQuery', 'loadData'] as $method) {
            if ((new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class) {
                return null;
            }
        }
        $q = $this->_buildQuery();
        if (!$q instanceof \Illuminate\Database\Eloquent\Builder && !$q instanceof \Illuminate\Database\Query\Builder) {
            return null;
        }
        $base = $this->_queryBuilder($q);
        $base->orders = null;
        // ORDER BY por subquery (chain) carrega bindings — mesmo cuidado do _runQuery.
        $base->bindings['order'] = [];
        if ($this->searchQuery) { ($this->searchQuery)($q); }

        return (int) $q->count();
    }

    /**
     * Dialog "Exportacao concluida" com botao Baixar e contagem de linhas /
     * tamanho do arquivo. Substitui o auto-download do runtime legado,
     * dando ao usuario controle sobre quando iniciar o download (melhor UX
     * em ambientes com popup-blocker e em mobile).
     */
    protected function _exportDialog(string $file, string $format, string $filename): \Mad\Http\MadResponse
    {
        $ext      = pathinfo($file, PATHINFO_EXTENSION);
        $size     = self::_exportFileSize($file);
        $sizeStr  = self::_humanFileSize($size);
        $safeName = preg_replace('#[\\\\/:*?"<>|]#', '_', $filename) . '.' . $ext;

        // Icone do formato — CSV/Excel/PDF traduzidos, mas o icone e fixo por extensao
        $iconMap = ['csv' => 'file-text', 'xlsx' => 'file-spreadsheet', 'pdf' => 'file-type'];
        $icon    = $iconMap[strtolower($ext)] ?? 'download';

        $message = '<div style="display:flex;align-items:center;gap:12px;">'
                 . '<div style="flex-shrink:0;width:40px;height:40px;border-radius:8px;background:#f1f5f9;'
                 . 'display:flex;align-items:center;justify-content:center;color:#475569;">'
                 . '<i data-lucide="' . htmlspecialchars($icon) . '" style="width:22px;height:22px;"></i>'
                 . '</div>'
                 . '<div style="flex:1;min-width:0;">'
                 . '<div style="font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                 . htmlspecialchars($safeName) . '</div>'
                 . '<div style="font-size:12px;color:#6b7280;margin-top:2px;">'
                 . htmlspecialchars($format) . ' · ' . $sizeStr
                 . '</div>'
                 . '</div></div>';

        // Botao de download: cria um <a download> temporario, clica e remove.
        // Aponta pra rota AUTENTICADA mad.grid.export (não pro path cru do FS):
        // o arquivo fica fora do público e a rota devolve 410 se já foi purgado.
        // Forca nome do arquivo amigavel em vez do uniqid gerado pelo exporter.
        $dlUrl  = route('mad.grid.export', ['file' => basename($file), 'basename' => $safeName], false);
        $urlJs  = json_encode($dlUrl, JSON_UNESCAPED_SLASHES);
        $nameJs = json_encode($safeName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $downloadCb = '()=>{const a=document.createElement("a");a.href=' . $urlJs
                    . ';a.download=' . $nameJs
                    . ';document.body.appendChild(a);a.click();document.body.removeChild(a);}';

        $opts = json_encode([
            'type'    => 'success',
            'title'   => __('grid.export_success'),
            'message' => $message,
            'icon'    => 'check-circle-2',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $btnDownload = json_encode(__('grid.download'), JSON_UNESCAPED_UNICODE);
        $btnClose    = json_encode(__('grid.close'),    JSON_UNESCAPED_UNICODE);
        $buttons = '[{label:' . $btnDownload . ',type:"primary",callback:' . $downloadCb . '},'
                 . '{label:' . $btnClose . ',type:"secondary",callback:null}]';

        $optsWithBtns = substr($opts, 0, -1) . ',"buttons":' . $buttons . '}';

        return (new \Mad\Http\MadResponse())->script("MadDialog.show({$optsWithBtns})");
    }

    /**
     * Tamanho em bytes do arquivo exportado.
     *
     * O MadGridExporter devolve a CHAVE do disco de scratch
     * (`output/<uniqid>.<ext>`), não um caminho físico: `filesize()` na chave
     * resolvia relativo ao cwd do PHP (public/), não achava nada e o dialog
     * mostrava "0 B" para CSV, XLSX e PDF. Caminho absoluto (override de app
     * que ainda grava fora do scratch) continua aceito.
     */
    protected static function _exportFileSize(string $file): int
    {
        if ($file === '') return 0;

        try {
            if (\Mad\Service\MadScratchStorage::isValidKey($file, 'output')) {
                $disk = \Mad\Service\MadScratchStorage::disk();
                if ($disk->exists($file)) {
                    return (int) $disk->size($file);
                }
            }
        } catch (\Throwable $e) {
            // disco indisponível — cai no caminho físico abaixo
        }

        return is_file($file) ? (int) (@filesize($file) ?: 0) : 0;
    }

    /** Formata bytes em string humana: 12 B / 4.2 KB / 1.8 MB. */
    private static function _humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        $units = ['KB', 'MB', 'GB', 'TB'];
        $v = $bytes / 1024;
        $u = 0;
        while ($v >= 1024 && $u < count($units) - 1) { $v /= 1024; $u++; }
        return number_format($v, $v < 10 ? 2 : 1, ',', '.') . ' ' . $units[$u];
    }

    // ── View ─────────────────────────────────────────────────────────────

    /**
     * Nome da coluna que identifica a linha — a CHAVE do model, nao o literal
     * `id`. Tabela cuja PK tem nome proprio (`codigo`, `matricula`) nao tem
     * coluna `id` na linha: o Blade caia no INDICE do loop, e os botoes de
     * linha (Editar/Excluir) agiam no registro errado.
     *
     * Grid alimentado por array (sem model) continua em `id`.
     */
    protected function rowIdField(): string
    {
        if (empty($this->model)) return 'id';
        try {
            $cls = class_exists($this->model)
                 ? $this->model
                 : \Mad\Form\ModelOptionsLoader::resolveModelClass($this->model);
            if (!$cls || !class_exists($cls)) return 'id';
            $obj = new $cls();
            return method_exists($obj, 'getKeyName') ? (string)$obj->getKeyName() : 'id';
        } catch (\Throwable $e) {
            return 'id';
        }
    }

    /**
     * Literal JS do id da linha, pros `isEditing(id, campo)` / `startEdit(...)`
     * do inline-edit. Inteiro sai cru (saida byte-identica a de sempre); todo
     * o resto vira string JSON — sem isto uma PK de texto chegava no Alpine
     * como `isEditing(ABC-1,…)` (erro de sintaxe) e uma PK numerica com zero a
     * esquerda (`0001`) virava literal OCTAL (1, ou SyntaxError em strict mode).
     *
     * Vai por `{{ }}`: as aspas do JSON precisam virar &quot; dentro do atributo.
     */
    public static function rowIdJs(mixed $rowId): string
    {
        if (is_int($rowId)) return (string)$rowId;
        if (is_string($rowId) && preg_match('/^(?:0|[1-9][0-9]*)$/', $rowId)) return $rowId;
        return json_encode((string)$rowId, JSON_UNESCAPED_UNICODE) ?: '""';
    }

    protected function view(): string|array
    {
        // Volta de formulário: o mount() já carregou a página 1 — restaura o
        // estado guardado e recarrega (grid standalone; o <mad-grid self> faz
        // o mesmo no _renderInlineGrid).
        if ($this->_applyReturnSnapshot() && $this->_autoLoadAllowed()) {
            $this->loadData();
            $this->_afterReturnLoad();
        }

        $columns = $this->_effectiveColumns();
        $this->_detectRenderFields($columns);

        $colsConfig = array_values(array_map(
            fn($c) => ['field' => $c->fieldKey, 'label' => $c->label, 'hideable' => $c->hideable],
            array_filter($columns, fn($c) => !$c->hidden)
        ));

        return ['components.data-grid', [
            'columns'      => $columns,
            'rows'         => $this->_rows,
            'rowIdField'   => $this->rowIdField(),
            'total'        => $this->_total,
            'totalPages'   => $this->perPage > 0
                                ? (int)ceil($this->_total / $this->perPage)
                                : 1,
            'actions'      => $this->_effectiveActions(),
            'actionGroups' => $this->_effectiveActionGroups(),
            'totals'       => $this->_computeTotals($columns),
            'groupData'    => $this->_computeGroupData($columns),
            'groupBy'      => $this->groupBy,
            'groupTotal'   => $this->groupTotal,
            'groupBand'    => $this->groupBand,
            'searchable'   => $this->searchable,
            'searchValue'  => $this->search,
            'exportable'   => $this->exportable,
            'permExport'   => $this->_permExportMode(),
            'refreshable'  => $this->refreshable,
            'deferred'     => $this->_isDeferred(),
            'requireFilter' => $this->requireFilter,
            'filterMissing' => $this->_filterMissing,
            'loadButton'   => $this->_showLoadButton(),
            'loadHint'     => $this->_loadHint(),
            'sticky'       => $this->sticky,
            'actionSide'   => $this->actionSide,
            'filterOps'    => $this->filterOps,
            'colFilters'   => $this->colFilters,
            // Filtro avançado — [] quando o grid não declarou <mad-custom-filters>.
            'customFilters' => $this->_customFiltersViewData($columns),
            'storageKey'   => str_replace('\\', '_', static::class),
            'gridClass'    => static::class,
            'colsConfig'   => $colsConfig,
            'focusRowId'   => $this->focusRowId,
            'cardView'     => false,
            'cardDefault'  => false,
            'cardCols'     => '',
        ] + $this->_selectionViewData()];
    }

    // ── Inline grid (<mad-grid self>) ─────────────────────────────────────

    /**
     * Chamado pelo Blade quando <mad-grid self> é usado dentro de uma view
     * de um MadDataGrid. Aplica a config inline, recarrega dados e retorna
     * o HTML do data-grid para ser embutido na page view.
     *
     * Uso no Blade:
     *   <mad-grid self per-page="15">
     *       <mad-columns> ... </mad-columns>
     *       <mad-actions> ... </mad-actions>
     *   </mad-grid>
     *
     * Uso na view() da subclasse:
     *   protected function view(): string|array
     *   {
     *       return ['minha-view', ['__component' => $this]];
     *   }
     */
    public function _renderInlineGrid(array $config): string
    {
        $this->_inlineConfig = $config;

        // Cache para renderSingleRow (manageRow) poder reconstruir colunas/ações
        session([static::class . '_dg_cfg' => $config]);

        // Volta de formulário: estado de navegação ANTES de qualquer carga — o
        // config abaixo ainda precisa entrar (model, per-page, filtro avançado)
        // e a carga do fim desta função já usa a página restaurada.
        $returned = $this->_applyReturnSnapshot();

        // Propriedades não-serializadas: aplicar sempre (perdidas entre requests AJAX)
        if (!empty($config['model']))      $this->model      = $config['model'];
        if (!empty($config['database']))   $this->database   = $config['database'];
        // order-by/:filters declarativos (props públicas — ver baseOrder/baseFilters).
        // Mesma armadilha do per-page: no PRIMEIRO render o loadData() do mount()
        // já rodou antes deste config chegar — se o valor mudou, força recarga.
        // Nos AJAX seguintes a prop sobrevive no state e é igual ao config (no-op).
        if (!empty($config['orderBy']) && $this->baseOrder !== (string) $config['orderBy']) {
            $this->baseOrder   = (string) $config['orderBy'];
            $this->_dataLoaded = false;
        }
        if (!empty($config['filters']) && is_array($config['filters'])
            && $this->baseFilters !== $config['filters']) {
            $this->baseFilters = $config['filters'];
            $this->_dataLoaded = false;
        }
        // :query (closure) NÃO funciona em grid paginado: o estado re-hidrata do
        // mad_state entre AJAX e closure não serializa — era ignorado em silêncio.
        // Loga uma vez por classe e aponta a alternativa declarativa.
        if (!empty($config['queryIgnored'])) {
            static $warned = [];
            if (empty($warned[static::class])) {
                $warned[static::class] = true;
                \Illuminate\Support\Facades\Log::warning(
                    static::class . ': :query em <mad-grid model=...> paginado é ignorado '
                    . '(closure não sobrevive ao AJAX). Use order-by="campo desc" e '
                    . ':filters="[[\'campo\',\'op\',\'valor\']]".'
                );
            }
        }
        if (!empty($config['groupBy'])) {
            $gb = (string)$config['groupBy'];
            $this->groupBy = str_contains($gb, ',')
                ? array_map('trim', explode(',', $gb))
                : $gb;
        }
        // group-mask do Blade (string '{campo}' ou array p/ multi-nível). Sem
        // isto a quebra do <mad-grid self> sempre cai no valor cru do campo.
        if (!empty($config['groupMask'])) $this->groupMask = $config['groupMask'];
        if (isset($config['groupTotal']))  $this->groupTotal = (bool)$config['groupTotal'];
        if (!empty($config['groupBand']))       $this->groupBand       = (string)$config['groupBand'];
        if (!empty($config['groupTotalLabel'])) $this->groupTotalLabel = (string)$config['groupTotalLabel'];
        // row-detail muda o CONTEÚDO das linhas: se o loadData do mount já
        // rodou sem ele, força recarregar para a máscara ser materializada.
        if (!empty($config['rowDetail']) && $this->rowDetail !== (string)$config['rowDetail']) {
            $this->rowDetail   = (string)$config['rowDetail'];
            $this->_dataLoaded = false;
        }
        // export-title / -subtitle / -filename do Blade vencem a prop PHP: o
        // atributo é o que o editor visual mostra e reescreve.
        if (!empty($config['exportTitle']))    $this->exportTitle    = (string)$config['exportTitle'];
        if (!empty($config['exportFilename'])) $this->exportFilename = (string)$config['exportFilename'];
        if (!empty($config['exportSubtitle'])) $this->exportSubtitle = (string)$config['exportSubtitle'];

        // Persiste a config de quebra pro AJAX de export (props de grupo são
        // protegidas e o handler de export não re-renderiza o Blade).
        if (!empty($this->groupBy)) {
            $this->exportGroupConfig = [
                'by'         => $this->groupBy,
                'mask'       => $this->groupMask,
                'total'      => $this->groupTotal,
                'band'       => $this->groupBand,
                'totalLabel' => $this->groupTotalLabel,
            ];
        }
        if (!empty($config['actionSide'])) $this->actionSide = $config['actionSide'];
        if (isset($config['searchable']))     $this->searchable    = (bool)$config['searchable'];
        if (!empty($config['searchColumns'])) $this->searchColumns = (array)$config['searchColumns'];
        if (isset($config['exportable']))     $this->exportable    = (bool)$config['exportable'];
        if (isset($config['refreshable']))    $this->refreshable   = (bool)$config['refreshable'];
        if (isset($config['sticky']))         $this->sticky        = (bool)$config['sticky'];
        // no-auto-load: pública (serializada) — o hydrate() dos AJAX seguintes
        // lê do state; aqui só o primeiro render enxerga o Blade.
        if (isset($config['autoLoad']))       $this->autoLoad      = (bool)$config['autoLoad'];
        if (!empty($config['requireFilter'])) {
            $this->requireFilter = true;
            $this->autoLoad      = false;
        }
        if (isset($config['requireFilterFields'])) $this->requireFilterFields = array_values((array)$config['requireFilterFields']);
        // Seleção de linhas: o Blade manda (flag + ações em lote); o state
        // leva ao AJAX — onBulkOpen() resolve o alvo pelo índice sem o Blade.
        if (!empty($config['selectable'])) $this->selectable = true;
        if (isset($config['bulkActions']) && is_array($config['bulkActions'])) {
            $this->bulkActions = static::_normalizeBulkActions($config['bulkActions']);
        }

        // Filtro avançado (<mad-custom-filters>): as defs são a allowlist do que
        // o usuário final filtra, e só nascem aqui (config compilado = confiável).
        // Mesma armadilha do per-page: o loadData() do mount() já rodou antes
        // deste config chegar — se as defs mudaram E há condições no estado,
        // força recarregar. Depois, o filtro padrão do usuário (1ª abertura).
        if ($this->_cfApplyConfig(is_array($config['customFilters'] ?? null) ? $config['customFilters'] : null)) {
            $this->_dataLoaded = false;
        }
        if ($this->_cfApplyDefaultFilter()) {
            $this->_dataLoaded = false;
        }

        // Propriedades de estado: aplicar apenas como default na primeira carga.
        // Se loadData() já rodou (mount/hydrate), preservar o valor serializado.
        // Detecta render fields ANTES de loadData para que _normalizeRows resolva {relacao->campo}
        $columns = $this->_effectiveColumns();
        $this->_detectRenderFields($columns);

        // Salva config de colunas para exportação (serializado no mad_state)
        if (empty($this->exportColConfigs)) {
            $this->exportColConfigs = $config['colConfigs'] ?? [];
        }

        // per-page do Blade é o default autoritativo até o usuário escolher
        // explicitamente outro tamanho via o seletor (onPerPage → perPageLocked).
        // Sem isto, o primeiro render fica preso no default 15: o loadData() do
        // mount() já rodou (_dataLoaded=true) antes deste config chegar, então o
        // antigo guard "if (!_dataLoaded)" pulava a aplicação do per-page="20".
        $declaredPerPage = !empty($config['perPage']) ? (int)$config['perPage'] : 0;
        if ($declaredPerPage > 0 && !$this->perPageLocked && $this->perPage !== $declaredPerPage) {
            $this->perPage     = $declaredPerPage;
            $this->_dataLoaded = false; // força recarregar com o limit/offset corretos
        }

        if ($this->_isDeferred()) {
            // Grid gerado pela plataforma não chama parent::mount(): nenhuma
            // query rodou até aqui. Subclasse manual que chamou parent::mount()
            // já consultou — descarta as linhas pra UI ficar consistente.
            $this->_rows     = [];
            $this->_rawItems = [];
            $this->_total    = 0;
        } elseif (!$this->_dataLoaded) {
            if ($declaredPerPage > 0 && !$this->perPageLocked) {
                $this->perPage = $declaredPerPage;
            }
            $this->loadData();
            if ($returned) {
                $this->_afterReturnLoad();
            }
        } elseif (!empty($this->_renderFields) && !empty($this->_rawItems)) {
            // Dados já foram carregados sem render fields — renormalizar.
            // Os rótulos da quebra vão junto: o group-mask do Blade só chega
            // AQUI, depois do loadData() do mount(), e um cache calculado antes
            // dele deixaria `{relacao->campo}` literal na banda.
            $this->_rows = $this->_normalizeRows($this->_rawItems);
            $this->_groupLabelCache = $this->_resolveGroupLabels();
            $this->_postProcessRows($columns);
        }
        $colsConfig = array_values(array_map(
            fn($c) => ['field' => $c->fieldKey, 'label' => $c->label, 'hideable' => $c->hideable],
            array_filter($columns, fn($c) => !$c->hidden)
        ));

        $this->_fillExportMeta();

        // display-condition, disabled e transform callbacks que acessam relacoes
        // do registro ($row['__record']->relacao->campo) funcionam via lazy-load
        // do Eloquent — sem necessidade de transacao aberta.
        return MadBlade::render('components.data-grid', [
            'columns'      => $columns,
            'rows'         => $this->_rows,
            'rowIdField'   => $this->rowIdField(),
            'total'        => $this->_total,
            'totalPages'   => $this->perPage > 0
                                ? (int)ceil($this->_total / $this->perPage)
                                : 1,
            'actions'      => $this->_effectiveActions(),
            'actionGroups' => $this->_effectiveActionGroups(),
            'totals'       => $this->_computeTotals($columns),
            'groupData'    => $this->_computeGroupData($columns),
            'groupBy'      => $this->groupBy,
            'groupTotal'   => $this->groupTotal,
            'groupBand'    => $this->groupBand,
            'searchable'   => $this->searchable,
            'searchValue'  => $this->search,
            'exportable'    => $this->exportable,
            'permExport'    => $this->_permExportMode(),
            'refreshable'   => $this->refreshable,
            'deferred'      => $this->_isDeferred(),
            'requireFilter' => $this->requireFilter,
            'filterMissing' => $this->_filterMissing,
            'loadButton'    => $this->_showLoadButton(),
            'loadHint'      => $this->_loadHint(),
            'columnChooser' => $this->_inlineConfig['columnChooser'] ?? true,
            'sticky'        => $this->sticky,
            'actionSide'    => $this->actionSide,
            'storageKey'    => str_replace('\\', '_', static::class),
            'gridClass'     => static::class,
            'colsConfig'    => $colsConfig,
            'focusRowId'    => $this->focusRowId,
            // estado de paginação/sort/filtro — necessário pelo template
            'page'         => $this->page,
            'perPage'      => $this->perPage,
            'sortBy'       => $this->sortBy,
            'sortDir'      => $this->sortDir,
            'filters'      => $this->filters,
            'filterOps'    => $this->filterOps,
            'colFilters'   => $this->colFilters,
            // Filtro avançado — [] quando o grid não declarou <mad-custom-filters>.
            'customFilters' => $this->_customFiltersViewData($columns),
            // Card view
            'cardView'     => $this->_inlineConfig['cardView'] ?? false,
            'cardDefault'  => $this->_inlineConfig['cardDefault'] ?? false,
            'cardCols'     => $this->_inlineConfig['cardCols'] ?? '',
        ] + $this->_selectionViewData());
    }

    /**
     * Retorna as colunas efetivas: usa $_inlineConfig se definido via Blade,
     * caso contrário chama columns() da subclasse.
     */
    protected function _effectiveColumns(): array
    {
        $cols = $this->_inlineConfig !== null
            ? array_map(
                fn($c) => static::_colFromConfig($c),
                $this->_inlineConfig['colConfigs'] ?? []
            )
            : $this->columns();

        return $this->_normalizeFilterTokens($this->_normalizeSortable($cols));
    }

    /**
     * Cunha o token assinado das colunas com filtro tipado.
     *
     * PONTO ÚNICO onde campo+operador de filtro viram token: serve tanto as
     * colunas vindas do Blade (compiler) quanto as declaradas em columns()
     * (fluent), que não passam por compiler nenhum. Como o par sai daqui e
     * volta decriptado no onColFilter, o cliente não tem como forjar nem errar
     * o operador — é o que impede `between`/`in` de virarem superfície de
     * injeção.
     *
     * @param GridColumn[] $cols
     * @return GridColumn[]
     */
    protected function _normalizeFilterTokens(array $cols): array
    {
        foreach ($cols as $col) {
            if (! ($col instanceof GridColumn) || $col->filterKind === '') continue;

            // `_unbrace` aqui e não só na query: o editor persiste a coluna como
            // ref rename-safe e o deploy resolve pra `{estado_id}` — COM chaves.
            // Sem normalizar no ponto único, as chaves vazariam pra chave do
            // state (`colFilters[...]`) e pro `data-filter-field` do DOM, e o
            // chip do filtro ativo deixaria de casar com o valor aplicado.
            $field = static::_unbrace($col->filterField !== '' ? $col->filterField : $col->field);
            $op    = static::_canonicalOp($col->filterOp) ?: 'like';

            $col->colFilterField = $field;
            $col->colFilterToken = MadStateCrypt::encrypt([
                'field' => $field,
                'op'    => $op,
                'kind'  => $col->filterKind,
            ]);
        }
        return $cols;
    }

    /**
     * Coluna de relação cujo model vive em OUTRA conexão não tem ORDER BY
     * possível — tira o `sortable` pro header não oferecer o clique (e o
     * whitelist de onSort recusar) em vez de prometer algo que não acontece.
     *
     * @param GridColumn[] $cols
     * @return GridColumn[]
     */
    protected function _normalizeSortable(array $cols): array
    {
        if (empty($this->model)) return $cols;

        foreach ($cols as $col) {
            if (! ($col instanceof GridColumn) || ! $col->sortable) continue;
            // Saldo acumulado por EXPRESSÃO não tem coluna no banco (o field é
            // sintético): oferecer o clique geraria ORDER BY sobre coluna
            // inexistente e derrubaria a listagem.
            if ($col->isRunning() && $col->running !== 'self') {
                $col->sortable = false;
                continue;
            }
            $chain = static::_chainParts($col->field);
            if ($chain === null) continue;
            $rels = $chain;
            array_pop($rels);                 // último segmento = coluna
            // Chain inteira precisa ficar na MESMA conexão: basta um salto fora
            // pra não existir ORDER BY possível. Salto DESCONHECIDO mantém o
            // comportamento de sempre (o clique simplesmente não ordena) — tirar
            // o sortable aqui mudaria o header de grids que já existem.
            $cursor = $this->model;
            foreach ($rels as $rel) {
                $meta = $this->_relMetaOn($cursor, $rel);
                if ($meta === null) break;
                if (! $meta['same']) { $col->sortable = false; break; }
                $cursor = $meta['class'];
            }
        }
        return $cols;
    }

    /**
     * Retorna as ações efetivas: usa $_inlineConfig se definido via Blade,
     * caso contrário chama actions() da subclasse.
     */
    protected function _effectiveActions(): array
    {
        $dono = $this->_permOwnerClass();

        if ($this->_inlineConfig !== null) {
            return array_values(array_filter(array_map(
                fn($a) => static::_actFromConfig($a, $dono),
                $this->_inlineConfig['actConfigs'] ?? []
            )));
        }

        // actions() escrito à mão (listagem gerada) ou montado pelo <mad-grid>
        // declarativo: as ações chegam sem dono. Carimbar AQUI, no único ponto
        // por onde todas passam, é o que faz a permissão valer nos dois casos —
        // senão só o caminho inline (<mad-grid self>) seria checado.
        return array_map(fn($act) => static::_permStamp($act, $dono), $this->actions());
    }

    /**
     * Diz à ação a quem perguntar pela permissão, quando ninguém disse ainda.
     *
     * Ação que NAVEGA pergunta à tela de destino (é ela que vai abrir e é ela
     * que o perfil marca); as demais, à tela dona do grid. `->perm()` escrito à
     * mão nunca é sobrescrito.
     */
    protected static function _permStamp(mixed $act, string $dono): mixed
    {
        if (!$act instanceof GridAction || $act->permClass !== '') {
            return $act;
        }

        return $act->isNav
            ? $act->perm($act->navClass, $act->navMethod)
            : $act->perm($dono, $act->method);
    }

    /**
     * A quem perguntar pela permissão das ações deste grid.
     *
     * Numa listagem gerada (subclasse de MadDataGrid) o dono é a própria tela.
     * No `<mad-grid>` declarativo, que roda numa classe do framework liberada a
     * todo usuário logado, o dono vem do `owner` gravado pelo compilador — ver
     * {@see \Mad\Grid\MadGrid::_permOwner()}.
     */
    protected function _permOwnerClass(): string
    {
        if (method_exists($this, '_permOwner')) {
            try {
                $dono = trim((string) $this->_permOwner());
            } catch (\Throwable $e) {
                $dono = '';
            }
            if ($dono !== '') {
                return $dono;
            }
        }

        return static::class;
    }

    /**
     * O que fazer com o menu Exportar: allow | hide | disable.
     *
     * Exportar é uma das cinco caixinhas do perfil, e é a única cuja "tela" é um
     * menu e não um botão — daí a decisão vir pronta para o template.
     */
    protected function _permExportMode(): string
    {
        return \Mad\Security\ActionGuard::decide($this->_permOwnerClass(), 'export')['mode'];
    }

    /**
     * Retorna os grupos de ações efetivos: usa $_inlineConfig se definido via Blade,
     * caso contrário chama actionGroups() da subclasse.
     */
    protected function _effectiveActionGroups(): array
    {
        $dono = $this->_permOwnerClass();

        if ($this->_inlineConfig !== null) {
            return array_map(function (array $g) use ($dono) {
                $grp = GridActionGroup::make($g['label'] ?? '');
                if (!empty($g['icon'])) $grp->icon($g['icon']);
                foreach ($g['actions'] ?? [] as $a) {
                    $act = static::_actFromConfig($a, $dono);
                    if ($act) $grp->add($act);
                }
                return $grp;
            }, $this->_inlineConfig['actGroupConfigs'] ?? []);
        }

        // Mesmo carimbo do _effectiveActions(), para os grupos escritos à mão.
        $grupos = $this->actionGroups();
        foreach ($grupos as $grp) {
            if (!$grp instanceof GridActionGroup) continue;
            foreach ($grp->actions as $act) {
                static::_permStamp($act, $dono);
            }
        }

        return $grupos;
    }

    // ── Helpers de conversão config-array → objetos ───────────────────────
    // Movidos aqui para serem acessíveis tanto por MadDataGrid (self mode)
    // quanto por MadGrid (standalone mode).

    public static function _colFromConfig(array $c): GridColumn
    {
        $col = GridColumn::make($c['field'] ?? '', $c['label'] ?? '');

        if (!empty($c['width']))     $col->width($c['width']);
        if (!empty($c['align']))     $col->align($c['align']);
        if (!empty($c['sortable']))  $col->sortable();
        if (!empty($c['hidden']))    $col->hidden();
        if (!empty($c['cardRole']))  $col->cardRole($c['cardRole']);
        if (!empty($c['editable'])) {
            $editType = $c['editType'] ?? 'text';
            match ($editType) {
                'select'          => $col->editSelect($c['editOpts'] ?? []),
                'date'            => $col->editDate($c['editDateFmt'] ?? 'Y-m-d'),
                'datetime'        => $col->editDatetime(),
                'number'          => $col->editNumber($c['editDecimals'] ?? 2),
                'numeric'         => $col->editNumeric($c['editDecimals'] ?? 2, $c['editPrefix'] ?? '', $c['editSuffix'] ?? ''),
                'money'           => $col->editMoney($c['editDecimals'] ?? 2, $c['editPrefix'] ?? ''),
                'textarea'        => $col->editTextarea($c['editRows'] ?? 3),
                'dbcombo'         => $col->editDbcombo(
                                        $c['editModel']    ?? '',
                                        $c['editDisplay']  ?? 'nome',
                                        $c['editDatabase'] ?? '',
                                        $c['editKey']      ?? 'id'
                                     ),
                'dbunique-search' => $col->editDbuniqueSearch(
                                        $c['editModel']     ?? '',
                                        $c['editDisplay']   ?? 'nome',
                                        $c['editDatabase']  ?? '',
                                        $c['editMinLength'] ?? 2
                                     ),
                'color'           => $col->editColor($c['editColors'] ?? []),
                'spinner'         => $col->editSpinner(
                                        $c['editMin']  ?? null,
                                        $c['editMax']  ?? null,
                                        $c['editStep'] ?? 1
                                     ),
                default           => $col->editable(),
            };
            // Props compartilhadas que valem para varios tipos
            if (isset($c['editOrderBy']))  $col->editOrderBy = $c['editOrderBy'];
            if (isset($c['editFilters']))  $col->editFilters = $c['editFilters'];
            if (isset($c['editMin']))      $col->editMin  = is_numeric($c['editMin']) ? (float)$c['editMin'] : null;
            if (isset($c['editMax']))      $col->editMax  = is_numeric($c['editMax']) ? (float)$c['editMax'] : null;
            if (isset($c['editStep']))     $col->editStep = is_numeric($c['editStep']) ? (float)$c['editStep'] : null;
            if (!empty($c['editMode'])) {
                $col->editMode($c['editMode']);
            }
        }
        if (!empty($c['totalFunc'])) $col->total($c['totalFunc']);
        if (!empty($c['totalMask'])) $col->totalMask((string) $c['totalMask']);
        if (!empty($c['group']))     $col->group($c['group']);

        // Saldo acumulado (running balance) — 3 props que precisam sobreviver
        // ao AJAX de export junto do resto do colConfig.
        if (!empty($c['running'])) {
            $col->running(
                (string) $c['running'],
                (string) ($c['runningReset'] ?? ''),
                (string) ($c['runningStart'] ?? '')
            );
        }

        if (!empty($c['filterable'])) {
            $col->filter($c['filterType'] ?? 'text', $c['filterOpts'] ?? []);
        }

        // Filtro tipado (filter-type=). O token NÃO é cunhado aqui: quem cunha é
        // _normalizeFilterTokens, no render — assim o mesmo caminho serve as
        // colunas vindas do Blade e as declaradas em columns() (que não passam
        // por compiler nenhum).
        if (!empty($c['filterKind'])) {
            $col->filterKind        = (string) $c['filterKind'];
            $col->filterOp          = (string) ($c['filterOp'] ?? '');
            $col->filterField       = (string) ($c['filterField'] ?? '');
            $col->filterOpts        = $c['filterOpts'] ?? [];
            $col->filterModel       = (string) ($c['filterModel'] ?? '');
            $col->filterDisplay     = (string) ($c['filterDisplay'] ?? 'nome');
            $col->filterKey         = (string) ($c['filterKey'] ?? 'id');
            $col->filterDatabase    = (string) ($c['filterDatabase'] ?? '');
            $col->filterOrderBy     = (string) ($c['filterOrderBy'] ?? '');
            $col->filterOrder       = (string) ($c['filterOrder'] ?? '');
            $col->filterFilters     = $c['filterFilters'] ?? [];
            $col->filterMinLength   = (int)    ($c['filterMinLength'] ?? 2);
            $col->filterTrue        = (string) ($c['filterTrue'] ?? '1');
            $col->filterFalse       = (string) ($c['filterFalse'] ?? '0');
            $col->filterPlaceholder = (string) ($c['filterPlaceholder'] ?? '');
            // Exclusividade: um filtro por coluna.
            $col->filterable        = false;
            $col->hasFilterPopover  = false;
        }

        if (!empty($c['filterPopover']) && empty($c['colFilterToken'])) {
            $html = $c['filterPopover'] === '__default__' ? '' : $c['filterPopover'];
            if ($html !== '') {
                // Restaura tags <mad-*> protegidas pelo MadGridCompiler
                $html = str_replace('MAD__DEFER__', 'mad-', $html);
                try {
                    $html = MadBlade::renderString($html);
                } catch (\Throwable $e) {
                    // mantém o html cru em caso de erro
                }
            }
            $html = str_replace('data-mad-model=', 'x-model=', $html);
            $col->filterPopover($html, !empty($c['filterOpSelect']));
        }

        // Col-filter seguro (token criptografado)
        if (!empty($c['colFilterToken'])) {
            $col->colFilterToken = $c['colFilterToken'];
            $col->colFilterField = $c['colFilterField'] ?? $col->field;
            // HTML customizado do popover (se veio de <mad-col-filter>conteúdo</mad-col-filter>)
            if (!empty($c['filterPopoverHtml'])) {
                $raw  = $c['filterPopoverHtml'];
                $html = str_replace('MAD__DEFER__', 'mad-', $raw);
                try {
                    $html = MadBlade::renderString($html);
                } catch (\Throwable $e) {}
                $html = str_replace('data-mad-model=', 'x-model=', $html);
                $col->filterPopoverHtml = $html;

                // Extrai model/display do dbcombo para resolver display dos filtros ativos
                // $raw contém tags protegidas: <MAD__DEFER__dbcombo-field model="..." .../>
                if (preg_match('/model="([^"]+)"/', $raw, $m))    $col->filterModel    = $m[1];
                if (preg_match('/display="([^"]+)"/', $raw, $m))  $col->filterDisplay  = $m[1];
                if (preg_match('/database="([^"]+)"/', $raw, $m)) $col->filterDatabase = $m[1];
            }
        }

        switch ($c['renderType'] ?? '') {
            case 'badge':  $col->badge($c['badgeMap'] ?? []);           break;
            case 'money':  $col->money($c['moneyPrefix'] ?? '');        break;
            case 'number': $col->number($c['numberDecimals'] ?? 2);     break;
            case 'date':   $col->date($c['dateFormat'] ?? 'd/m/Y');     break;
            case 'html':   $col->html();                                break;
        }

        // Transform estático: string callable 'Classe::metodo' ou closure
        if (!empty($c['transform'])) {
            $col->transform($c['transform']);
        }

        // Evaluate: expressão computada '{valor} * {quantidade}'
        if (!empty($c['evaluate'])) {
            $col->evaluate($c['evaluate']);
        }

        // Display condition: string callable 'Classe::metodo'
        if (!empty($c['displayCondition'])) {
            $col->displayCondition($c['displayCondition']);
        }

        return $col;
    }

    /**
     * @param string $owner Classe DONA da permissão das ações — a TELA que
     *                      contém o grid. Vazio = ninguém a declarou e a ação
     *                      fica fora do alcance do perfil (como sempre foi).
     */
    public static function _actFromConfig(array $a, string $owner = ''): ?GridAction
    {
        $method = $a['method'] ?? '';
        if (empty($method) && empty($a['isNav'])) return null;

        // Nav action sem method explícito: gera nome interno
        if (empty($method) && !empty($a['isNav'])) {
            $method = 'nav_' . strtolower($a['navClass'] ?? 'page');
        }

        $act = GridAction::make($method);

        if (!empty($a['label']))   $act->label($a['label']);
        if (!empty($a['icon']))    $act->icon($a['icon']);
        if (!empty($a['confirm'])) $act->confirm($a['confirm']);
        if (!empty($a['danger']))  $act->danger();
        if (!empty($a['primary'])) $act->primary();
        if (!empty($a['idField'])) $act->idField($a['idField']);
        if (!empty($a['params']))  $act->params($a['params']);

        if (!empty($a['isNav'])) {
            $act->nav($a['navClass'] ?? '', $a['navMethod'] ?? 'show', !empty($a['navDrawer']));
            if (!empty($a['navRow'])) $act->row();
            if (!empty($a['navParams'])) $act->navParams = (array)$a['navParams'];
        }

        // Dono da permissão: a tela que contém o grid para <mad-del>/<mad-act>,
        // a tela de DESTINO para <mad-nav>. O `<mad-grid>` declarativo roda numa
        // classe do framework liberada a todo usuário logado, então sem isto a
        // exclusão embutida não era checada contra perfil nenhum (ver
        // MadGrid::_permOwner). Owner vazio = ninguém declarou: fica liberada.
        static::_permStamp($act, $owner);

        if (!empty($a['when'])) {
            $cond = $a['when'];
            if (is_string($cond)) {
                // String callable: 'Classe::metodo' — recebe (array $row) → bool
                $act->when($cond);
            } else {
                // Array field-based: ['field'=>..., 'op'=>..., 'value'=>...]
                $act->when(fn($row) => static::_evalCond($row, $cond));
            }
        }
        if (!empty($a['disabled'])) {
            $cond = $a['disabled'];
            if (is_string($cond)) {
                $act->disabled($cond);
            } else {
                $act->disabled(fn($row) => static::_evalCond($row, $cond));
            }
        }

        // Transform estático: string callable 'Classe::metodo' ou closure
        if (!empty($a['transform'])) {
            $act->transform($a['transform']);
        }

        return $act;
    }

    /**
     * Avalia uma condicao declarativa (when-* e disabled-*) contra uma linha.
     * Aceita tanto objeto de model (preferido — permite navegacao ->) quanto array.
     */
    protected static function _evalCond(array|object $row, array $cond): bool
    {
        $field = $cond['field'] ?? '';
        if ($field === '') return true;

        if (is_object($row)) {
            try { $fv = (string)($row->{$field} ?? ''); }
            catch (\Throwable $e) { $fv = ''; }
        } else {
            $fv = (string)($row[$field] ?? '');
        }
        $val = $cond['value'] ?? '';

        return match ($cond['op'] ?? 'eq') {
            'eq'  => $fv === (string)$val,
            'neq' => $fv !== (string)$val,
            'in'  => in_array($fv, (array)$val, true),
            'nin' => !in_array($fv, (array)$val, true),
            default => true,
        };
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Retorna os campos usados na busca global.
     * Se $searchColumns foi definido, usa diretamente.
     * Senão, pega as colunas que não são computadas (evaluate) e não são monetárias.
     * Se as colunas não estiverem disponíveis (AJAX sem _inlineConfig), usa os
     * atributos do model como fallback.
     */
    protected function _searchableFields(): array
    {
        if (!empty($this->searchColumns)) {
            return $this->searchColumns;
        }

        $cols = $this->_effectiveColumns();
        if (!empty($cols)) {
            $fields = [];
            foreach ($cols as $col) {
                if ($col->evaluate !== '') continue;
                // Saldo acumulado é calculado em PHP (e o campo pode nem existir
                // na tabela) — buscar por ele emitiria SQL sobre coluna fantasma.
                if ($col->isRunning()) continue;
                if ($col->isMoney || $col->isDate || $col->isNumber) continue;
                $fields[] = $col->field;
            }
            return $fields;
        }

        // Fallback: atributos do model (exclui campos típicos não-texto)
        if (!empty($this->model) && class_exists($this->model)) {
            $skip = ['id', 'created_at', 'updated_at', 'deleted_at'];
            try {
                $obj = new ($this->model)();
                $attrs = $obj->getAttributes() ?: [];
                return array_values(array_filter($attrs, fn($a) =>
                    !in_array($a, $skip) && !str_ends_with($a, '_id')
                ));
            } catch (\Throwable $e) {}
        }

        return [];
    }

    /** Carrega dados via query() customizada ou auto-query do model.
     *  Eloquent/Query Builder abrem a conexão sob demanda (lazy) — sem transação explícita. */
    public function loadData(): void
    {
        // Filtro obrigatório: guarda ÚNICA, porque todos os caminhos de carga
        // (onReload, onShow/onAtualizar, busca, sort, página, por página e até
        // a chamada direta do browser — loadData é público) passam por aqui.
        // O hydrate() roda antes do fill() e ainda enxerga os filtros antigos;
        // a ação logo depois reavalia com os valores novos.
        if ($this->requireFilter) {
            if (!$this->_hasUserFilter()) {
                $this->_filterMissing = true;
                $this->_dataLoaded = false;
                $this->_resetToDeferred();
                return;
            }
            $this->_filterMissing = false;
        }

        $this->_dataLoaded = true;
        // Os call sites automáticos (mount/hydrate/_renderInlineGrid) são
        // gateados por _autoLoadAllowed(); chegar aqui em estado adiado é
        // sempre ação explícita do usuário → arma o grid.
        $this->loadRequested = true;

        // O mount() chama loadData() ANTES do view()/_renderInlineGrid detectarem
        // as chains — sem isto o eager-load derivado nao existiria na consulta e
        // a chave da quebra nao seria materializada na primeira carga.
        if (! $this->_renderFieldsDetected) {
            $this->_detectRenderFields($this->_effectiveColumns());
        }

        try {
            $result = $this->query();

            if (empty($result)) {
                $result = $this->_autoQuery();
            }

            $items = $result['items'] ?? [];

            // Evaluate: resolve computed columns before normalization
            $columns   = $this->_effectiveColumns();
            $evaluates = [];
            foreach ($columns as $col) {
                if ($col->evaluate !== '') {
                    $evaluates[$col->field] = $col->evaluate;
                }
            }
            if (!empty($evaluates) && !empty($items)) {
                $this->_applyEvaluates($items, $evaluates);
            }

            $this->_rawItems = $items;
            $this->_rows    = $this->_normalizeRows($items);
            $this->_total   = (int)($result['total'] ?? count($this->_rows));

            // Pre-resolve group mask labels que dependem de relacionamentos
            $this->_groupLabelCache = $this->_resolveGroupLabels();

            // Saldo acumulado + linha descritiva: materializados em $_rows para
            // que tela, totais, CSV, XLSX e PDF leiam exatamente o mesmo dado.
            $this->_postProcessRows($columns);

            $this->_saveToSession();
            $this->_saveReturnSnapshot();
        } catch (\Throwable $e) {
            $this->_rows  = [];
            $this->_total = 0;
            throw $e;
        }
    }


    /**
     * Busca global (searchbar) builder-native — OR de (col LIKE %term% / fk IN
     * (SELECT id FROM rel WHERE col LIKE ...)). Tolera Eloquent|Query Builder.
     *
     * Todo LIKE daqui sai por `whereLike()` (sem diferenciar maiúsculas): o
     * `where($c, 'like', …)` cru é case-sensitive no Postgres, e "b1" não achava
     * "B1" — enquanto o mesmo termo no MySQL (collation `_ci`) achava.
     */
    protected function _applySearch($q): void
    {
        if ($this->search === '') return;

        $fields = !empty($this->searchColumns)
            ? $this->searchColumns
            : $this->_searchableFields();
        if (empty($fields)) return;

        $term = $this->search;
        $q->where(function ($w) use ($q, $fields, $term) {
            foreach ($fields as $f) {
                $this->_applySearchField($w, $q, (string) $f, $term);
            }
        });
    }

    /**
     * Um OR da busca global. Sem `_searchColumns` explícito os fields vêm das
     * COLUNAS do grid — que podem ser chain ('{cliente->nome}') ou ref embrulhada
     * ('{nome}'). Mandar isso cru pro orWhere faz o Eloquent ler '->' como JSON
     * path (json_extract("{cliente", '$."nome}"')) e a listagem inteira 500.
     * Mesma tradução do filtro de coluna, inclusive o caminho cross-conexão.
     */
    protected function _applySearchField($w, $q, string $field, string $term): void
    {
        $like = "%{$term}%";

        if (($chain = static::_chainParts($field)) !== null && count($chain) === 2) {
            [$rel, $col] = $chain;

            $meta = $this->_relMeta($rel, $q);
            if ($meta !== null && ! $meta['same']) {
                $ids = $this->_relMatchingIds($meta, $col, 'like', $term);
                if (! empty($ids)) {
                    $w->orWhereIn($meta['fk'], $ids);
                }
                return;
            }

            $model   = static::_gridModel($q);
            $relName = $model !== null ? static::_relMethodName($model, $rel) : null;
            if ($relName !== null) {
                try {
                    $w->orWhereHas($relName, function ($sub) use ($col, $like) {
                        $sub->whereLike($col, $like);
                    });
                    return;
                } catch (\Throwable $e) {
                    // método existe mas não é relação → cai no fallback
                }
            }

            $w->orWhereIn($rel . '_id', function ($sub) use ($rel, $col, $like) {
                $sub->select('id')->from($rel)->whereLike($col, $like);
            });
            return;
        }

        // Notação ponto legada ('familia_produto.nome') → subselect na tabela.
        if (str_contains($field, '.')) {
            [$table, $col] = explode('.', $field, 2);
            // Identificadores vêm da config de colunas; só plain idents
            // entram no SQL (defesa contra tabela/coluna maliciosa).
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                return;
            }
            // Valor (input do usuário) vai PARAMETRIZADO via subquery builder.
            $w->orWhereIn($table . '_id', function ($sub) use ($table, $col, $like) {
                $sub->select('id')->from($table)->whereLike($col, $like);
            });
            return;
        }

        $field = static::_unbrace($field);

        // Chain profundo / resto de chave: ignora esse OR em vez de emitir SQL
        // inválido (fail-closed, igual _applyColWhere).
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            return;
        }

        $w->orWhereLike($field, $like);
    }

    /**
     * Query builder-native (Fase 4c-2) — as 7 camadas direto no Eloquent Builder,
     * sem camada intermediária. Auto-filters via siblings do trait (F4c-1);
     * col-filters/searchbar/sort inline (mesmos ramos que o grid usa do applyFilter:
     * subselect-in, LIKE, escalar). Paginação fica no _autoQuery (count clonado sem
     * order/limit). onSearch dev aplica a closure searchQuery direto no builder.
     */
    protected function _buildQuery($base = null)
    {
        // $base: fonte alternativa (ex: derived table do MadSeekGrid). Default = model.
        $q     = $base ?? $this->model::query();
        $model = $this->model ?: null;

        // (A) período + unit + auto-discovery — siblings builder-native.
        if ($this->autoMergeDashFilters) {
            $this->applyPeriodoToQuery($q, $model);
            $this->applyUnitToQuery($q, $model);
            $this->applyAutoFiltersToQuery($q, $model);
        }

        // (A2) :filters declarativos do Blade — DSL do metric-card. Subselect
        // desabilitado de propósito (prop pública round-tripa no mad_state).
        if (!empty($this->baseFilters)) {
            \Mad\Database\QuerySource::applyArrayFilters($q, $this->baseFilters, false);
        }

        // (B) onSearch custom — builder-native: onSearch() monta a closure
        // searchQuery, aplicada no builder por _applyOnSearch.
        $this->_applyOnSearch($q, $model);

        // (C) busca global (searchbar).
        $this->_applySearch($q);

        // (D) filtros de coluna legados (filter-popover).
        foreach ($this->filters as $field => $value) {
            if (static::_filterValueIsEmpty($value)) continue;
            $op = $this->filterOps[$field] ?? 'like';
            // Sem cast em $value: range/multi são array e o cast os destruiria.
            $this->_applyColWhere($q, (string) $field, (string) $op, $value);
        }

        // (E) filtros de coluna seguros (col-filter com token criptografado).
        foreach ($this->colFilters as $f) {
            // _filterValueIsEmpty e não empty(): empty('0') é true e engolia o
            // filtro booleano "Não".
            if (static::_filterValueIsEmpty($f['value'] ?? null)) continue;
            $op    = $f['op'] ?? 'like';
            $value = $f['value'];

            if (!empty($f['sub'])) {
                // O template de subselect interpola {value} num bind escalar. Com
                // valor array (range/multi) o preg_replace_callback abaixo faria
                // "Array to string conversion" e bindaria o literal 'Array'.
                if (! is_scalar($value)) {
                    continue;
                }
                // field vem do token assinado (MadStateCrypt); guard de identificador
                // por defesa em profundidade — fail-closed se não for coluna válida.
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', (string) $f['field'])) {
                    continue;
                }
                // Defesa em profundidade: mesmo vindo do token assinado, rejeita
                // template com terminador de statement / comentário (empilhamento,
                // comment-injection). Um template legítimo nunca os contém. Cobre o
                // caso de colFilters ser envenenado por qualquer canal futuro.
                if (preg_match('/;|--|\/\*|\*\/|\x00/', (string) $f['sub'])) {
                    continue;
                }
                // $f['sub'] é template SQL assinado; o {value} (input do usuário) vira
                // bind posicional ? em vez de ser escapado e inlined. Literal entre
                // aspas ('%{value}%') preserva os wildcards dentro do bind.
                $binds = [];
                $sub = preg_replace_callback(
                    "/'([^'\\\\;]*)\\{value\\}([^'\\\\;]*)'|\\{value\\}/",
                    function ($m) use (&$binds, $value) {
                        $binds[] = ($m[0][0] === "'") ? ($m[1] . $value . ($m[2] ?? '')) : $value;
                        return '?';
                    },
                    $f['sub']
                );
                $q->whereRaw("{$f['field']} in ({$sub})", $binds);
            } else {
                $this->_applyColWhere($q, (string) $f['field'], (string) $op, $value);
            }
        }

        // (E2) filtro avançado do usuário final (<mad-custom-filters>): UM grupo
        // `( … AND/OR … )` com as condições revalidadas contra as defs. Vem
        // depois dos filtros de coluna e antes do sort — o export passa pelo
        // mesmo _buildQuery, então CSV/XLSX/PDF saem filtrados igual à tela.
        $this->_applyCustomFilters($q);

        // (F) sort — _isSortableField guard + OrderGuard anti-injection (emite RAW
        // já validado, nunca orderBy() que escaparia).
        $orderExpr = '';
        $ordered   = false;   // já ordenado no builder (subquery de chain)
        if (!empty($this->sortBy) && $this->_isSortableField($this->sortBy)) {
            $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';
            // `data|day` ordena pela coluna `data` — o sufixo de granularidade
            // não é identificador SQL e o OrderGuard rejeitaria.
            $sortField = GridRenderHelpers::groupGrainBase($this->sortBy);
            // '{rel->col}' não é identificador SQL — o OrderGuard rejeitaria e a
            // listagem 500 no clique do header. Traduz pra subquery correlacionada
            // (mesma conexão) ou desiste (outra conexão: ORDER BY não atravessa banco).
            if (($chain = static::_chainParts($sortField)) !== null) {
                $ordered = $this->_applyChainOrder($q, $chain, $dir);
                if (! $ordered) {
                    static::_warnOnce(
                        'sort-chain:' . $sortField,
                        'ordenação por "' . $sortField . '" ignorada: caminho de relação '
                        . 'sem ORDER BY possível (relação não-belongsTo ou em outra conexão).'
                    );
                }
            } else {
                $orderExpr = static::_unbrace($sortField) . ' ' . $dir;
            }
        }
        if (! $ordered && $orderExpr === '') {
            // order-by do Blade (declarativo) / defaultSort da subclasse. Vai
            // termo a termo: chain ('rubrica->codigo desc') não é identificador
            // SQL e ia CRUA pro OrderGuard — exceção "Invalid ORDER BY" = 500 na
            // abertura da tela, sem clique nenhum.
            $declared = $this->baseOrder !== '' ? $this->baseOrder : $this->defaultSort;
            if ($declared !== '') {
                $ordered = $this->_applyDeclaredOrder($q, $declared) || $ordered;
            }
        } elseif ($orderExpr !== '') {
            \Mad\Database\OrderGuard::validate($orderExpr, 'ORDER BY');
            $q->orderByRaw($orderExpr);
        }

        if (! $ordered && $orderExpr === '' && $this->_hasDerivedGroup()) {
            static::_warnOnce(
                'group-chain-unordered',
                'quebra por caminho de relação ou por dia/mês/ano sem ordenação declarada '
                . '— os grupos saem na ordem que o banco devolver e podem aparecer '
                . 'repetidos. Declare order-by="campo asc" no <mad-grid>.'
            );
        }

        return $q;
    }

    /**
     * Há quebra cuja chave NÃO é a coluna crua — caminho de relação
     * (`rubrica->codigo`) ou granularidade (`data|day`)?
     *
     * Nos dois casos o banco não ordena pela chave do grupo sozinho: sem
     * `order-by` declarado o mesmo grupo pode aparecer em duas bandas.
     */
    protected function _hasDerivedGroup(): bool
    {
        foreach ($this->_groupFields() as $f) {
            $field = (string) $f;
            if (str_contains($field, '->')) return true;
            if (GridRenderHelpers::splitGroupGrain($field)[1] !== null) return true;
        }
        return false;
    }

    /**
     * Aplica o ORDER BY declarado (`order-by` / `defaultSort`) termo a termo.
     *
     * Termo com caminho de relação vira subquery correlacionada; termo simples
     * segue pelo OrderGuard, como sempre. Caminho sem ORDER BY possível (outra
     * conexão, relação que não é belongsTo) é IGNORADO com aviso — a listagem
     * abre sem essa ordenação em vez de estourar.
     *
     * @return bool algum termo foi aplicado por subquery de relação
     */
    protected function _applyDeclaredOrder($q, string $expr): bool
    {
        $usedChain = false;

        foreach (explode(',', $expr) as $term) {
            $term = trim($term);
            if ($term === '') continue;

            $bits  = preg_split('/\s+/', $term) ?: [$term];
            // `order-by="data|day asc"` ordena pela coluna `data` (o sufixo é da
            // QUEBRA); sem o strip o termo iria cru pro OrderGuard = 500 na tela.
            $field = GridRenderHelpers::groupGrainBase($bits[0] ?? '');
            $dir   = strtolower($bits[1] ?? '');
            $simple = count($bits) <= 2 && ($dir === '' || $dir === 'asc' || $dir === 'desc');

            if ($simple && ($chain = static::_chainParts($field)) !== null) {
                if ($this->_applyChainOrder($q, $chain, $dir === 'desc' ? 'desc' : 'asc')) {
                    $usedChain = true;
                } else {
                    static::_warnOnce(
                        'order-chain:' . $field,
                        'ordenação declarada por "' . $field . '" ignorada: caminho de relação '
                        . 'sem ORDER BY possível (relação não-belongsTo ou em outra conexão).'
                    );
                }
                continue;
            }

            // `{nome} asc` (ref embrulhada do editor) não é identificador SQL —
            // desembrulha antes do guard, senão a tela 500 sem clique nenhum.
            $plain = $simple
                ? static::_unbrace($field) . ($dir !== '' ? ' ' . $dir : '')
                : $term;

            \Mad\Database\OrderGuard::validate($plain, 'ORDER BY');
            $q->orderByRaw($plain);
        }

        return $usedChain;
    }

    /**
     * Executa a query: count (clone sem order; perPage=0/export conta tudo) +
     * paginação + eager-load. Tolera Eloquent|Query Builder (derived table do
     * MadSeekGrid — with() só em Eloquent).
     */
    protected function _runQuery($itemsQ): array
    {
        $countQ = clone $itemsQ;
        $countBuilder = $this->_queryBuilder($countQ);
        $countBuilder->orders = null;
        // ORDER BY por subquery (chain '{rel->col}') carrega bindings; zerar só
        // `orders` deixaria binds órfãos e o count estouraria por aridade.
        $countBuilder->bindings['order'] = [];
        if ($this->searchQuery) { ($this->searchQuery)($countQ); }
        $total = (int) $countQ->count();

        if ($this->perPage > 0) {
            $itemsQ->offset(($this->page - 1) * $this->perPage)->limit($this->perPage);
        }
        if ($this->searchQuery) { ($this->searchQuery)($itemsQ); }
        // `with` declarado pela subclasse + o derivado das chains. Sem o
        // derivado, cada `{rel->col}` de coluna ou de quebra dispara um SELECT
        // por linha (50 linhas = 51 consultas).
        $with = $this->with;
        foreach ($this->_autoWith as $rel) {
            if (in_array($rel, $with, true) || array_key_exists($rel, $with)) {
                continue;   // ja declarado (inclusive na forma relacao => closure)
            }
            $with[] = $rel;
        }
        if (!empty($with) && method_exists($itemsQ, 'with')) {
            $itemsQ->with($with);
        }

        return ['items' => $itemsQ->get()->all(), 'total' => $total];
    }

    /** Query automática builder-native (Fase 4c-2/4). Conexão do model (Eloquent). */
    protected function _autoQuery(): array
    {
        if (empty($this->model)) return ['items' => [], 'total' => 0];
        return $this->_runQuery($this->_buildQuery());
    }

    /**
     * Detecta campos com {relacao->campo} nas colunas para uso no render().
     *
     * A QUEBRA entra junto: `group-by="rubrica->codigo"` precisa da chave
     * materializada na linha (`$row['rubrica->codigo']`), senao o achatamento
     * de grupos, o reset do saldo acumulado e a mascara procuram uma chave que
     * nao existe e tudo cai num grupo vazio so — em silencio.
     *
     * O mesmo levantamento produz o eager-load: cada chain vira a relacao
     * correspondente no `with()` da consulta, senao materializar a chave
     * custaria uma consulta por linha.
     */
    protected function _detectRenderFields(array $columns): void
    {
        $this->_renderFields = GridRenderHelpers::detectRenderFields($columns)
            + GridRenderHelpers::groupRenderFields($this->_groupFields());

        $this->_autoWith = GridRenderHelpers::deriveEagerLoads(
            array_keys($this->_renderFields),
            $this->model ?: null
        );
        $this->_renderFieldsDetected = true;
    }

    /** Valor de um campo na linha tolerando `{a->b}` / `a->b` / `__record`. */
    protected static function _rowValue(array $row, string $field): mixed
    {
        return GridRenderHelpers::rowValue($row, $field);
    }

    /** Normaliza itens para array puro (delega ao helper compartilhado). */
    protected function _normalizeRows(array $items): array
    {
        return GridRenderHelpers::normalizeRows($items, $this->_renderFields);
    }

    /** Calcula totalizadores de rodapé (delega ao helper compartilhado). */
    protected function _computeTotals(array $columns): array
    {
        return GridRenderHelpers::computeTotals($this->_rows, $columns);
    }

    /** @var array Cache de labels resolvidos para groupMask {relacao->campo} */
    protected array $_groupLabelCache = [];

    /**
     * Pre-resolve placeholders de relacionamento no groupMask.
     * Chamado DENTRO de loadData(); relacoes resolvem via lazy-load do Eloquent.
     */
    protected function _resolveGroupLabels(): array
    {
        $fields = $this->_groupFields();
        if (empty($fields)) return [];

        $masks = $this->_groupMasks($fields);

        $cache = [];
        foreach ($fields as $i => $field) {
            $mask = $masks[$i] ?? '';
            if (!$mask || strpos($mask, '{') === false) continue;

            // Coleta valores unicos deste campo + um sample row. O balde VAZIO
            // (data nula) entra junto: sem ele a banda desse grupo caía no
            // fallback de str_replace e imprimia o token da máscara cru.
            $uniqueVals = [];
            foreach ($this->_rows as $row) {
                $val = (string)(static::_rowValue($row, $field) ?? '');
                if (!array_key_exists($val, $uniqueVals)) {
                    $uniqueVals[$val] = $row;
                }
            }

            foreach ($uniqueVals as $val => $sampleRow) {
                $cache[$field . ':' . $val] = static::_renderMask($mask, $sampleRow);
            }
        }
        return $cache;
    }

    /**
     * Campos de quebra normalizados (string simples, "a,b" já explodido pelo
     * _renderInlineGrid, ou array). 4 linhas que viviam duplicadas.
     *
     * @return string[]
     */
    protected function _groupFields(): array
    {
        $fields = is_array($this->groupBy)
            ? array_values(array_filter($this->groupBy))
            : (empty($this->groupBy) ? [] : [$this->groupBy]);

        if (empty($fields)) {
            return $fields;
        }

        // Granularidade (`data_venda|day`): sufixo desconhecido cai fora com
        // aviso — ver GridRenderHelpers::normalizeGroupField.
        $fields = array_map(
            fn ($f) => GridRenderHelpers::normalizeGroupField((string) $f),
            $fields
        );

        if (empty($this->model)) {
            return $fields;
        }

        // Fail-closed: quebra por relação que o model não tem como resolver
        // (nem relação declarada, nem model homônimo pela convenção de FK)
        // jogaria TODAS as linhas num grupo de valor vazio, sem sinal nenhum.
        // Ignorar o nível com aviso é menos pior do que a tela mentir.
        $out = [];
        foreach ($fields as $field) {
            $f = (string) $field;
            // A validação é sobre o campo BASE: `venda->data|day` é a MESMA
            // relação de `venda->data`; o sufixo só diz como o valor vira chave.
            $base = GridRenderHelpers::groupGrainBase($f);
            if (! str_contains($base, '->') || GridRenderHelpers::isResolvableChain($this->model, $base)) {
                $out[] = $field;
                continue;
            }
            static::_warnOnce(
                'group-chain:' . $f,
                'quebra por "' . $f . '" ignorada: ' . $this->model . ' não declara a relação "'
                . trim(explode('->', GridRenderHelpers::unbraceChain($base))[0])
                . '" nem existe model com esse nome. Corrija o group-by do <mad-grid>.'
            );
        }

        return $out;
    }

    /**
     * Máscara por nível de quebra (string única replicada, ou array).
     *
     * @param string[] $fields
     * @return string[]
     */
    protected function _groupMasks(array $fields): array
    {
        return is_array($this->groupMask)
            ? $this->groupMask
            : array_fill(0, count($fields), (string)$this->groupMask);
    }

    /**
     * Renderiza uma máscara `{campo}` / `{campo|formatter}` / `{rel->campo}`
     * contra uma linha. Ponto ÚNICO usado por group-mask, row-detail e
     * group-total-label.
     *
     * Devolve TEXTO PURO: o formatter sai do \Mad\Support\ValueFormatter (o
     * mesmo catálogo do formatter-select do editor) e NÃO do
     * GridColumn::renderValue, que devolve HTML — o Blade imprime a máscara com
     * `{{ }}` e o HTML apareceria escapado na tela.
     *
     * Token desconhecido fica CRU (`{foo}`), sinalizando o erro em vez de
     * apagar silenciosamente o pedaço da máscara.
     */
    protected static function _renderMask(string $mask, array $row): string
    {
        if ($mask === '' || strpos($mask, '{') === false) {
            return $mask;
        }

        return (string) preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($row) {
            $token = $m[1];
            $fmt   = '';
            if (strpos($token, '|') !== false) {
                [$token, $fmt] = explode('|', $token, 2);
                $fmt = trim($fmt);
            }
            $key = trim($token);

            // Chave direta, a outra grafia da chain (`{a->b}` ↔ `a->b`) e, em
            // último caso, o próprio registro da linha. O fallback antigo
            // instanciava `new ucfirst($rel)` — classe que nunca existiu no
            // namespace dos models — e por isso `{vendedor->nome}` numa máscara
            // de quebra saía LITERAL na tela.
            $val = GridRenderHelpers::rowValue($row, $key);

            if ($val === null) {
                // Campo que EXISTE na linha e está vazio (data nula) renderiza
                // vazio; token que a linha não tem continua CRU, sinalizando o
                // erro. Antes os dois saíam crus e a banda de um grupo sem data
                // levava `{data|date}` literal para a tela, o CSV e o PDF.
                return GridRenderHelpers::rowHasField($row, $key) ? '' : $m[0];
            }
            if (is_array($val) || (is_object($val) && ! method_exists($val, '__toString'))) {
                return $m[0];
            }

            // `{data|day}` / `|month` / `|year` / `|week`: os MESMOS baldes do
            // `group-by="data|day"`, para a máscara poder repetir o rótulo que
            // a quebra já produz. Não entram no catálogo do ValueFormatter (que
            // é lockstep com o formatter-select do editor) — `{data|date}` e os
            // demais `date*` continuam valendo aqui, inalterados.
            if ($fmt !== '' && in_array(strtolower($fmt), GridRenderHelpers::GROUP_GRAINS, true)) {
                return GridRenderHelpers::groupGrainLabel(
                    GridRenderHelpers::groupGrainKey($val, $fmt),
                    $fmt
                );
            }

            return $fmt !== ''
                ? \Mad\Support\ValueFormatter::apply($val, $fmt)
                : (string) $val;
        }, $mask);
    }

    /**
     * Rótulo do sub-total da quebra. `{group}` = o label do grupo; o resto da
     * máscara resolve contra a primeira linha do grupo (mesma sintaxe do
     * group-mask, formatter incluído).
     */
    protected function _groupTotalLabelFor(string $groupLabel, array $sampleRow): string
    {
        if ($this->groupTotalLabel === '') {
            return '';
        }

        return static::_renderMask(
            str_replace('{group}', $groupLabel, $this->groupTotalLabel),
            $sampleRow
        );
    }

    /** Produz lista achatada de itens (group/row/group-total) para o template. */
    protected function _computeGroupData(array $columns): array
    {
        $fields = $this->_groupFields();
        if (empty($fields)) return [];

        return $this->_flattenGroups($this->_rows, $fields, $this->_groupMasks($fields), 0, $columns);
    }

    /**
     * Achata grupos aninhados recursivamente em lista sequencial para o Blade.
     *
     * Cada item pode ser:
     *   ['type'=>'group',       'level'=>N, 'label'=>'...', 'field'=>'...', 'value'=>'...', 'count'=>N]
     *   ['type'=>'row',         'level'=>N, 'data'=>[...row array...]]
     *   ['type'=>'group-total', 'level'=>N, 'label'=>'...', 'totals'=>[...]]
     */
    protected function _flattenGroups(array $rows, array $fields, array $masks, int $depth, array $columns): array
    {
        if (empty($fields)) {
            $items = [];
            foreach ($rows as $row) {
                $items[] = ['type' => 'row', 'level' => $depth, 'data' => $row];
            }
            return $items;
        }

        $field           = $fields[0];
        $mask            = $masks[0] ?? '';
        $remainingFields = array_slice($fields, 1);
        $remainingMasks  = array_slice($masks, 1);

        // Agrupa preservando a ordem de aparição
        $grouped = [];
        $order   = [];
        foreach ($rows as $row) {
            $val = (string)(static::_rowValue($row, $field) ?? '');
            if (!array_key_exists($val, $grouped)) {
                $grouped[$val] = [];
                $order[]       = $val;
            }
            $grouped[$val][] = $row;
        }

        $items = [];
        foreach ($order as $val) {
            $groupRows = $grouped[$val];
            // Usa cache de labels pre-resolvido no loadData()
            $cacheKey = $field . ':' . $val;
            // Sem máscara o rótulo é o próprio valor — ou a data do balde no
            // formato do locale, quando a quebra declara granularidade
            // (`data_venda|day` → "09/03/2026").
            $shown = GridRenderHelpers::groupDefaultLabel($field, $val);
            if (isset($this->_groupLabelCache[$cacheKey])) {
                $label = $this->_groupLabelCache[$cacheKey];
            } elseif ($mask) {
                // O token da máscara pode vir com ou sem chaves em volta da
                // chain — `{rel->col}` é o que o editor persiste, `rel->col` é
                // o que o group-by declara — e, na quebra granular, também
                // `{data|day}` / `{data}`.
                $label = str_replace(GridRenderHelpers::groupMaskTokens($field), $shown, $mask);
            } else {
                $label = $shown;
            }

            $groupTotals = $this->groupTotal ? $this->_computeTotalsForRows($groupRows, $columns) : [];

            $items[] = [
                'type'   => 'group',
                'level'  => $depth,
                'label'  => $label,
                'field'  => $field,
                'value'  => $val,
                'count'  => count($groupRows),
                'totals' => $groupTotals,
            ];

            // Filhos: sub-grupos ou linhas
            foreach ($this->_flattenGroups($groupRows, $remainingFields, $remainingMasks, $depth + 1, $columns) as $child) {
                $items[] = $child;
            }

            // Total por grupo (rodapé do grupo)
            if ($this->groupTotal) {
                $total = [
                    'type'   => 'group-total',
                    'level'  => $depth,
                    'label'  => $label,
                    'totals' => $groupTotals,
                ];
                // `totalLabel` só existe quando group-total-label foi declarado
                // — assim o shape do groupData de sempre não muda.
                if ($this->groupTotalLabel !== '') {
                    $total['totalLabel'] = $this->_groupTotalLabelFor($label, $groupRows[0] ?? []);
                }
                $items[] = $total;
            }
        }

        return $items;
    }

    /**
     * Totais de um GRUPO. Mesmo cálculo do rodapé geral — delegar em vez de
     * duplicar o switch é o que faz `total-mask`, `total="last"` e a regra do
     * saldo acumulado valerem também no sub-total da quebra (antes o total
     * geral formatava com a máscara e o de grupo ignorava).
     */
    protected function _computeTotalsForRows(array $rows, array $columns): array
    {
        return GridRenderHelpers::computeTotals($rows, $columns);
    }

    // ── Saldo acumulado (running balance) + linha descritiva ─────────────

    /**
     * Pós-processamento das linhas carregadas. Roda no fim do loadData() e
     * depois de cada re-normalização (que reconstrói $_rows dos items crus e
     * apagaria o que foi materializado aqui).
     *
     * @param GridColumn[] $columns
     */
    protected function _postProcessRows(array $columns): void
    {
        $this->_applyRunning($columns);
        $this->_applyRowDetail();
    }

    /** Avisa uma vez por classe (molde do queryIgnored). */
    private static function _warnOnce(string $tag, string $msg): void
    {
        static $warned = [];
        $key = static::class . '|' . $tag;
        if (!empty($warned[$key])) return;
        $warned[$key] = true;
        \Illuminate\Support\Facades\Log::warning(static::class . ': ' . $msg);
    }

    /**
     * Materializa o SALDO ACUMULADO das colunas `running` em $_rows.
     *
     * Por que em $_rows e não no achatamento dos grupos: `_computeGroupData`
     * roda 3× por render (view(), _renderInlineGrid, export) e os caminhos sem
     * quebra — tela, CSV, XLSX, PDF, totais — leem `$rows` direto. Materializar
     * uma vez é o que mantém os seis consistentes.
     *
     * @param GridColumn[] $columns
     */
    protected function _applyRunning(array $columns): void
    {
        $running = array_values(array_filter(
            $columns,
            fn($c) => $c instanceof GridColumn && $c->isRunning()
        ));
        if (empty($running) || empty($this->_rows)) {
            return;
        }

        $groupFields = $this->_groupFields();

        // Ordem de EXIBIÇÃO. Sem isto o prefix-sum seguiria a ordem da query
        // enquanto a tela mostra as linhas agrupadas — saldo e tela divergiriam.
        if (!empty($groupFields)) {
            $this->_rows = static::_rowsInGroupOrder($this->_rows, $groupFields);
        }

        if ($this->perPage > 0) {
            static::_warnOnce(
                'running-paginated',
                'coluna com saldo acumulado (running) em grid paginado — o saldo acumula '
                . 'dentro da PÁGINA. Use per-page="0" (o padrão do tipo relatório) para o '
                . 'saldo correr sobre o resultado inteiro.'
            );
        }
        if ($this->sortBy === '' && $this->baseOrder === '' && $this->defaultSort === '') {
            static::_warnOnce(
                'running-unordered',
                'coluna com saldo acumulado (running) sem ordenação declarada — o saldo '
                . 'segue a ordem que o banco devolver. Declare order-by="campo asc" no '
                . '<mad-grid> para o resultado ser estável.'
            );
        }

        foreach ($running as $col) {
            $field = $col->field;
            $level = static::_runningResetLevel($col->runningReset);
            if ($level === null || empty($groupFields)) {
                $level = null;   // sem quebra não há o que zerar
            }

            $acc          = null;
            $lastGroupKey = null;

            foreach ($this->_rows as $i => $row) {
                // Snapshot idempotente do delta: numa 2ª passada sobre as MESMAS
                // linhas o campo já guarda o acumulado, e sem isto o saldo
                // acumularia o acumulado.
                $srcKey = '__run_src_' . $col->fieldKey;
                if (array_key_exists($srcKey, $row)) {
                    $delta = (float) $row[$srcKey];
                } else {
                    $delta = static::_runningDelta($col, $row);
                    $this->_rows[$i][$srcKey] = $delta;
                }

                $gkey = $level === null ? '' : static::_groupKeyUpTo($row, $groupFields, $level);
                if ($acc === null || $gkey !== $lastGroupKey) {
                    $acc          = static::_runningStart($col, $row);
                    $lastGroupKey = $gkey;
                }

                $acc += $delta;
                $this->_rows[$i][$field] = $acc;
            }
        }
    }

    /**
     * Materializa a linha descritiva (`row-detail`) em `$row['__detail']`.
     * Uma passada só, no load — o Blade tem TRÊS pontos de render de `<tr>`
     * (ramo com grupo, ramo sem grupo e o partial do manage_row) e o PDF um
     * quarto; resolver a máscara em cada um deles é como eles divergem.
     */
    protected function _applyRowDetail(): void
    {
        if ($this->rowDetail === '' || empty($this->_rows)) {
            return;
        }

        foreach ($this->_rows as $i => $row) {
            $this->_rows[$i]['__detail'] = static::_renderMask($this->rowDetail, $row);
        }
    }

    /** Delta de UMA linha: o próprio campo (`self`) ou a expressão declarada. */
    protected static function _runningDelta(GridColumn $col, array $row): float
    {
        $expr = $col->running;

        if ($expr === '' || $expr === 'self') {
            $v = $row[$col->field] ?? 0;
            return is_numeric($v) ? (float) $v : 0.0;
        }

        // Atalho para `{campo}` puro (inclusive `{rel->campo}` já materializado
        // pelos renderFields): lê direto, sem passar pelo eval.
        if (preg_match('/^\{([^}]+)\}$/', trim($expr), $m)) {
            $key = trim($m[1]);
            if (isset($row[$key])) {
                return is_numeric($row[$key]) ? (float) $row[$key] : 0.0;
            }
        }

        // Expressão: mesma DSL e mesmo sanitizador do `evaluate`.
        $v = static::_computeEvaluate($expr, $row['__record'] ?? $row, $row);
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Saldo inicial do escopo (número ou expressão sobre a linha). */
    protected static function _runningStart(GridColumn $col, array $row): float
    {
        $start = trim($col->runningStart);
        if ($start === '') {
            return 0.0;
        }
        if (is_numeric($start)) {
            return (float) $start;
        }

        $v = static::_computeEvaluate($start, $row['__record'] ?? $row, $row);
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Nível de quebra em que o acumulador zera.
     * '' / 'none' = nunca · 'group' = nível 0 · 'group:N' = nível N.
     */
    protected static function _runningResetLevel(string $reset): ?int
    {
        $reset = strtolower(trim($reset));
        if ($reset === '' || $reset === 'none') return null;
        if ($reset === 'group') return 0;
        if (preg_match('/^group:(\d+)$/', $reset, $m)) return (int) $m[1];

        return null;
    }

    /** Chave do grupo até o nível $level (identidade do escopo de reset). */
    protected static function _groupKeyUpTo(array $row, array $fields, int $level): string
    {
        $parts = [];
        $max   = min($level, count($fields) - 1);
        for ($i = 0; $i <= $max; $i++) {
            $parts[] = (string) (static::_rowValue($row, $fields[$i]) ?? '');
        }

        return implode("\x1F", $parts);
    }

    /**
     * Reordena as linhas para a ORDEM DE EXIBIÇÃO da quebra — grupos por
     * primeira aparição, recursivamente. Espelho exato do `_flattenGroups`;
     * um divergir do outro faria o saldo da tela não bater com o do PDF.
     */
    protected static function _rowsInGroupOrder(array $rows, array $fields): array
    {
        if (empty($fields)) {
            return array_values($rows);
        }

        $field   = $fields[0];
        $rest    = array_slice($fields, 1);
        $grouped = [];
        $order   = [];
        foreach ($rows as $row) {
            $val = (string) (static::_rowValue($row, $field) ?? '');
            if (!array_key_exists($val, $grouped)) {
                $grouped[$val] = [];
                $order[]       = $val;
            }
            $grouped[$val][] = $row;
        }

        $out = [];
        foreach ($order as $val) {
            foreach (static::_rowsInGroupOrder($grouped[$val], $rest) as $r) {
                $out[] = $r;
            }
        }

        return $out;
    }

    // ── Evaluate engine ──────────────────────────────────────────────────

    /**
     * Aplica expressões evaluate nos itens (objetos/arrays) ANTES de normalizar.
     * Caminhos de relacionamento (->) resolvem via lazy-load do Eloquent.
     *
     * @param array<object|array> &$items
     * @param array<string,string> $evaluates  campo => expressão
     */
    protected function _applyEvaluates(array &$items, array $evaluates): void
    {
        // Caminhos de relacionamento ('->') resolvem via lazy-load do Eloquent
        // — sem necessidade de transacao aberta.
        foreach ($items as &$item) {
            $row = is_object($item) && method_exists($item, 'toArray')
                ? $item->toArray()
                : (is_object($item) ? (array)$item : $item);

            foreach ($evaluates as $field => $expr) {
                $value = static::_computeEvaluate($expr, $item, $row);
                if (is_object($item)) {
                    $item->$field = $value;
                } else {
                    $item[$field] = $value;
                }
            }
        }
        unset($item);
    }

    /**
     * Computa uma expressão evaluate substituindo {placeholders} por valores.
     *
     * @param string       $expr  Ex: '{valor} * {quantidade}'
     * @param object|array $item  Item original (objeto legado para traversal)
     * @param array        $row   Item como array (campos simples)
     */
    protected static function _computeEvaluate(string $expr, $item, array $row): mixed
    {
        $math = preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($item, $row) {
            $key = trim($m[1]);

            // Caminho de relacionamento: produto->tipo_produto->percentual
            if (str_contains($key, '->') && is_object($item)) {
                $v = static::_resolveObjectPath($item, $key);
                return is_numeric($v) ? $v : '0';
            }

            // Campo simples do array
            if (array_key_exists($key, $row)) {
                $v = $row[$key];
                return is_numeric($v) ? $v : '0';
            }

            // Tenta propriedade do objeto
            if (is_object($item)) {
                $v = $item->$key ?? null;
                return ($v !== null && is_numeric($v)) ? $v : '0';
            }

            return '0';
        }, $expr);

        // Sanitiza: permite apenas dígitos, ponto, operadores, parênteses, espaços
        $safe = preg_replace('/[^0-9.+\-*\/() ]/', '', $math);
        if ($safe === '' || !preg_match('/\d/', $safe)) {
            return 0;
        }

        try {
            $result = @eval("return ({$safe});");
            if (is_numeric($result) && is_finite((float)$result)) {
                return (float)$result;
            }
            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * ORDER BY por chain ('{cliente->nome}'): subquery correlacionada na tabela
     * do relacionado. Só existe SQL possível quando as duas pontas estão na MESMA
     * conexão — cross-conexão (db-fk) devolve false e o grid segue sem essa
     * ordenação em vez de estourar.
     */
    protected function _applyChainOrder($q, array $parts, string $dir): bool
    {
        $model = static::_gridModel($q);
        if ($model === null || count($parts) < 2) {
            return false;
        }

        $path  = implode('->', $parts);
        $col   = array_pop($parts);           // último segmento = coluna
        $metas = $this->_chainRelMetas($parts, get_class($model), $model);
        if ($metas === null) {
            return false;
        }

        try {
            // Subqueries correlacionadas encadeadas. `a->b->c` vira
            //   order by (select (select c from b where b.id = a.b_id limit 1)
            //             from a where a.id = grid.a_id limit 1)
            $sub = $this->_chainOrderSub($metas, $col, $model->getTable(), 0);
            if ($sub === null) {
                return false;
            }

            $q->orderBy($sub, $dir);
            return true;
        } catch (\Throwable $e) {
            @error_log('[MadDataGrid] sort por ' . $path . ' falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Subquery correlacionada do nível $i da chain, recursiva.
     * Nível folha seleciona a COLUNA; os de cima selecionam a subquery de baixo.
     *
     * @param array<int, array> $metas
     */
    protected function _chainOrderSub(array $metas, string $col, string $ownerTable, int $i)
    {
        if (! isset($metas[$i])) {
            return null;
        }
        $meta    = $metas[$i];
        $cls     = $meta['class'];
        $related = new $cls();
        $q       = $cls::query()
            ->whereColumn(
                $related->getTable() . '.' . $meta['ownerKey'],
                $ownerTable . '.' . $meta['fk'],
            )
            ->limit(1);

        if ($i === count($metas) - 1) {
            return $q->select($col);
        }

        $inner = $this->_chainOrderSub($metas, $col, $related->getTable(), $i + 1);
        if ($inner === null) {
            return null;
        }

        return $q->selectSub($inner, 'mad_chain_order');
    }

    /**
     * Metadados de CADA salto da chain, na ordem. null quando algum segmento
     * não é belongsTo ou quando o caminho atravessa conexão — ORDER BY não
     * cruza banco, e prometer a ordenação seria pior do que não ordenar.
     *
     * @param string[] $rels segmentos de RELAÇÃO (sem a coluna final)
     * @return array<int, array>|null
     */
    protected function _chainRelMetas(array $rels, string $ownerClass, $ownerInstance = null): ?array
    {
        $metas  = [];
        $cursor = $ownerClass;
        foreach ($rels as $i => $rel) {
            $meta = $this->_relMetaOn($cursor, $rel, $i === 0 ? $ownerInstance : null);
            if ($meta === null) {
                @error_log('[MadDataGrid] sort por "' . $rel . '" ignorado: '
                    . $cursor . ' não declara essa relação belongsTo.');
                return null;
            }
            if (! $meta['same']) {
                @error_log('[MadDataGrid] sort por "' . $rel . '" ignorado: '
                    . $meta['class'] . ' está em outra conexão (ORDER BY não atravessa banco).');
                return null;
            }
            $metas[] = $meta;
            $cursor  = $meta['class'];
        }

        return empty($metas) ? null : $metas;
    }

    /**
     * Teto de ids materializados ao filtrar/ordenar por relação que vive em
     * OUTRA conexão (db-fk). Truncar é logado — nunca silencioso.
     */
    protected const REL_ID_CAP = 5000;

    /**
     * Operadores aceitos num filtro de coluna. Allowlist DURA: o `where()` do
     * Laravel rebaixa operador desconhecido a VALOR — `where($f,'=','between')`
     * — em silêncio, então validar aqui é a diferença entre "não filtra" e
     * "filtra errado sem avisar".
     *
     * `date` e `date between` são pseudo-ops MAD: existem para `_whereOp` saber
     * usar whereDate/fronteiras de dia sem ganhar um parâmetro a mais.
     *
     * Do filtro avançado (<mad-custom-filters>): `starts`/`ends` (LIKE ancorado),
     * `empty`/`not empty` (NULL ou '' — só oferecidos a colunas de TEXTO) e
     * `date preset` (período nomeado, ver DATE_PRESETS). Entrar aqui também os
     * libera no `filter-op-select` legado, e lá continuam seguros: o `_whereOp`
     * ignora o valor nos sem-valor e descarta preset desconhecido.
     */
    protected const FILTER_OPS = [
        '=', '!=', '<>', '>', '>=', '<', '<=',
        'like', 'not like', 'in', 'not in',
        'between', 'date', 'date between',
        'is null', 'is not null',
        'starts', 'ends', 'empty', 'not empty', 'date preset',
    ];

    /**
     * Períodos nomeados do operador `date preset` (id → sem rótulo; o rótulo é
     * `mad.gridcf.preset.<id>`). Allowlist: o valor da regra só pode ser um
     * destes ids — nunca uma data solta.
     */
    public const DATE_PRESETS = [
        'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'this_year', 'last_year', 'last_7_days', 'last_30_days', 'last_90_days',
        'next_7_days', 'next_30_days',
    ];

    /**
     * Teto de binds num `IN (...)`. Postgres aceita 65535 binds e SQL Server
     * 2100 — uma lista sem teto vinda do cliente derruba a conexão.
     */
    protected const FILTER_IN_CAP = 500;

    /** Cache de metadados de relação, keyed por "ModelFqcn::relacao". */
    private static array $_relMetaCache = [];

    /**
     * Normaliza e valida o operador. Devolve '' quando não é reconhecido — o
     * chamador trata como fail-closed (não filtra) em vez de deixar o Laravel
     * rebaixá-lo a valor.
     */
    protected static function _canonicalOp(string $op): string
    {
        $op = strtolower(trim($op));
        if ($op === '') {
            return '';
        }
        $op = match ($op) {
            '<>'          => '!=',
            'nin', 'notin', 'not_in'      => 'not in',
            'notlike', 'not_like'         => 'not like',
            'daterange', 'date_between'   => 'date between',
            'isnull', 'is_null'           => 'is null',
            'isnotnull', 'is_not_null'    => 'is not null',
            'startswith', 'starts_with', 'starts with', 'begins' => 'starts',
            'endswith', 'ends_with', 'ends with'                 => 'ends',
            'is empty', 'isempty', 'is_empty', 'blank'           => 'empty',
            'notempty', 'not_empty', 'is not empty', 'isnotempty', 'is_not_empty', 'filled' => 'not empty',
            'preset', 'date_preset', 'datepreset', 'period'      => 'date preset',
            default       => $op,
        };
        return in_array($op, static::FILTER_OPS, true) ? $op : '';
    }

    /**
     * Forma canônica de um operador ('' = não reconhecido). Pública para o
     * compilador validar o `ops=` do <mad-custom-filter> contra o MESMO
     * vocabulário do runtime. Estática: não é ação invocável pelo wire.
     */
    public static function canonicalFilterOp(string $op): string
    {
        return static::_canonicalOp($op);
    }

    /**
     * Intervalo [início, fim] (Y-m-d, inclusivos) de um período nomeado do
     * `date preset`, calculado com now() no fuso do app — semana começa na
     * SEGUNDA. "Últimos N dias" e "Próximos N dias" incluem hoje. null = id
     * fora da allowlist (o chamador não filtra).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function datePresetRange(string $id): ?array
    {
        if (! in_array($id, static::DATE_PRESETS, true)) {
            return null;
        }

        $today = \Illuminate\Support\Carbon::now()->startOfDay();
        $d     = fn ($c) => $c->format('Y-m-d');

        return match ($id) {
            'today'        => [$d($today), $d($today)],
            'yesterday'    => [$d($today->copy()->subDay()), $d($today->copy()->subDay())],
            'this_week'    => [$d($today->copy()->startOfWeek(\Carbon\CarbonInterface::MONDAY)),
                               $d($today->copy()->endOfWeek(\Carbon\CarbonInterface::SUNDAY))],
            'last_week'    => [$d($today->copy()->subWeek()->startOfWeek(\Carbon\CarbonInterface::MONDAY)),
                               $d($today->copy()->subWeek()->endOfWeek(\Carbon\CarbonInterface::SUNDAY))],
            'this_month'   => [$d($today->copy()->startOfMonth()), $d($today->copy()->endOfMonth())],
            // subMonthNoOverflow: em 31/03 o "mês passado" é fevereiro, não março.
            'last_month'   => [$d($today->copy()->subMonthNoOverflow()->startOfMonth()),
                               $d($today->copy()->subMonthNoOverflow()->endOfMonth())],
            'this_year'    => [$d($today->copy()->startOfYear()), $d($today->copy()->endOfYear())],
            'last_year'    => [$d($today->copy()->subYear()->startOfYear()), $d($today->copy()->subYear()->endOfYear())],
            'last_7_days'  => [$d($today->copy()->subDays(6)), $d($today)],
            'last_30_days' => [$d($today->copy()->subDays(29)), $d($today)],
            'last_90_days' => [$d($today->copy()->subDays(89)), $d($today)],
            'next_7_days'  => [$d($today), $d($today->copy()->addDays(6))],
            'next_30_days' => [$d($today), $d($today->copy()->addDays(29))],
        };
    }

    /**
     * Operador escolhido pelo usuário (filter-op-select) contra a allowlist,
     * caindo no default declarado da coluna quando inválido.
     */
    protected static function _sanitizeOp(string $op, string $fallback): string
    {
        return static::_canonicalOp($op) ?: $fallback;
    }

    /**
     * Valor de filtro "vazio" (nada a aplicar).
     *
     * NÃO use `empty()`: `empty('0')` é true, e um filtro booleano com valor
     * '0' (Não) sumia em silêncio. Range é vazio só quando os dois extremos são.
     */
    protected static function _filterValueIsEmpty(mixed $v): bool
    {
        if ($v === null || $v === '' || $v === []) {
            return true;
        }
        if (is_array($v)) {
            foreach ($v as $item) {
                if (! static::_filterValueIsEmpty($item)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Lista de escalares para IN/NOT IN — descarta aninhamento e aplica o cap.
     * @return array<int, scalar>
     */
    protected static function _scalarList(mixed $v): array
    {
        $items = is_array($v) ? $v : [$v];
        $out   = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;   // array aninhado / objeto: bind inválido
            }
            if ($item === '' || $item === null) {
                continue;
            }
            $out[] = $item;
            if (count($out) >= static::FILTER_IN_CAP) {
                @error_log('[MadDataGrid] lista de filtro truncada em '
                    . static::FILTER_IN_CAP . ' itens — resultado pode estar incompleto.');
                break;
            }
        }
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * Extremos de um range. Aceita ['a','b'], ['from'=>..,'to'=>..] e escalar
     * (vira início aberto). Extremo ausente vira null — o chamador degrada para
     * `>=` / `<=` em vez de descartar o filtro inteiro.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected static function _rangeParts(mixed $v): array
    {
        if (! is_array($v)) {
            $s = (string) $v;
            return [$s === '' ? null : $s, null];
        }
        $from = $v['from'] ?? $v['min'] ?? $v['start'] ?? $v[0] ?? null;
        $to   = $v['to']   ?? $v['max'] ?? $v['end']   ?? $v[1] ?? null;

        $norm = static fn($x) => (is_scalar($x) && (string) $x !== '') ? (string) $x : null;
        return [$norm($from), $norm($to)];
    }

    /**
     * Emite o predicado de UM operador. Ponto único: `_applyColWhere` (coluna
     * direta e whereHas de chain) e `_relMatchingIds` (cross-conexão) passam
     * todos por aqui, então um operador novo funciona nos três caminhos.
     *
     * `like`/`not like` vão por `whereLike()`/`whereNotLike()`, que compila pelo
     * driver da conexão DO BUILDER: `ilike` no Postgres, `like` nos demais. É o
     * builder e não o grid que decide porque no cross-conexão (`_relMatchingIds`)
     * o relacionado pode estar em outro motor.
     *
     * `$op` JÁ deve ter passado por `_canonicalOp`.
     */
    protected static function _whereOp($q, string $field, string $op, mixed $value): void
    {
        switch ($op) {
            case 'like':
                $q->whereLike($field, '%' . $value . '%');
                break;

            case 'not like':
                $q->whereNotLike($field, '%' . $value . '%');
                break;

            case 'starts':
                if (! is_scalar($value)) return;
                $q->whereLike($field, $value . '%');
                break;

            case 'ends':
                if (! is_scalar($value)) return;
                $q->whereLike($field, '%' . $value);
                break;

            case 'empty':
                // Vazio = NULL OU '' — agrupado, senão o OR vazaria pro resto
                // do WHERE. Só é oferecido a texto: `int_col = ''` estoura no
                // Postgres.
                $q->where(function ($g) use ($field) {
                    $g->whereNull($field)->orWhere($field, '=', '');
                });
                break;

            case 'not empty':
                $q->whereNotNull($field)->where($field, '<>', '');
                break;

            case 'in':
            case 'not in':
                $vals = static::_scalarList($value);
                if ($vals === []) {
                    // IN vazio é intenção explícita ("nenhum destes"), não erro
                    // de config: nada casa. NOT IN vazio não restringe.
                    $q->whereRaw($op === 'in' ? '1 = 0' : '1 = 1');
                    break;
                }
                $op === 'in' ? $q->whereIn($field, $vals) : $q->whereNotIn($field, $vals);
                break;

            case 'between':
                [$a, $b] = static::_rangeParts($value);
                if ($a !== null && $b !== null)  $q->whereBetween($field, [$a, $b]);
                elseif ($a !== null)             $q->where($field, '>=', $a);
                elseif ($b !== null)             $q->where($field, '<=', $b);
                break;

            case 'date':
                $q->whereDate($field, '=', (string) $value);
                break;

            case 'date between':
                // Fronteiras de dia em vez de whereDate duplo: whereDate sobre
                // coluna datetime mata o índice em MySQL/Postgres.
                [$a, $b] = static::_rangeParts($value);
                static::_whereDayRange($q, $field, $a, $b);
                break;

            case 'date preset':
                // Período nomeado resolvido AGORA (não na gravação): um filtro
                // salvo "Este mês" continua valendo no mês seguinte. Mesmas
                // fronteiras de dia do `date between`. Id fora da allowlist →
                // não filtra.
                $range = is_string($value) ? static::datePresetRange($value) : null;
                if ($range === null) return;
                static::_whereDayRange($q, $field, $range[0], $range[1]);
                break;

            case 'is null':
                $q->whereNull($field);
                break;

            case 'is not null':
                $q->whereNotNull($field);
                break;

            default:
                // Comparador simples, já validado contra FILTER_OPS.
                if (! is_scalar($value)) {
                    return;
                }
                $q->where($field, $op, $value);
                break;
        }
    }

    /**
     * Intervalo de DIAS, inclusivo nas duas pontas — `date between` e `date
     * preset`. Ponta ausente degrada para `>=` / `<=`.
     *
     * O início vai como o dia puro ('2026-01-01'), nunca com '00:00:00': o
     * SQLite guarda DATE como texto e compara como texto, e '2026-01-01' é
     * MENOR que '2026-01-01 00:00:00' — "a partir de 01/01" deixava o próprio
     * dia 01/01 de fora. Em MySQL/Postgres o dia puro já é a meia-noite, então
     * lá nada muda. O fim fecha em 23:59:59: pega o dia inteiro numa coluna
     * DATETIME e, numa DATE, '2026-01-31' continua <= '2026-01-31 23:59:59'.
     * Mesma convenção do `_cfApplyRule` e dos presets do dashboard.
     */
    protected static function _whereDayRange($q, string $field, ?string $from, ?string $to): void
    {
        $to = $to !== null ? $to . ' 23:59:59' : null;

        if ($from !== null && $to !== null)  $q->whereBetween($field, [$from, $to]);
        elseif ($from !== null)              $q->where($field, '>=', $from);
        elseif ($to !== null)                $q->where($field, '<=', $to);
    }

    /** Model do builder (null quando é Query Builder puro — ex.: MadSeekGrid). */
    protected static function _gridModel($q)
    {
        return $q instanceof \Illuminate\Database\Eloquent\Builder ? $q->getModel() : null;
    }

    /**
     * Field simples embrulhado pelo IR do editor ('{nome}') → 'nome'. O editor
     * persiste refs rename-safe e o deploy resolve o id mantendo as chaves, então
     * o runtime recebe `field="{nome}"` — que como identificador SQL não existe
     * (vira json_extract / é barrado pelo OrderGuard). Chains ('{a->b}') NÃO são
     * desembrulhados aqui: quem trata é _chainParts.
     */
    protected static function _unbrace(string $field): string
    {
        if (strlen($field) > 2 && $field[0] === '{' && substr($field, -1) === '}') {
            $inner = substr($field, 1, -1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $inner)) {
                return $inner;
            }
        }
        return $field;
    }

    /**
     * Chain rename-safe de N níveis: '{a->b}' | 'a->b->c' → ['a','b','c'].
     * O ÚLTIMO segmento é a coluna; os anteriores são relações.
     *
     * Quem só sabe traduzir 1 nível (busca global) checa `count($parts) === 2`
     * e cai no próprio fail-closed — emitir SQL para um caminho que não se sabe
     * percorrer filtraria pela relação errada. O filtro de coluna percorre até
     * 3 saltos (ver _applyColWhere).
     */
    protected static function _chainParts(string $field): ?array
    {
        $f = trim($field);
        if (strlen($f) > 2 && $f[0] === '{' && substr($f, -1) === '}') {
            $f = substr($f, 1, -1);
        }
        $ident = '[A-Za-z_][A-Za-z0-9_]*';
        if (preg_match('/^' . $ident . '(?:->' . $ident . ')+$/', $f)) {
            return explode('->', $f);
        }
        return null;
    }

    /**
     * Metadados do belongsTo `$rel`: ['class','fk','ownerKey','same'].
     * `same` = relacionado roda na MESMA conexão do model do grid. Quando é outra
     * (db-fk pra IAM, p.ex.), JOIN/whereHas/subselect NÃO atravessam o banco — a
     * única via é materializar os ids na conexão do relacionado.
     *
     * @param mixed $q Builder do grid; null = usa $this->model.
     */
    protected function _relMeta(string $rel, $q = null): ?array
    {
        $model = null;
        if ($q !== null) {
            $model = static::_gridModel($q);
            if ($model === null) return null;
            $cls = get_class($model);
        } else {
            $cls = $this->model ?: null;
            if ($cls === null || ! class_exists($cls)) return null;
        }

        $key = $cls . '::' . $rel;
        if (array_key_exists($key, self::$_relMetaCache)) {
            return self::$_relMetaCache[$key];
        }

        $meta = null;
        try {
            $model = $model ?? new $cls();
            // Ponte snake↔camel (`categoria_produto` → `categoriaProduto()`),
            // a mesma do _relMetaOn: sem ela o segmento do editor em snake não
            // achava a relação camelCase e o filtro caía no fallback de tabela.
            $name = static::_relMethodName($model, $rel);
            if ($name !== null) {
                $relation = $model->$name();
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                    $related = $relation->getRelated();
                    $meta = [
                        'class'    => get_class($related),
                        'fk'       => $relation->getForeignKeyName(),
                        'ownerKey' => $relation->getOwnerKeyName(),
                        'same'     => $related->getConnectionName() === $model->getConnectionName(),
                        'method'   => $name,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $meta = null;
        }

        return self::$_relMetaCache[$key] = $meta;
    }

    /**
     * Nome REAL do método de relação para o segmento `$rel`: o próprio nome
     * quando o model o declara (comportamento de sempre — vence), senão a
     * variante camel/snake que for relação Eloquent. null = nenhum método.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected static function _relMethodName($model, string $rel): ?string
    {
        if ($rel === '') return null;
        if (method_exists($model, $rel)) return $rel;
        $resolved = GridRenderHelpers::relationOn(get_class($model), $rel);

        return $resolved[0] ?? null;
    }

    /**
     * Caminho `a.b.c` (nomes REAIS de método) para whereHas aninhado, a partir
     * dos segmentos de relação de uma chain `{a->b->c->col}`.
     *
     * Todo salto precisa ser relação Eloquent declarada E viver na MESMA
     * conexão do model do grid: whereHas vira subconsulta no mesmo SQL e não
     * atravessa banco. Cross-conexão só é suportada em 1 nível (ids
     * materializados por _relMatchingIds); mais fundo → null (fail-closed).
     *
     * @param string[] $rels segmentos de relação (sem a coluna final)
     */
    protected function _relChainPath($q, array $rels): ?string
    {
        $model = static::_gridModel($q);
        if ($model === null || $rels === []) {
            return null;
        }

        $conn   = $model->getConnectionName();
        $cursor = $model;
        $names  = [];
        try {
            foreach ($rels as $seg) {
                $name = static::_relMethodName($cursor, (string) $seg);
                if ($name === null) return null;
                $relation = $cursor->$name();
                if (! $relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) return null;
                $related = $relation->getRelated();
                if ($related->getConnectionName() !== $conn) return null;
                $names[] = $name;
                $cursor  = $related;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return implode('.', $names);
    }

    /** @var array<string, array|null> cache do _relMetaOn ("Fqcn::segmento"). */
    private static array $_relMetaOnCache = [];

    /**
     * Metadados de UM salto belongsTo a partir de uma classe QUALQUER — o que
     * permite percorrer `a->b->c` sem que o segundo salto precise sair do model
     * do grid.
     *
     * O nome do segmento passa pela ponte snake↔camel (`categoria_produto` →
     * `categoriaProduto()`), a mesma do `resolveRelationFallback`. O `_relMeta`
     * (filtro e busca global) usa a mesma ponte via `_relMethodName`, e emite
     * o whereHas com o nome RESOLVIDO.
     *
     * @param \Illuminate\Database\Eloquent\Model|null $ownerInstance instância real
     *        do dono (preserva conexão trocada em runtime); null = instancia nova.
     */
    protected function _relMetaOn(string $ownerClass, string $rel, $ownerInstance = null): ?array
    {
        $key = $ownerClass . '::' . $rel;
        if (array_key_exists($key, self::$_relMetaOnCache) && $ownerInstance === null) {
            return self::$_relMetaOnCache[$key];
        }

        $meta = null;
        try {
            $resolved = GridRenderHelpers::relationOn($ownerClass, $rel);
            if ($resolved !== null) {
                $model    = $ownerInstance ?? new $ownerClass();
                $relation = $model->{$resolved[0]}();
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                    $related = $relation->getRelated();
                    $meta = [
                        'class'    => get_class($related),
                        'fk'       => $relation->getForeignKeyName(),
                        'ownerKey' => $relation->getOwnerKeyName(),
                        'same'     => $related->getConnectionName() === $model->getConnectionName(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $meta = null;
        }

        return self::$_relMetaOnCache[$key] = $meta;
    }

    /**
     * Ids do model relacionado que casam com o predicado — caminho cross-conexão.
     * @return array<int, mixed>
     */
    protected function _relMatchingIds(array $meta, string $col, string $op, mixed $value): array
    {
        try {
            $cls = $meta['class'];
            $sub = $cls::query();
            static::_whereOp($sub, $col, $op, $value);

            $ids = $sub->limit(static::REL_ID_CAP + 1)->pluck($meta['ownerKey'])->all();
            if (count($ids) > static::REL_ID_CAP) {
                array_pop($ids);
                @error_log('[MadDataGrid] filtro cross-conexão truncado em ' . static::REL_ID_CAP
                    . ' ids (' . $cls . '.' . $col . ') — resultado pode estar incompleto.');
            }
            return $ids;
        } catch (\Throwable $e) {
            @error_log('[MadDataGrid] filtro cross-conexão falhou (' . ($meta['class'] ?? '?')
                . '.' . $col . '): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Aplica um WHERE de filtro de coluna tolerando field em forma de chain
     * rename-safe ('{cliente->nome}' / 'cliente->nome'). Sem tratamento, o
     * Eloquent interpreta '->' como JSON path e as chaves viram SQL malformado
     * (json_extract("{cliente", '$."nome}"')). Chain de 1 nível vira whereHas
     * (mesma conexão) ou whereIn de ids materializados (conexões distintas);
     * de 2 a 3 saltos vira whereHas aninhado ('a.b.c'), só na mesma conexão.
     * Caminho que não se sabe percorrer, mais de 3 saltos ou field
     * não-identificador é fail-closed (não filtra, não quebra a listagem).
     */
    protected function _applyColWhere($q, string $field, string $op, mixed $value): void
    {
        // Operador desconhecido é fail-closed: sem isso o where() do Laravel o
        // rebaixa a VALOR (where($f, '=', 'between')) e a listagem filtra errado
        // sem sinal nenhum.
        $canonical = static::_canonicalOp($op ?: 'like');
        if ($canonical === '') {
            @error_log('[MadDataGrid] operador de filtro rejeitado: ' . $op
                . ' (campo ' . $field . ') — filtro ignorado.');
            return;
        }
        $op = $canonical;

        // Chain rename-safe de 1 nível: '{rel->col}' ou 'rel->col'. Caminho mais
        // profundo segue para o whereHas aninhado logo abaixo.
        if (($chain = static::_chainParts($field)) !== null && count($chain) === 2) {
            [$rel, $col] = $chain;

            $meta = $this->_relMeta($rel, $q);

            // Relacionado em OUTRA conexão: whereHas/subselect não atravessam o
            // banco — resolve os ids lá e filtra pela FK local.
            if ($meta !== null && ! $meta['same']) {
                $ids = $this->_relMatchingIds($meta, $col, $op, $value);
                if (empty($ids)) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn($meta['fk'], $ids);
                }
                return;
            }

            $where = function ($sub) use ($col, $op, $value) {
                static::_whereOp($sub, $col, $op, $value);
            };

            // Preferência: relação Eloquent real — resolve tabela/FK verdadeiras
            // (ex.: relação `cliente` apontando pra tabela `pessoa`). O nome
            // passa pela ponte snake↔camel (`categoria_produto` → método
            // `categoriaProduto()`).
            $model   = static::_gridModel($q);
            $relName = $model !== null ? static::_relMethodName($model, $rel) : null;
            if ($relName !== null) {
                try {
                    $q->whereHas($relName, $where);
                    return;
                } catch (\Throwable $e) {
                    // método existe mas não é relação → cai no fallback
                }
            }

            // Fallback (Query Builder / relação ausente): convenção fk = tabela_id.
            $q->whereIn($rel . '_id', function ($sub) use ($rel, $where) {
                $sub->select('id')->from($rel);
                $where($sub);
            });
            return;
        }

        // Chain de 2 a 3 saltos ('{cidade->estado->nome}'): whereHas aninhado
        // ('cidade.estado'), desde que CADA salto seja relação declarada na
        // mesma conexão do grid. Qualquer outra coisa (salto desconhecido,
        // relação em outro banco, mais de 3 saltos) é fail-closed: o filtro
        // é ignorado em vez de filtrar pela relação errada.
        if ($chain !== null) {
            if (count($chain) > 4) {
                return;
            }
            $col  = array_pop($chain);
            $path = $this->_relChainPath($q, $chain);
            if ($path === null) {
                static::_warnOnce(
                    'filter-chain:' . $field,
                    'filtro por "' . $field . '" ignorado: caminho de relação desconhecido '
                    . 'ou atravessando conexão (só 1 nível cruza banco).'
                );
                return;
            }
            try {
                $q->whereHas($path, function ($sub) use ($col, $op, $value) {
                    static::_whereOp($sub, $col, $op, $value);
                });
            } catch (\Throwable $e) {
                @error_log('[MadDataGrid] filtro por ' . $field . ' falhou: ' . $e->getMessage());
            }
            return;
        }

        // Ref do editor ('{nome}') → coluna real; sem isso o filtro era descartado.
        $field = static::_unbrace($field);

        // Chain profundo / field com metacaracteres: não dá pra traduzir com
        // segurança — ignora o filtro em vez de emitir SQL inválido.
        if (str_contains($field, '->') || str_contains($field, '{') || str_contains($field, '}')) {
            return;
        }

        static::_whereOp($q, $field, $op, $value);
    }

    /**
     * Resolve um caminho de propriedades aninhadas em um objeto.
     * Ex: 'produto->tipo_produto->percentual' → $obj->produto->tipo_produto->percentual
     */
    protected static function _resolveObjectPath($obj, string $path): mixed
    {
        $parts   = explode('->', $path);
        $current = $obj;

        foreach ($parts as $part) {
            $part = trim($part);
            if (is_object($current)) {
                try {
                    $current = $current->$part ?? null;
                } catch (\Throwable $e) {
                    return null;
                }
            } elseif (is_array($current)) {
                $current = $current[$part] ?? null;
            } else {
                return null;
            }
            if ($current === null) return null;
        }

        return $current;
    }

    protected function _db(): string
    {
        if (!empty($this->database)) return $this->database;
        return defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business';
    }

    // ── ManageRow — renderiza uma <tr> individual para update/insert ──

    /**
     * Renderiza o HTML de uma única <tr> para uso em manageRow.
     *
     * Instancia o grid, restaura a config inline da sessão (para grids
     * que usam <mad-grid self>), carrega o registro e gera a <tr> em PHP.
     *
     * @param string     $gridClass  Classe do grid (ex: UnitList::class)
     * @param int|string $id         ID do registro
     * @return string                HTML da <tr>
     */
    public static function renderSingleRow(string $gridClass, int|string $id): string
    {
        // Classe inexistente aqui = fatal seco no meio de um save que JÁ gravou
        // (o manageRow é a última op da action). O erro nativo não diz o que
        // fazer; este diz — e o autoload já tentou resolver pelo basename antes
        // de chegar aqui (ver ControlNamespaceFallback).
        if (! class_exists($gridClass)) {
            throw new \RuntimeException(sprintf(
                'manageRow: grid "%s" nao existe. O identificador de um control e o BASENAME '
                .'(ex.: %s), nao o FQCN: confira se o `use App\\Control\\...` no topo do arquivo '
                .'nao inventou um modulo — tela sem modulo fica em app/control/ (classe global).',
                $gridClass,
                \Mad\Registry\ControlRegistry::idFor($gridClass)
            ));
        }

        /** @var MadDataGrid $grid */
        $grid = new $gridClass();

        // Restaura config inline da sessão (grids com <mad-grid self>)
        $cfg = session($gridClass . '_dg_cfg');
        if ($cfg) {
            $grid->_inlineConfig = $cfg;
            if (!empty($cfg['model']))      $grid->model      = $cfg['model'];
            if (!empty($cfg['database']))   $grid->database   = $cfg['database'];
            if (!empty($cfg['actionSide'])) $grid->actionSide = $cfg['actionSide'];
        }

        $columns      = $grid->_effectiveColumns();
        $actions      = $grid->_effectiveActions();
        $actionGroups = $grid->_effectiveActionGroups();
        $grid->_detectRenderFields($columns);

        if (empty($columns)) {
            // Sem colunas a <tr> sai só com a célula de ações: "linha em branco".
            // Acontece quando o cache inline da sessão (<grid>_dg_cfg) não existe
            // para ESTA classe — ex.: manageRow(id, Outra::class) de um grid que
            // nunca renderizou nesta sessão, ou sessão trocada/expirada.
            \Illuminate\Support\Facades\Log::warning('manageRow: grid sem colunas — a linha sairia em branco', [
                'grid' => $gridClass, 'id' => $id, 'inlineCfgInSession' => $cfg !== null && $cfg !== [],
            ]);
        }

        // Carrega o registro
        $modelClass = $grid->model;
        // Eloquent: construtor recebe array de atributos — carrega por id via find().
        // Objeto sem Eloquent: mantém o idioma `new $modelClass($id)`.
        $record = is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)
            ? $modelClass::find($id)
            : new $modelClass($id);

        if ($record === null) {
            // Registro invisível para find(): id errado, global scope (tenant/
            // unidade) ou conexão. Devolver '' fazia o mad.js trocar a <tr> por
            // nada — a linha SUMIA em silêncio (o mad.js agora mantém a linha).
            \Illuminate\Support\Facades\Log::warning('manageRow: registro nao encontrado', [
                'grid' => $gridClass, 'model' => $modelClass, 'id' => $id,
            ]);

            return '';
        }

        // Decoração da linha — o gancho que devolve ao `manageRow` as colunas
        // que só existem em PHP (ver `decorateRow()`).
        $decorated = $grid->decorateRow($record);
        // O hook normalmente devolve o PRÓPRIO registro (decorado in-place): só
        // o objeto volta para `$record`, porque o resto do método navega
        // relações por ele (`render()`, `evaluate`, `getKey()`). Um hook que
        // devolva array de atributos tem a palavra final sobre o que veio do
        // banco — é o que ele acabou de calcular —, mas não substitui o
        // registro.
        if (is_object($decorated)) {
            $record = $decorated;
        }

        $row = $record->toArray();
        if (is_array($decorated)) {
            $row = $decorated + $row;
        }

        // Resolve campos de relacionamento {relacao->campo}
        if (!empty($grid->_renderFields)) {
            $hasRender = method_exists($record, 'render');
            foreach ($grid->_renderFields as $field => $pattern) {
                try {
                    // render() quando o objeto expõe; Eloquent: traversa {relacao->campo} (lazy-load).
                    $row[$field] = $hasRender
                        ? $record->render($pattern)
                        : GridRenderHelpers::resolveTemplate($pattern, $record, $row);
                } catch (\Throwable $e) { $row[$field] = ''; }
            }
        }

        // Resolve evaluate columns
        foreach ($columns as $col) {
            if ($col->evaluate !== '') {
                $row[$col->field] = static::_computeEvaluate($col->evaluate, $record, $row);
            }
        }

        // Mantem ref ao registro para callables de when/disabled/transform navegarem relacoes
        $row['__record'] = $record;

        // row-detail: a 2ª linha descritiva precisa ser recalculada aqui — o
        // manage_row não passa pelo loadData(), que é onde ela é materializada.
        // A máscara vem do config inline da sessão (prop protegida não viaja).
        $detailMask = (string) ($cfg['rowDetail'] ?? '');
        if ($detailMask !== '') {
            $row['__detail'] = static::_renderMask($detailMask, $row);
        }

        // NOTA: display-condition e transform callbacks acessam relacoes via
        // lazy-load do Eloquent — sem necessidade de transacao aberta.

        $visibleColumns = array_values(array_filter($columns, fn($c) => !$c->hidden && $c->checkDisplay()));
        $hasActions     = !empty($actions) || !empty($actionGroups);
        // getKey() primeiro: a linha so tem coluna `id` quando a PK se chama
        // assim. Sem isto, `removeRow`/`data-row-id` erravam o alvo.
        $rowId          = (is_object($record) && method_exists($record, 'getKey') ? $record->getKey() : null)
                          ?? $row['id'] ?? $id;
        $_rowPrefix     = $gridClass . '_';

        // Reconstroi caches editComboOptions / editSearchToken / editSearchPreloaded
        // para que dbcombo/dbunique-search rendam labels (nao ID cru) e tenham
        // MAD Select funcional na linha re-renderizada.
        [$editComboOptions, $editSearchToken, $editSearchPreloaded]
            = static::_buildEditCaches($visibleColumns, [$row]);

        $rowHtml = static::_buildRowHtml(
            $row, $rowId, $_rowPrefix,
            $visibleColumns, $actions, $actionGroups, $hasActions, $grid->actionSide,
            $editComboOptions, $editSearchToken, $editSearchPreloaded,
            // Linha nova num grid com seleção ganha a célula do checkbox —
            // sem ela a <tr> entraria com uma coluna a menos.
            !empty($cfg['selectable']) || $grid->selectable
        );

        // Se card view está habilitado, gera card HTML também
        $cardHtml = '';
        if (!empty($cfg['cardView'])) {
            $cardHtml = static::_buildCardHtml($row, $rowId, $_rowPrefix, $visibleColumns, $actions, $actionGroups,
                !empty($cfg['selectable']) || $grid->selectable);
        }

        return $cardHtml ? $rowHtml . '<!-- CARD_HTML -->' . $cardHtml : $rowHtml;
    }

    /**
     * Auto-atribui card roles para colunas sem role explícito.
     */
    protected static function _assignCardRoles(array $visibleColumns): array
    {
        $hasExplicit = false;
        foreach ($visibleColumns as $col) {
            if ($col->cardRole !== '') { $hasExplicit = true; break; }
        }

        $cardTitle = $cardSubtitle = $cardBadge = $cardImage = $cardHighlight = null;
        $cardBody = [];

        foreach ($visibleColumns as $col) {
            $role = $col->cardRole;
            if (!$hasExplicit) {
                if (!$cardTitle && !$col->isBadge && !$col->isMoney && !$col->isDate && $col->field !== 'id') {
                    $role = 'title';
                } elseif ($col->isBadge && !$cardBadge) {
                    $role = 'badge';
                } elseif ($col->isMoney && !$cardHighlight) {
                    $role = 'highlight';
                } elseif ($col->isDate && !$cardSubtitle) {
                    $role = 'subtitle';
                }
            }
            match ($role) {
                'title'     => $cardTitle     = $col,
                'subtitle'  => $cardSubtitle  = $col,
                'badge'     => $cardBadge     = $col,
                'image'     => $cardImage     = $col,
                'highlight' => $cardHighlight = $col,
                default     => $cardBody[]    = $col,
            };
        }

        return compact('cardTitle', 'cardSubtitle', 'cardBadge', 'cardImage', 'cardHighlight', 'cardBody');
    }

    /**
     * Gera o HTML de um card do data-grid em PHP puro.
     */
    protected static function _buildCardHtml(
        array $row,
        int|string $rowId,
        string $_rowPrefix,
        array $visibleColumns,
        array $actions,
        array $actionGroups,
        bool $selectable = false
    ): string {
        $rid   = htmlspecialchars($_rowPrefix . $rowId, ENT_QUOTES);
        $roles = static::_assignCardRoles($visibleColumns);
        extract($roles); // cardTitle, cardSubtitle, cardBadge, cardImage, cardHighlight, cardBody

        $html = '<div class="mad-dg-card" data-card-id="' . $rid . '">';
        // <mad-grid selectable>: mesmo checkbox do card do primeiro render.
        if ($selectable) {
            $sid   = htmlspecialchars(json_encode((string) $rowId, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            $html .= '<label class="mad-dg-card-select" @click.stop><input type="checkbox" class="mad-dg-select"'
                   . ' aria-label="' . htmlspecialchars((string) __('grid.select_row'), ENT_QUOTES) . '"'
                   . ' :checked="isSelected(' . $sid . ')" @change="toggleRow(' . $sid . ', $event.target.checked)"></label>';
        }

        // Image
        if ($cardImage) {
            $imgVal = $row[$cardImage->field] ?? '';
            if ($imgVal) {
                $html .= '<div class="mad-dg-card-image"><img src="' . htmlspecialchars($imgVal, ENT_QUOTES) . '" alt="" loading="lazy" /></div>';
            }
        }

        $html .= '<div class="mad-dg-card-body">';

        // Header: title + badge
        $html .= '<div class="mad-dg-card-header">';
        $html .= '<div class="mad-dg-card-titles">';
        if ($cardTitle) {
            $html .= '<span class="mad-dg-card-title">' . $cardTitle->renderValue($row[$cardTitle->field] ?? '', $row) . '</span>';
        }
        if ($cardSubtitle) {
            $html .= '<span class="mad-dg-card-subtitle">' . $cardSubtitle->renderValue($row[$cardSubtitle->field] ?? '', $row) . '</span>';
        }
        $html .= '</div>';
        if ($cardBadge) {
            $html .= $cardBadge->renderValue($row[$cardBadge->field] ?? '', $row);
        }
        $html .= '</div>';

        // Highlight
        if ($cardHighlight) {
            $html .= '<div class="mad-dg-card-highlight">' . $cardHighlight->renderValue($row[$cardHighlight->field] ?? '', $row) . '</div>';
        }

        // Body fields
        if (!empty($cardBody)) {
            $html .= '<div class="mad-dg-card-fields">';
            foreach ($cardBody as $col) {
                $cellVal = $row[$col->field] ?? '';
                if ($cellVal === '' || $cellVal === null) continue;
                $html .= '<div class="mad-dg-card-field"'
                        . " :class=\"{ 'mad-dg-col-hidden': isColHidden('" . $col->fieldKey . "') }\">"
                        . '<span class="mad-dg-card-field-label">' . htmlspecialchars($col->label, ENT_QUOTES) . '</span>'
                        . '<span class="mad-dg-card-field-value">' . $col->renderValue($cellVal, $row) . '</span>'
                        . '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // card-body

        // Footer: actions
        $hasActions = !empty($actions) || !empty($actionGroups);
        if ($hasActions) {
            $html .= '<div class="mad-dg-card-footer"><div class="mad-dg-card-actions">';
            $html .= static::_buildCardActionsHtml($row, $rowId, $actions, $actionGroups);
            $html .= '</div></div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Gera o HTML dos botões de ação de um card.
     */
    protected static function _buildCardActionsHtml(
        array $row,
        int|string $rowId,
        array $actions,
        array $actionGroups
    ): string {
        $html = '';

        foreach ($actions as $act) {
            if (!$act->isVisible($row)) continue;
            $aId = $row[$act->idField] ?? $rowId;
            $ep  = !empty($act->params) ? ', ' . json_encode(array_values($act->params)) : '';
            $act = $act->getTransformed($row);
            $dis = $act->isDisabled($row) ? ' disabled' : '';
            $ico = $act->icon
                ? '<i data-lucide="' . htmlspecialchars($act->icon, ENT_QUOTES) . '" style="width:14px;height:14px;"></i>'
                : '';
            $lbl = '<span>' . htmlspecialchars($act->label, ENT_QUOTES) . '</span>';
            $cls = 'mad-dg-card-action' . ($act->isDanger ? ' mad-dg-card-action-danger' : '');

            if ($act->isNav) {
                $html .= '<button type="button" class="' . $cls . '"' . $dis
                        . ' ' . $act->getNavAttr($aId, $row) . '>' . $ico . $lbl . '</button>';
            } elseif ($act->confirm || $act->confirmPopover) {
                $cMsg  = $act->confirmPopover ?: $act->confirm;
                $cType = $act->confirmPopover ? "'popover'" : 'null';
                $click = 'confirmAction(' . json_encode($cMsg)
                       . ", () => \$dispatch('mad-dg-call',{method:" . json_encode($act->method)
                       . ',params:[' . self::rowIdJs($aId) . $ep . "]}), \$event, " . $cType . ')';
                $html .= '<button type="button" class="' . $cls . '"' . $dis
                        . ' @click="' . htmlspecialchars($click, ENT_COMPAT) . '">'
                        . $ico . $lbl . '</button>';
            } else {
                $html .= '<button type="button" class="' . $cls . '"' . $dis
                        . ' mad:click="' . htmlspecialchars($act->method . '(' . self::rowIdJs($aId) . $ep . ')', ENT_COMPAT) . '">'
                        . $ico . $lbl . '</button>';
            }
        }

        foreach ($actionGroups as $grp) {
            $html .= '<div class="mad-dg-dropdown-wrap" x-data="{open:false}" style="position:relative;">';
            $html .= '<button type="button" class="mad-dg-card-action" @click.stop="open=!open"'
                    . ' title="' . htmlspecialchars($grp->label, ENT_QUOTES) . '">';
            if ($grp->icon) $html .= '<i data-lucide="' . htmlspecialchars($grp->icon, ENT_QUOTES) . '" style="width:14px;height:14px;"></i>';
            if ($grp->label) $html .= '<span>' . htmlspecialchars($grp->label, ENT_QUOTES) . '</span>';
            $html .= '</button>';
            $html .= '<div class="mad-dg-dropdown" x-show="open" x-cloak @click.outside="open=false" style="position:absolute;top:100%;right:0;margin-top:4px;z-index:50;">';

            foreach ($grp->actions as $act) {
                if (!$act->isVisible($row)) continue;
                $aId = $row[$act->idField] ?? $rowId;
                $ep  = !empty($act->params) ? ', ' . json_encode(array_values($act->params)) : '';
                $act = $act->getTransformed($row);
                $cls = 'mad-dg-dropdown-item' . ($act->isDanger ? ' mad-dg-dropdown-danger' : '');
                $dis = $act->isDisabled($row) ? ' disabled' : '';
                $ico = $act->icon
                    ? '<i data-lucide="' . htmlspecialchars($act->icon, ENT_QUOTES) . '" style="width:13px;height:13px;"></i>'
                    : '';

                if ($act->isNav) {
                    $html .= '<button type="button" class="' . $cls . '"' . $dis
                            . ' @click="open=false" ' . $act->getNavAttr($aId, $row) . '>'
                            . $ico . htmlspecialchars($act->label, ENT_QUOTES) . '</button>';
                } elseif ($act->confirm || $act->confirmPopover) {
                    $cMsg  = $act->confirmPopover ?: $act->confirm;
                    $cType = $act->confirmPopover ? "'popover'" : 'null';
                    $click = "open=false; confirmAction(" . json_encode($cMsg)
                           . ", () => \$dispatch('mad-dg-call',{method:" . json_encode($act->method)
                           . ',params:[' . self::rowIdJs($aId) . $ep . "]}), \$event, " . $cType . ')';
                    $html .= '<button type="button" class="' . $cls . '"' . $dis
                            . ' @click="' . htmlspecialchars($click, ENT_COMPAT) . '">'
                            . $ico . htmlspecialchars($act->label, ENT_QUOTES) . '</button>';
                } else {
                    $html .= '<button type="button" class="' . $cls . '"' . $dis
                            . ' @click="open=false" mad:click="' . htmlspecialchars($act->method . '(' . self::rowIdJs($aId) . $ep . ')', ENT_COMPAT) . '">'
                            . $ico . htmlspecialchars($act->label, ENT_QUOTES) . '</button>';
                }
            }

            $html .= '</div></div>';
        }

        return $html;
    }

    /**
     * Gera o HTML de uma <tr> do data-grid em PHP puro.
     * Replica a mesma estrutura que data-grid.blade.php gera (sem edição inline).
     */
    /**
     * Pre-carrega caches usados pelo render dos editores inline:
     *  - editComboOptions    [field => [id => label]] para edit-type=dbcombo
     *  - editSearchToken     [field => string] token AJAX assinado para dbunique-search
     *  - editSearchPreloaded [field => [id => label]] labels do registro selecionado
     *                        em colunas dbunique-search (batch lookup)
     *
     * Mesma logica do data-grid.blade.php — extraida pra ser reusada em renderSingleRow.
     *
     * @param GridColumn[] $visibleColumns
     * @param array<array> $rows  rows da pagina (usado para batch lookup do preloaded)
     * @return array{0: array, 1: array, 2: array}
     */
    public static function _buildEditCaches(array $visibleColumns, array $rows): array
    {
        $editComboOptions    = [];
        $editSearchToken     = [];
        $editSearchPreloaded = [];

        foreach ($visibleColumns as $col) {
            if (!$col->editable) continue;

            // dbcombo — carrega TODAS as options do model
            if ($col->editType === 'dbcombo' && !empty($col->editModel)) {
                try {
                    // F5: builder-native (:editFilters via applyArrayFilters direto no Query Builder).
                    $__m  = class_exists($col->editModel) ? $col->editModel : \Mad\Form\ModelOptionsLoader::resolveModelClass($col->editModel);
                    $__cq = $__m::query();
                    if (!empty($col->editFilters)) {
                        \Mad\Database\QuerySource::applyArrayFilters($__cq, $col->editFilters);
                    }
                    $editComboOptions[$col->field] = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                        $__cq,
                        $col->editKey,
                        $col->editDisplay,
                        $col->editOrderBy ?: null
                    );
                } catch (\Throwable $e) {
                    $editComboOptions[$col->field] = [];
                }
                continue;
            }

            // dbunique-search — token AJAX + batch label lookup dos IDs presentes nas rows
            if ($col->editType === 'dbunique-search' && !empty($col->editModel)) {
                try {
                    // F5: builder-native — token carrega query_sql/bindings compilados do Query Builder.
                    $__m  = class_exists($col->editModel) ? $col->editModel : \Mad\Form\ModelOptionsLoader::resolveModelClass($col->editModel);
                    $__cq = $__m::query();
                    if (!empty($col->editFilters)) {
                        \Mad\Database\QuerySource::applyArrayFilters($__cq, $col->editFilters);
                    }
                    [$__sql, $__binds] = \Mad\Database\QuerySource::compileSql($__cq);
                    $editSearchToken[$col->field] = \Mad\Http\MadStateCrypt::encrypt([
                        'database'       => static::_searchTokenDatabase($col->editDatabase, $__cq),
                        'model'          => $col->editModel,
                        'key'            => $col->editKey,
                        'display'        => $col->editDisplay,
                        'order'          => $col->editOrderBy,
                        'column'         => '',
                        'query_sql'      => $__sql,
                        'query_bindings' => $__binds,
                        'limit'          => 500,
                    ]);

                    // Batch lookup dos labels (sem N+1)
                    $ids = [];
                    foreach ($rows as $r) {
                        $v = $r[$col->field] ?? null;
                        if ($v !== null && $v !== '') $ids[(string)$v] = true;
                    }
                    if (!empty($ids)) {
                        $__lq = $__m::query()->whereIn($col->editKey, array_keys($ids));
                        $editSearchPreloaded[$col->field] = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                            $__lq,
                            $col->editKey,
                            $col->editDisplay,
                            null
                        ) ?: [];
                    } else {
                        $editSearchPreloaded[$col->field] = [];
                    }
                } catch (\Throwable $e) {
                    $editSearchToken[$col->field]     = '';
                    $editSearchPreloaded[$col->field] = [];
                }
            }
        }

        return [$editComboOptions, $editSearchToken, $editSearchPreloaded];
    }

    /**
     * Conexão do token de busca AJAX (filtro `dbsearch`, edição inline
     * `dbunique-search`): a declarada na coluna; sem ela, a do PRÓPRIO builder
     * — é contra ela que o `query_sql` do token foi compilado (a do model,
     * senão a default do app). O Studio omite `filter-database` quando é o
     * banco padrão; antes caía na constante MAIN_DATABASE, que o app gerado não
     * define — o Error ia pro catch, o token saía vazio e a busca respondia
     * "Nenhum registro encontrado" para qualquer termo.
     */
    protected static function _searchTokenDatabase(string $declared, $query): string
    {
        if ($declared !== '') {
            return $declared;
        }

        return \Mad\Database\QuerySource::connectionName($query) ?: (string) config('database.default');
    }

    /**
     * Pre-carrega os caches do render dos filtros TIPADOS. Gêmeo de
     * _buildEditCaches:
     *  - filterComboOptions [field => [id => label]]  dbcombo/multi com model
     *  - filterSearchToken  [field => string]         token AJAX do dbsearch
     *  - filterActiveLabels [field => [id => label]]  rótulo do chip ativo
     *
     * As options são memoizadas por configuração: sem isso a MESMA query roda a
     * cada render do grid. E o rótulo do chip sai de um whereIn batelado — o
     * caminho legado fazia um `find()` por chip, dentro do loop do Blade.
     *
     * O memo é POR REQUEST e por unidade/tenant (DataScope::memo). Era um
     * `static` da classe: num worker persistente (Octane) a lista carregada
     * pela query escopada da unidade A era servida à unidade B.
     *
     * @param GridColumn[] $visibleColumns
     * @param array        $colFilters  state dos filtros ativos
     * @return array{0: array, 1: array, 2: array}
     */
    public static function _buildFilterCaches(array $visibleColumns, array $colFilters): array
    {
        $filterComboOptions = [];
        $filterSearchToken  = [];
        $filterActiveLabels = [];

        foreach ($visibleColumns as $col) {
            if (! ($col instanceof GridColumn) || $col->filterKind === '') continue;

            $needsOptions = in_array($col->filterKind, ['dbcombo', 'multi'], true);
            $needsSearch  = $col->filterKind === 'dbsearch';
            if ((! $needsOptions && ! $needsSearch) || $col->filterModel === '') continue;

            try {
                $model = class_exists($col->filterModel)
                    ? $col->filterModel
                    : \Mad\Form\ModelOptionsLoader::resolveModelClass($col->filterModel);

                // `filter-database` declarado resolve a connection via Model::on()
                // (mesmo padrão do MadDbSearchService/MadGantt). Antes só o token
                // do dbsearch usava a database — options e labels saíam da default.
                $db = $col->filterDatabase;

                if ($needsOptions) {
                    // database + order na chave: duas colunas com o mesmo model e
                    // connection/direção diferentes NÃO podem colidir no memo.
                    $memoKey = md5(implode('|', [
                        $model, json_encode($col->filterFilters),
                        $col->filterDisplay, $col->filterOrderBy, $col->filterKey,
                        $db, $col->filterOrder,
                    ]));

                    $filterComboOptions[$col->field] = \Mad\Database\DataScope::memo('grid.filter_options', $memoKey, function () use ($model, $db, $col) {
                        $q = $db !== '' ? $model::on($db) : $model::query();
                        if (! empty($col->filterFilters)) {
                            \Mad\Database\QuerySource::applyArrayFilters($q, $col->filterFilters);
                        }

                        return \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                            $q,
                            $col->filterKey,
                            $col->filterDisplay ?: 'nome',
                            $col->filterOrderBy ?: null,
                            $col->filterOrder === 'desc' ? 'desc' : 'asc'
                        );
                    });
                }

                if ($needsSearch) {
                    // Mesmo formato do token de edição inline, de propósito: assim
                    // o MadDbSearchService::onSearch atende os dois sem alteração.
                    $q = $db !== '' ? $model::on($db) : $model::query();
                    if (! empty($col->filterFilters)) {
                        \Mad\Database\QuerySource::applyArrayFilters($q, $col->filterFilters);
                    }
                    [$sql, $binds] = \Mad\Database\QuerySource::compileSql($q);
                    $filterSearchToken[$col->field] = MadStateCrypt::encrypt([
                        'database'       => static::_searchTokenDatabase($db, $q),
                        'model'          => $col->filterModel,
                        'key'            => $col->filterKey,
                        'display'        => $col->filterDisplay ?: 'nome',
                        'order'          => $col->filterOrderBy,
                        'order_dir'      => $col->filterOrder ?: 'asc',
                        'column'         => '',
                        'query_sql'      => $sql,
                        'query_bindings' => $binds,
                        'limit'          => 500,
                    ]);
                }

                // Rótulo do(s) valor(es) ativo(s) — um whereIn por coluna.
                $active = $colFilters[$col->colFilterField ?: $col->field]['value'] ?? null;
                $ids    = array_values(array_filter(
                    is_array($active) ? $active : [$active],
                    fn($v) => is_scalar($v) && (string) $v !== ''
                ));
                if ($ids !== []) {
                    // dbcombo/multi já têm o mapa completo carregado.
                    if (! empty($filterComboOptions[$col->field])) {
                        $filterActiveLabels[$col->field] = $filterComboOptions[$col->field];
                    } else {
                        $filterActiveLabels[$col->field] = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                            ($db !== '' ? $model::on($db) : $model::query())->whereIn($col->filterKey, $ids),
                            $col->filterKey,
                            $col->filterDisplay ?: 'nome',
                            null
                        ) ?: [];
                    }
                }
            } catch (\Throwable $e) {
                @error_log('[MadDataGrid] falha ao carregar opcoes do filtro ('
                    . $col->filterModel . '.' . $col->field . '): ' . $e->getMessage());
                if ($needsOptions) $filterComboOptions[$col->field] = [];
                if ($needsSearch)  $filterSearchToken[$col->field]  = '';
            }
        }

        return [$filterComboOptions, $filterSearchToken, $filterActiveLabels];
    }

    /**
     * Rótulo legível de um filtro ativo, por tipo. Range vira "de X até Y" (ou
     * só um lado quando o outro está aberto), multi lista os primeiros com
     * "+N", bool vira Sim/Não.
     */
    public static function filterChipLabel(GridColumn $col, mixed $value, array $labels = []): string
    {
        $kind = $col->filterKind;

        if (str_ends_with($kind, '-range')) {
            [$a, $b] = static::_rangeParts($value);
            $a = $a !== null ? static::filterValueLabel($col, $a, $kind) : null;
            $b = $b !== null ? static::filterValueLabel($col, $b, $kind) : null;
            if ($a !== null && $b !== null) return __('grid.filter_range_between', ['from' => $a, 'to' => $b]);
            if ($a !== null)                return __('grid.filter_range_from',    ['from' => $a]);
            if ($b !== null)                return __('grid.filter_range_to',      ['to'   => $b]);
            return '';
        }

        if ($kind === 'multi') {
            $items = static::_scalarList($value);
            $names = array_map(fn($v) => (string) ($labels[$v] ?? $col->filterOpts[$v] ?? $v), $items);
            $head  = array_slice($names, 0, 3);
            $rest  = count($names) - count($head);
            return implode(', ', $head)
                . ($rest > 0 ? ' ' . __('grid.filter_selected_more', ['count' => $rest]) : '');
        }

        if ($kind === 'bool') {
            return (string) $value === $col->filterTrue ? __('grid.yes') : __('grid.no');
        }

        $scalar = is_array($value) ? reset($value) : $value;
        if ($kind === 'date' || $kind === 'number') {
            return static::filterValueLabel($col, is_scalar($scalar) ? (string) $scalar : '', $kind);
        }
        return (string) ($labels[$scalar] ?? $col->filterOpts[$scalar] ?? $scalar);
    }

    /**
     * Um valor de filtro de data/número como a COLUNA o mostra.
     *
     * O filtro guarda a data em `Y-m-d` e o número cru do input ("5000"); o
     * chip repetia os dois assim — "a partir de 2026-01-01", "até 5000" — ao
     * lado de uma grade em "01/01/2026" e "5.000,00".
     *
     *  - data: a máscara da coluna (`date="d/m/Y"`) quando ela é só de dia; o
     *    filtro é por DIA, então coluna com hora (ou sem `date`) usa o formato
     *    de data do idioma;
     *  - número: dinheiro/número da coluna (mesma formatação da célula), token
     *    built-in numérico do formatter, ou — sem formato — milhar e decimal do
     *    idioma com as casas que foram digitadas.
     *
     * Valor que não é data/número (estado antigo, digitação livre) sai como veio.
     *
     * @param string $kind date | date-range | number | number-range ('' = o da coluna)
     */
    public static function filterValueLabel(GridColumn $col, string $value, string $kind = ''): string
    {
        $kind = $kind !== '' ? $kind : $col->filterKind;
        $v    = trim($value);
        if ($v === '') {
            return '';
        }

        if ($kind === 'date' || $kind === 'date-range') {
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2})?)?$/', $v, $m)
                || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $value;
            }
            $day = new \DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]}");
            $fmt = ($col->isDate && $col->dateFormat !== '' && ! preg_match('/[aABgGhHisuveIOPpTZcrU]/', $col->dateFormat))
                ? $col->dateFormat
                : static::_localeDateFormat();

            return $day->format($fmt);
        }

        if ($kind === 'number' || $kind === 'number-range') {
            if (! is_numeric($v)) {
                return $value;
            }
            if ($col->isMoney) {
                return trim($col->moneyPrefix . ' ' . number_format((float) $v, 2, ',', '.'));
            }
            if ($col->isNumber) {
                return number_format((float) $v, $col->numberDecimals, ',', '.');
            }
            if (in_array($col->builtinFormat, [
                'money', 'currency-usd', 'currency-eur', 'currency-gbp', 'currency-jpy',
                'number', 'number-en', 'integer', 'percent', 'decimal-4',
            ], true)) {
                return \Mad\Support\ValueFormatter::apply($v, $col->builtinFormat);
            }

            return static::_localeNumber($v);
        }

        return $value;
    }

    /** Formato de data do idioma do app (pt-BR/es: d/m/Y). */
    protected static function _localeDateFormat(): string
    {
        $fmt = \Mad\I18n\MadLang::t('mad.tempo.date_format');

        return ($fmt === '' || $fmt === 'mad.tempo.date_format') ? 'd/m/Y' : $fmt;
    }

    /**
     * Número cru ("1234.5") com milhar e decimal do idioma ("1.234,5"; em
     * inglês "1,234.5"), mantendo as casas digitadas.
     */
    protected static function _localeNumber(string $v): string
    {
        $en       = str_starts_with(strtolower(\Mad\I18n\MadLang::getLocale()), 'en');
        $decimals = preg_match('/\.(\d+)$/', $v, $m) ? min(strlen($m[1]), 6) : 0;

        return number_format((float) $v, $decimals, $en ? '.' : ',', $en ? ',' : '.');
    }

    protected static function _buildRowHtml(
        array $row,
        int|string $rowId,
        string $_rowPrefix,
        array $visibleColumns,
        array $actions,
        array $actionGroups,
        bool $hasActions,
        string $actionSide,
        array $editComboOptions = [],
        array $editSearchToken = [],
        array $editSearchPreloaded = [],
        bool $selectable = false
    ): string {
        // Reusa o partial Blade da row (mesmo render do primeiro paint)
        // — assim o manage_row tem os mesmos editores inline / click / dblclick.
        try {
            return \Mad\View\MadBlade::render('components.data-grid-row', [
                'row'                 => $row,
                'rowId'               => $rowId,
                '_rowPrefix'          => $_rowPrefix,
                'visibleColumns'      => $visibleColumns,
                'actions'             => $actions,
                'actionGroups'        => $actionGroups,
                'hasActions'          => $hasActions,
                'actionSide'          => $actionSide,
                'editComboOptions'    => $editComboOptions,
                'editSearchToken'     => $editSearchToken,
                'editSearchPreloaded' => $editSearchPreloaded,
                'selectable'          => $selectable,
                'isEven'              => false,
                'rowDepth'            => 0,
                '_lastRow'            => null,
            ]);
        } catch (\Throwable $e) {
            // Fallback minimo — texto cru sem editores. Nao deveria acontecer em
            // producao, e marca claramente o erro pra investigar.
            $rid  = htmlspecialchars($_rowPrefix . $rowId, ENT_QUOTES);
            $html = '<tr class="mad-dg-row" data-row-id="' . $rid . '">';
            foreach ($visibleColumns as $col) {
                $cellVal  = $row[$col->field] ?? '';
                $rendered = $col->renderValue($cellVal, $row);
                $html .= '<td class="mad-dg-cell" style="text-align:' . $col->align . ';">' . $rendered . '</td>';
            }
            $html .= '</tr>';
            return $html;
        }
    }

    /**
     * Gera o HTML da célula de ações de uma row.
     */
    protected static function _buildActionsCell(
        array $row,
        int|string $rowId,
        array $actions,
        array $actionGroups
    ): string {
        $html = '<td class="mad-dg-cell mad-dg-actions-cell"><div class="mad-dg-actions">';

        foreach ($actions as $act) {
            if (!$act->isVisible($row)) continue;
            $aId = $row[$act->idField] ?? $rowId;
            $ep  = !empty($act->params) ? ', ' . json_encode(array_values($act->params)) : '';
            $act = $act->getTransformed($row);
            $dis = $act->isDisabled($row) ? ' disabled' : '';
            // Botão recusado pelo perfil explica o porquê no lugar do rótulo —
            // .mad-dg-action-btn:disabled não tem pointer-events:none, o title
            // do próprio botão aparece.
            $ttl = ' title="' . htmlspecialchars($act->denyTitle() ?: $act->label, ENT_QUOTES) . '"';
            $ico = $act->icon
                ? '<i data-lucide="' . htmlspecialchars($act->icon, ENT_QUOTES) . '" style="width:14px;height:14px;"></i>'
                : '';
            $lbl = ($act->label && !$act->icon) ? htmlspecialchars($act->label, ENT_QUOTES) : '';

            if ($act->isNav) {
                $html .= '<button type="button" class="' . $act->btnClass() . '"' . $dis . $ttl
                        . ' ' . $act->getNavAttr($aId, $row) . '>' . $ico . $lbl . '</button>';
            } elseif ($act->confirm || $act->confirmPopover) {
                $cMsg  = $act->confirmPopover ?: $act->confirm;
                $cType = $act->confirmPopover ? "'popover'" : 'null';
                $click = 'confirmAction(' . json_encode($cMsg)
                       . ", () => \$dispatch('mad-dg-call',{method:" . json_encode($act->method)
                       . ',params:[' . self::rowIdJs($aId) . $ep . "]}), \$event, " . $cType . ')';
                $html .= '<button type="button" class="' . $act->btnClass() . '"' . $dis . $ttl
                        . ' @click="' . htmlspecialchars($click, ENT_COMPAT) . '">'
                        . $ico . $lbl . '</button>';
            } else {
                $html .= '<button type="button" class="' . $act->btnClass() . '"' . $dis . $ttl
                        . ' mad:click="' . htmlspecialchars($act->method . '(' . self::rowIdJs($aId) . $ep . ')', ENT_COMPAT) . '">'
                        . $ico . $lbl . '</button>';
            }
        }

        foreach ($actionGroups as $grp) {
            $gc = 'mad-dg-action-btn' . ($grp->label ? ' mad-dg-action-btn-group' : '');
            $html .= '<div class="mad-dg-dropdown-wrap" x-data="{open:false}">';
            $html .= '<button type="button" class="' . $gc . '" @click.stop="open=!open"'
                    . ' title="' . htmlspecialchars($grp->label, ENT_QUOTES) . '">';
            if ($grp->icon) $html .= '<i data-lucide="' . htmlspecialchars($grp->icon, ENT_QUOTES) . '" style="width:14px;height:14px;"></i>';
            if ($grp->label) $html .= '<span class="mad-dg-action-btn-label">' . htmlspecialchars($grp->label, ENT_QUOTES) . '</span>';
            $html .= '</button>';
            $html .= '<div class="mad-dg-dropdown" x-show="open" x-cloak @click.outside="open=false">';

            foreach ($grp->actions as $act) {
                if (!$act->isVisible($row)) continue;
                $aId = $row[$act->idField] ?? $rowId;
                $ep  = !empty($act->params) ? ', ' . json_encode(array_values($act->params)) : '';
                $act = $act->getTransformed($row);
                $cls = 'mad-dg-dropdown-item' . ($act->isDanger ? ' mad-dg-dropdown-danger' : '');
                $dis = $act->isDisabled($row) ? ' disabled' : '';
                // Item recusado pelo perfil ganha a dica do porquê (item de menu
                // não tem rótulo em title — só o texto ao lado).
                $den = $act->denyTitle();
                if ($den !== '') $dis .= ' title="' . htmlspecialchars($den, ENT_QUOTES) . '"';
                $ico = $act->icon
                    ? '<i data-lucide="' . htmlspecialchars($act->icon, ENT_QUOTES) . '" style="width:13px;height:13px;"></i>'
                    : '';

                if ($act->isNav) {
                    $html .= '<button type="button" class="' . $cls . '"' . $dis
                            . ' @click="open=false" ' . $act->getNavAttr($aId, $row) . '>'
                            . $ico . htmlspecialchars($act->label, ENT_QUOTES) . '</button>';
                } elseif ($act->confirm || $act->confirmPopover) {
                    $cMsg  = $act->confirmPopover ?: $act->confirm;
                    $cType = $act->confirmPopover ? "'popover'" : 'null';
                    $click = "open=false; confirmAction(" . json_encode($cMsg)
                           . ", () => \$dispatch('mad-dg-call',{method:" . json_encode($act->method)
                           . ',params:[' . self::rowIdJs($aId) . $ep . "]}), \$event, " . $cType . ')';
                    $html .= '<button type="button" class="' . $cls . '"' . $dis
                            . ' @click="' . htmlspecialchars($click, ENT_COMPAT) . '">'
                            . $ico . htmlspecialchars($act->label, ENT_QUOTES) . '</button>';
                } else {
                    $html .= '<button type="button" class="' . $cls . '"' . $dis
                            . ' @click="open=false" mad:click="' . htmlspecialchars($act->method . '(' . self::rowIdJs($aId) . $ep . ')', ENT_COMPAT) . '">'
                            . $ico . htmlspecialchars($act->label, ENT_QUOTES) . '</button>';
                }
            }

            $html .= '</div></div>';
        }

        $html .= '</div></td>';
        return $html;
    }
}
