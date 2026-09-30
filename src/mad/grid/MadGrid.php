<?php
namespace Mad\Grid;
use Mad\Ui\MadToast;


/**
 * MadGrid — Grid reativo configurável 100% via Blade, sem subclasse PHP.
 *
 * ┌─ Uso mínimo (zero arquivos PHP extras) ───────────────────────────────┐
 * │                                                                        │
 * │  {!! MadGrid::of('Pessoa')                                             │
 * │      ->perPage(15)                                                     │
 * │      ->col('id',    'Cód.')->w(60)->center()->sort()                   │
 * │      ->col('nome',  'Nome')->sort()->filter()                          │
 * │      ->col('email', 'E-mail')->filter()                                │
 * │      ->col('status','Status')->badge(['A'=>'success:Ativo',            │
 * │                                       'I'=>'danger:Inativo'])          │
 * │      ->col('dt',   'Data')->date('d/m/Y')->w(110)->center()            │
 * │      ->nav('pencil','Editar',  'PessoaForm')    ← navega, zero PHP    │
 * │      ->del('Excluir este registro?')            ← delete embutido     │
 * │  !!}                                                                   │
 * │                                                                        │
 * ├─ Com handler para ações customizadas ─────────────────────────────────┤
 * │                                                                        │
 * │  {!! MadGrid::of('Pedido')                                             │
 * │      ->col('total','Total')->money('R$')->sort()->total('sum')         │
 * │      ->act('onAprovar','check','Aprovar')->primary()                   │
 * │      ->handler('PedidoHandler')          ← classe com onAprovar()     │
 * │  !!}                                                                   │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 */
class MadGrid extends MadDataGrid
{
    protected static string $wrapper = self::INTERNAL;

    /**
     * Config serializada no state (colunas, ações, model, handler...).
     * Tudo que MadGrid precisa para reconstruir-se após um AJAX.
     */
    public array $gridConfig = [];

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        if (!empty($params['config'])) {
            $this->gridConfig = $params['config'];
        }
        $cfg = $this->gridConfig;
        if (!empty($cfg['model']))      $this->model      = $cfg['model'];
        if (!empty($cfg['database']))   $this->database   = $cfg['database'];
        if (!empty($cfg['perPage']))    $this->perPage    = (int)$cfg['perPage'];
        if (!empty($cfg['actionSide'])) $this->actionSide = $cfg['actionSide'];
        if (isset($cfg['exportable']))  $this->exportable = (bool)$cfg['exportable'];
        if (isset($cfg['searchable']))  $this->searchable = (bool)$cfg['searchable'];
        if (isset($cfg['autoLoad']))    $this->autoLoad   = (bool)$cfg['autoLoad'];
        if (!empty($cfg['requireFilter'])) { $this->requireFilter = true; $this->autoLoad = false; }
        if (isset($cfg['requireFilterFields'])) $this->requireFilterFields = array_values((array)$cfg['requireFilterFields']);
        if (!empty($cfg['defaultSort']))$this->defaultSort= $cfg['defaultSort'];
        // row-detail: 2ª linha descritiva. Protegida no MadDataGrid — sem
        // este mapeamento o atributo seria compilado e nunca lido.
        if (!empty($cfg['rowDetail']))  $this->rowDetail  = (string) $cfg['rowDetail'];
        // Seleção de linhas + ações em lote (<mad-grid model=... selectable>).
        if (!empty($cfg['selectable'])) $this->selectable = true;
        if (!empty($cfg['bulkActions']) && is_array($cfg['bulkActions'])) {
            $this->bulkActions = static::_normalizeBulkActions($cfg['bulkActions']);
        }
        // order-by/:filters declarativos do Blade (props públicas do MadDataGrid;
        // mapeados ANTES do parent::mount() → o loadData() já roda com eles).
        if (!empty($cfg['orderBy'])) $this->baseOrder = (string) $cfg['orderBy'];
        if (!empty($cfg['filters']) && is_array($cfg['filters'])) {
            $this->baseFilters = $cfg['filters'];
        }
        // Filtro avançado (<mad-custom-filters>): defs seladas SÓ aqui, no
        // primeiro render, a partir do config compilado. O hydrate() NÃO as
        // reaplica do gridConfig — ele viaja no estado e pode ter sido reescrito
        // por mad:model; as defs seladas no state é que valem nos AJAX.
        $this->_cfApplyConfig(is_array($cfg['customFilters'] ?? null) ? $cfg['customFilters'] : null);
        // :query (closure) não sobrevive ao mad_state — compilador marca e o
        // runtime avisa uma vez por classe, apontando a alternativa declarativa.
        if (!empty($cfg['queryIgnored'])) {
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
        parent::mount($params);
    }

    public function hydrate(): void
    {
        $cfg = $this->gridConfig;
        if (!empty($cfg['model']))      $this->model      = $cfg['model'];
        if (!empty($cfg['database']))   $this->database   = $cfg['database'];
        if (!empty($cfg['actionSide'])) $this->actionSide = $cfg['actionSide'];
        if (isset($cfg['exportable']))  $this->exportable = (bool)$cfg['exportable'];
        if (isset($cfg['searchable']))  $this->searchable = (bool)$cfg['searchable'];
        if (isset($cfg['autoLoad']))    $this->autoLoad   = (bool)$cfg['autoLoad'];
        if (!empty($cfg['requireFilter'])) { $this->requireFilter = true; $this->autoLoad = false; }
        if (isset($cfg['requireFilterFields'])) $this->requireFilterFields = array_values((array)$cfg['requireFilterFields']);
        if (!empty($cfg['defaultSort']))$this->defaultSort= $cfg['defaultSort'];
        // row-detail: 2ª linha descritiva. Protegida no MadDataGrid — sem
        // este mapeamento o atributo seria compilado e nunca lido.
        if (!empty($cfg['rowDetail']))  $this->rowDetail  = (string) $cfg['rowDetail'];
        if (!empty($cfg['orderBy'])) $this->baseOrder = (string) $cfg['orderBy'];
        if (!empty($cfg['filters']) && is_array($cfg['filters'])) {
            $this->baseFilters = $cfg['filters'];
        }
        parent::hydrate();
    }

    // ── Geração de colunas/ações a partir do config ───────────────────────

    protected function columns(): array
    {
        return array_map(
            fn($c) => static::_colFromConfig($c),
            $this->gridConfig['colConfigs'] ?? []
        );
    }

    protected function actions(): array
    {
        $acts = array_map(
            fn($a) => static::_actFromConfig($a),
            $this->gridConfig['actConfigs'] ?? []
        );
        return array_filter($acts);
    }

    // ── Delete embutido ───────────────────────────────────────────────────

    /**
     * Action embutida de exclusão — chamada quando configurado via ->del().
     * Não requer handler externo.
     */
    public function onMadGridDelete(int|string $id): mixed
    {
        $model = $this->gridConfig['model'] ?? '';
        if (empty($model) || !class_exists($model)) {
            return MadToast::danger('Model não configurado corretamente.');
        }

        $db = $this->_db();
        try {
            \Illuminate\Support\Facades\DB::connection($db)->transaction(function () use ($model, $id) {
                $record = $model::find($id);
                if ($record) {
                    $record->delete();
                }
            });
        } catch (\Throwable $e) {
            // Erro técnico (ex.: registro em uso — violação de FK) não vai cru
            // pra tela: o toast mostrava o SQL e o caminho do banco.
            return \Mad\Ui\MadUserError::isTechnical($e)
                ? MadToast::danger(\Mad\Ui\MadUserError::message($e, mad_t('mad.error.delete_failed'), static::class . '::onMadGridDelete'))
                : MadToast::danger('Erro ao excluir: ' . $e->getMessage());
        }

        $this->loadData();
        return MadToast::success('Registro excluído com sucesso.');
    }

    // ── Dono da permissão ─────────────────────────────────────────────────

    /**
     * A quem PERGUNTAR pela permissão das ações deste grid — o dono principal.
     *
     * O `<mad-grid>` declarativo não é uma tela: ele roda nesta classe do
     * framework, liberada a todo usuário logado como qualquer serviço interno.
     * Se a permissão fosse checada contra ela, a exclusão embutida (`->del()`)
     * escaparia de qualquer perfil — foi exatamente o que acontecia.
     *
     * O dono principal é a **TELA** que contém o grid (gravada pelo compilador),
     * porque é ela que o perfil marca: ninguém cadastra "PedidoHandler" na lista
     * de programas, cadastra "Pedidos". A classe de ações (`->handler(...)`)
     * entra como dono secundário — ver {@see _permOwners()}. Sem nenhum dos
     * dois, sobra esta classe, e a permissão fica liberada como sempre foi.
     */
    public function _permOwner(): string
    {
        $donos = $this->_permOwners();

        return $donos[0] ?? static::class;
    }

    /**
     * TODOS os donos de permissão do grid, na ordem: tela, depois a classe de
     * ações. Sem vazios e sem repetição.
     *
     * São dois porque as ações de um grid podem estar em dois lugares: as
     * embutidas (`->del()`) pertencem à tela, e as declaradas por
     * `->handler('PedidoHandler')` podem estar cadastradas no próprio handler.
     * Perguntar a um só deixava um buraco: com handler configurado, a exclusão
     * embutida escapava da marcação "Excluir" da tela. O gate recusa se
     * QUALQUER um dos dois disser não — negação nunca é revogada por um
     * segundo dono que simplesmente não conhece a ação.
     *
     * @return list<string>
     */
    public function _permOwners(): array
    {
        $donos = [];
        foreach (['owner', 'handler'] as $chave) {
            $classe = trim((string) ($this->gridConfig[$chave] ?? ''));
            if ($classe !== '' && !in_array($classe, $donos, true)) {
                $donos[] = $classe;
            }
        }

        return $donos;
    }

    /**
     * Chave da seleção: a TELA dona (é ela que outra tela conhece —
     * MadGridSelection::get(PedidoList::class)); sem dono, esta classe.
     */
    protected function _selectionKey(): string
    {
        $owner = trim((string) ($this->gridConfig['owner'] ?? ''));

        return $owner !== '' ? $owner : static::class;
    }

    /**
     * Chave dos filtros avançados salvos: a TELA dona + o model. Todo grid
     * standalone roda nesta mesma classe — com a chave da classe, os filtros
     * salvos de uma tela apareceriam (com as colunas "caducadas") em todas.
     */
    protected function _cfGridKey(): string
    {
        $owner = trim((string) ($this->gridConfig['owner'] ?? ''));
        $model = trim((string) ($this->gridConfig['model'] ?? ''));
        $key   = str_replace('\\', '_', $owner !== '' ? $owner : static::class);

        return $model !== '' ? $key . ':' . basename(str_replace('\\', '/', $model)) : $key;
    }

    // ── Delegação de actions ao handler externo ───────────────────────────

    public function __call(string $name, array $args): mixed
    {
        // Ações de linha E ações em lote (<mad-bulk-action method>) delegam ao
        // handler — o método do lote recebe a seleção ([id => id]).
        $actConfigs   = array_merge($this->gridConfig['actConfigs'] ?? [], $this->gridConfig['bulkActions'] ?? []);
        $matchingActs = array_filter($actConfigs, fn($a) => ($a['method'] ?? '') === $name);

        if (!empty($matchingActs) && !empty($this->gridConfig['handler'])) {
            $handlerClass = $this->gridConfig['handler'];
            if (class_exists($handlerClass) && method_exists($handlerClass, $name)) {
                $handler = new $handlerClass();
                $result  = $handler->$name(...$args);
                $this->loadData();
                return $result;
            }
        }

        throw new \BadMethodCallException(
            "Método '{$name}' não encontrado. "
            . "Configure ->handler('MinhaClasse') com o método '{$name}' público, "
            . "ou implemente a ação diretamente em uma subclasse de MadGrid."
        );
    }

    // ── Fábrica estática ──────────────────────────────────────────────────

    /**
     * Inicia o builder fluent a partir do Blade.
     *
     * @param  string $model  Classe do model (ex: 'Pessoa', 'PedidoVenda')
     */
    public static function of(string $model): GridBuilder
    {
        return new GridBuilder($model);
    }

    /**
     * Renderiza uma instância a partir de um config array (chamado pelo GridBuilder).
     *
     * @internal
     */
    public static function _renderFromConfig(array $config): string
    {
        $grid             = new static();
        $grid->gridConfig = $config;
        if (!empty($config['model']))      $grid->model      = $config['model'];
        if (!empty($config['database']))   $grid->database   = $config['database'];
        if (!empty($config['perPage']))    $grid->perPage    = (int)$config['perPage'];
        if (!empty($config['actionSide'])) $grid->actionSide = $config['actionSide'];
        if (isset($config['exportable']))  $grid->exportable = (bool)$config['exportable'];
        if (isset($config['searchable']))  $grid->searchable = (bool)$config['searchable'];
        if (isset($config['autoLoad']))    $grid->autoLoad   = (bool)$config['autoLoad'];
        if (!empty($config['requireFilter'])) { $grid->requireFilter = true; $grid->autoLoad = false; }
        if (isset($config['requireFilterFields'])) $grid->requireFilterFields = array_values((array)$config['requireFilterFields']);
        if (!empty($config['defaultSort']))$grid->defaultSort= $config['defaultSort'];
        if (!empty($config['selectable'])) $grid->selectable = true;
        $grid->boot();
        $grid->mount([]);
        return $grid->_renderWrapped();
    }

    // _colFromConfig(), _actFromConfig() e _evalCond() herdados de MadDataGrid.
}

// ============================================================================
//  GridBuilder — builder fluent; retornado por MadGrid::of()
// ============================================================================

/**
 * GridBuilder — acumula a configuração do grid e produz o HTML final.
 *
 * Retornado por MadGrid::of(). Suporta encadeamento completo:
 *   ->col() → GridColBuilder (col-level methods) que delega de volta ao GridBuilder
 *   ->act() → GridActBuilder (act-level methods) que delega de volta ao GridBuilder
 *   ->render() / echo $builder → renderiza o componente
 */
class GridBuilder
{
    private string $model      = '';
    private array  $colConfigs = [];
    private array  $actConfigs = [];
    private array  $options    = [];   // perPage, groupBy, actionSide, etc.

    /** Seção em construção no momento (não finalizada ainda). */
    private ?GridColBuilder $_pendingCol = null;
    private ?GridActBuilder $_pendingAct = null;

    public function __construct(string $model)
    {
        $this->model = $model;
    }

    // ── Finalização interna ───────────────────────────────────────────────

    public function _flush(): void
    {
        if ($this->_pendingCol !== null) {
            $this->colConfigs[] = $this->_pendingCol->_config();
            $this->_pendingCol  = null;
        }
        if ($this->_pendingAct !== null) {
            $this->actConfigs[] = $this->_pendingAct->_config();
            $this->_pendingAct  = null;
        }
    }

    // ── Opções de grid ────────────────────────────────────────────────────

    public function perPage(int $n): static
    {
        $this->_flush();
        $this->options['perPage'] = $n;
        return $this;
    }

    public function groupBy(string $field, bool $withTotal = false): static
    {
        $this->_flush();
        $this->options['groupBy']    = $field;
        $this->options['groupTotal'] = $withTotal;
        return $this;
    }

    /**
     * 2ª linha descritiva por registro: '{descricao} conf. {documento}'.
     *
     * `group-band`/`group-total-label` NÃO têm equivalente fluent aqui de
     * propósito: o `groupBy` do GridBuilder nunca chegou a ser aplicado no
     * caminho standalone (o mount() do MadGrid não o lê do gridConfig), então
     * um setter de banda de quebra seria API inerte. No `<mad-grid self>`, que
     * é o caminho do editor, os dois funcionam.
     */
    public function rowDetail(string $mask): static
    {
        $this->_flush();
        $this->options['rowDetail'] = $mask;
        return $this;
    }

    public function actionsLeft(): static
    {
        $this->_flush();
        $this->options['actionSide'] = 'left';
        return $this;
    }

    /** Equivalente ao `no-auto-load` do Blade: abre vazio, consulta só após ação do usuário. */
    public function noAutoLoad(): static
    {
        $this->_flush();
        $this->options['autoLoad'] = false;
        return $this;
    }

    /**
     * Equivalente ao `require-filter` do Blade: abre vazio e só consulta com ao
     * menos um filtro do usuário. `$fields` restringe quais campos liberam.
     */
    public function requireFilter(array $fields = []): static
    {
        $this->_flush();
        $this->options['requireFilter'] = true;
        $this->options['autoLoad'] = false;
        if (!empty($fields)) $this->options['requireFilterFields'] = array_values($fields);
        return $this;
    }

    /** Equivalente ao `no-load-button`: oculta o botão "Carregar registros". */
    public function noLoadButton(): static
    {
        $this->_flush();
        $this->options['loadButton'] = false;
        return $this;
    }

    /** Equivalente ao `load-hint`: texto da listagem vazia. */
    public function loadHint(string $text): static
    {
        $this->_flush();
        $this->options['loadHint'] = $text;
        return $this;
    }

    public function handler(string $class): static
    {
        $this->_flush();
        $this->options['handler'] = $class;
        return $this;
    }

    public function database(string $db): static
    {
        $this->_flush();
        $this->options['database'] = $db;
        return $this;
    }

    // ── Definição de colunas ──────────────────────────────────────────────

    public function col(string $field, string $label): GridColBuilder
    {
        $this->_flush();
        $this->_pendingCol = new GridColBuilder($this, $field, $label);
        return $this->_pendingCol;
    }

    // ── Definição de ações ────────────────────────────────────────────────

    /**
     * Ação genérica AJAX — requer handler() ou subclasse.
     * Ex: ->act('onAprovar','check','Aprovar')->primary()
     */
    public function act(string $method, string $icon = '', string $label = ''): GridActBuilder
    {
        $this->_flush();
        $this->_pendingAct = new GridActBuilder($this, $method, $icon, $label);
        return $this->_pendingAct;
    }

    /**
     * Ação de navegação — abre uma página ou modal/drawer sem AJAX no grid.
     * Ex: ->nav('pencil','Editar','PessoaForm')
     *     ->nav('eye','Ver','PessoaForm','show',drawer:true)
     *
     * @param string $icon       Ícone Lucide
     * @param string $label      Rótulo do botão
     * @param string $targetClass Classe destino (ex: 'PessoaForm')
     * @param string $targetMethod Método (padrão: 'show')
     * @param bool   $drawer     true → abre como drawer/modal via @madGet
     * @param bool   $row        true → abre anexado à linha (quick-edit inline)
     */
    public function nav(
        string $icon,
        string $label,
        string $targetClass,
        string $targetMethod = 'show',
        bool   $drawer = false,
        bool   $row = false
    ): static {
        $this->_flush();
        $method = 'nav_' . strtolower($targetClass) . '_' . $targetMethod . '_' . substr(md5(uniqid()), 0, 6);
        $this->_pendingAct = (new GridActBuilder($this, $method, $icon, $label))
            ->_setNav($targetClass, $targetMethod, $drawer, $row);
        return $this;
    }

    /**
     * Delete embutido — usa MadGrid::onMadGridDelete() internamente.
     * Zero PHP extra necessário.
     *
     * @param string $confirm Mensagem de confirmação
     * @param string $icon    Ícone Lucide (padrão: trash-2)
     */
    public function del(string $confirm = 'Excluir este registro?', string $icon = 'trash-2'): static
    {
        $this->_flush();
        $this->_pendingAct = (new GridActBuilder($this, 'onMadGridDelete', $icon, 'Excluir'))
            ->danger()
            ->confirm($confirm);
        return $this;
    }

    // ── Renderização ──────────────────────────────────────────────────────

    public function render(): string
    {
        $this->_flush();

        $config = array_merge($this->options, [
            'model'      => $this->model,
            'colConfigs' => $this->colConfigs,
            'actConfigs' => $this->actConfigs,
        ]);

        return MadGrid::_renderFromConfig($config);
    }

    public function __toString(): string
    {
        return $this->render();
    }
}

// ============================================================================
//  GridColBuilder — métodos fluent de coluna, delega de volta ao GridBuilder
// ============================================================================

class GridColBuilder
{
    private GridBuilder $grid;
    private array       $cfg;

    public function __construct(GridBuilder $grid, string $field, string $label)
    {
        $this->grid = $grid;
        $this->cfg  = ['field' => $field, 'label' => $label];
    }

    public function _config(): array { return $this->cfg; }

    // ── Configuração de coluna ────────────────────────────────────────────

    /** Largura: int (px) ou string ('10%', '80px'). */
    public function w(int|string $width): static
    {
        $this->cfg['width'] = is_int($width) ? "{$width}px" : $width;
        return $this;
    }

    public function sort(): static          { $this->cfg['sortable']  = true;         return $this; }
    public function center(): static        { $this->cfg['align']     = 'center';     return $this; }
    public function right(): static         { $this->cfg['align']     = 'right';      return $this; }
    public function hide(): static          { $this->cfg['hidden']    = true;         return $this; }
    public function edit(): static          { $this->cfg['editable']  = true;         return $this; }
    public function html(): static          { $this->cfg['renderType'] = 'html';      return $this; }
    public function total(string $f): static{ $this->cfg['totalFunc'] = $f;           return $this; }
    public function group(string $g): static{ $this->cfg['group']     = $g;           return $this; }

    /** Expressão computada: '{valor} * {quantidade}' ou '{rel->campo}' */
    public function evaluate(string $e): static { $this->cfg['evaluate'] = $e; return $this; }

    /** Máscara do total (rodapé e sub-total da quebra): 'Total: {value}'. */
    public function totalMask(string $m): static { $this->cfg['totalMask'] = $m; return $this; }

    /**
     * Saldo acumulado: ->running() acumula o próprio campo; com expressão
     * ('{credito} - {debito}') acumula o delta.
     * $reset: '' | 'none' | 'group' | 'group:N'.
     */
    public function running(string $expr = 'self', string $reset = '', string $start = ''): static
    {
        $this->cfg['running']      = $expr !== '' ? $expr : 'self';
        $this->cfg['runningReset'] = $reset;
        $this->cfg['runningStart'] = $start;
        return $this;
    }

    /**
     * Filtro: ->filter() texto livre | ->filter('select', [...opts]) | ->filter('date')
     */
    public function filter(string $type = 'text', array $opts = []): static
    {
        $this->cfg['filterable'] = true;
        $this->cfg['filterType'] = $type;
        $this->cfg['filterOpts'] = $opts;
        return $this;
    }

    /**
     * Badge por valor.
     *
     * Formatos do map:
     *   'value' => 'success'            → variant apenas (label = value)
     *   'value' => 'success:Ativo'      → variant:label
     *   'value' => ['variant'=>'success', 'label'=>'Ativo']  → array
     */
    public function badge(array $map = []): static
    {
        $this->cfg['renderType'] = 'badge';
        $this->cfg['badgeMap']   = $map;
        return $this;
    }

    /** Formata como moeda: R$ 1.234,56 */
    public function money(string $prefix = ''): static
    {
        $this->cfg['renderType']   = 'money';
        $this->cfg['moneyPrefix']  = $prefix;
        return $this;
    }

    /** Formata como número com decimais. */
    public function num(int $decimals = 2): static
    {
        $this->cfg['renderType']      = 'number';
        $this->cfg['numberDecimals']  = $decimals;
        return $this;
    }

    /** Formata data. */
    public function date(string $format = 'd/m/Y'): static
    {
        $this->cfg['renderType'] = 'date';
        $this->cfg['dateFormat'] = $format;
        return $this;
    }

    // ── Delegação ao GridBuilder ──────────────────────────────────────────

    public function col(string $f, string $l): GridColBuilder { return $this->grid->col($f, $l); }
    public function act(string $m, string $i = '', string $l = ''): GridActBuilder { return $this->grid->act($m, $i, $l); }
    public function nav(string $i, string $l, string $c, string $mt = 'show', bool $d = false): GridBuilder { return $this->grid->nav($i, $l, $c, $mt, $d); }
    public function del(string $confirm = 'Excluir este registro?', string $icon = 'trash-2'): GridBuilder { return $this->grid->del($confirm, $icon); }
    public function perPage(int $n): GridBuilder { return $this->grid->perPage($n); }
    public function handler(string $c): GridBuilder { return $this->grid->handler($c); }
    public function database(string $db): GridBuilder { return $this->grid->database($db); }
    public function groupBy(string $f, bool $t = false): GridBuilder { return $this->grid->groupBy($f, $t); }
    public function actionsLeft(): GridBuilder { return $this->grid->actionsLeft(); }

    public function render(): string    { return $this->grid->render(); }
    public function __toString(): string { return $this->grid->render(); }
}

// ============================================================================
//  GridActBuilder — métodos fluent de ação, delega de volta ao GridBuilder
// ============================================================================

class GridActBuilder
{
    private GridBuilder $grid;
    private array       $cfg;

    public function __construct(GridBuilder $grid, string $method, string $icon, string $label)
    {
        $this->grid = $grid;
        $this->cfg  = ['method' => $method, 'icon' => $icon, 'label' => $label];
    }

    public function _config(): array { return $this->cfg; }

    public function _setNav(string $class, string $method, bool $drawer, bool $row = false): static
    {
        $this->cfg['navClass']  = $class;
        $this->cfg['navMethod'] = $method;
        $this->cfg['navDrawer'] = $drawer;
        $this->cfg['navRow']    = $row;
        return $this;
    }

    // ── Configuração de ação ──────────────────────────────────────────────

    public function primary(): static                { $this->cfg['primary'] = true;  return $this; }
    public function danger(): static                 { $this->cfg['danger']  = true;  return $this; }
    public function confirm(string $msg): static     { $this->cfg['confirm'] = $msg;  return $this; }
    public function idField(string $f): static       { $this->cfg['idField'] = $f;    return $this; }
    public function params(array $p): static         { $this->cfg['params']  = $p;    return $this; }

    /** Condição simples de visibilidade (serializable, sem closure). */
    public function when(string $field, mixed $value, string $op = 'eq'): static
    {
        $this->cfg['when'] = ['field' => $field, 'op' => $op, 'value' => $value];
        return $this;
    }

    /** Condição simples de desabilitado. */
    public function disabled(string $field, mixed $value, string $op = 'eq'): static
    {
        $this->cfg['disabled'] = ['field' => $field, 'op' => $op, 'value' => $value];
        return $this;
    }

    /** Abre drawer ao invés de página: equivale a @madGet */
    public function drawer(string $class, string $method = 'show'): static
    {
        return $this->_setNav($class, $method, true);
    }

    /** Abre o alvo anexado à linha da grid (quick-edit inline). */
    public function attachRow(string $class, string $method = 'show'): static
    {
        return $this->_setNav($class, $method, false, true);
    }

    // ── Delegação ao GridBuilder ──────────────────────────────────────────

    public function col(string $f, string $l): GridColBuilder  { return $this->grid->col($f, $l); }
    public function act(string $m, string $i = '', string $l = ''): GridActBuilder { return $this->grid->act($m, $i, $l); }
    public function nav(string $i, string $l, string $c, string $mt = 'show', bool $d = false): GridBuilder { return $this->grid->nav($i, $l, $c, $mt, $d); }
    public function del(string $confirm = 'Excluir este registro?', string $icon = 'trash-2'): GridBuilder { return $this->grid->del($confirm, $icon); }
    public function perPage(int $n): GridBuilder    { return $this->grid->perPage($n); }
    public function handler(string $c): GridBuilder  { return $this->grid->handler($c); }
    public function database(string $db): GridBuilder{ return $this->grid->database($db); }
    public function groupBy(string $f, bool $t = false): GridBuilder { return $this->grid->groupBy($f, $t); }

    public function render(): string     { return $this->grid->render(); }
    public function __toString(): string { return $this->grid->render(); }
}