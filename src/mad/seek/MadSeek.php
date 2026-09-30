<?php
namespace Mad\Seek;
use Mad\Grid\MadGridCompiler;
use Mad\View\MadBlade;


/**
 * MadSeek — Campo de busca com modal de listagem (DB Seek Field).
 *
 * ┌─ Uso mínimo ─────────────────────────────────────────────────────────────┐
 * │                                                                          │
 * │  {!! MadSeek::of('Pessoa')                                               │
 * │      ->name('cliente_id')                                                │
 * │      ->label('Cliente')                                                  │
 * │      ->display('nome')                                                   │
 * │      ->column('id',   'Cód.')->w(60)->sort()                            │
 * │      ->column('nome', 'Nome')->sort()->filter()                         │
 * │      ->column('email','E-mail')->filter()                               │
 * │      ->perPage(10)                                                      │
 * │  !!}                                                                     │
 * │                                                                          │
 * ├─ Com valor inicial e campos auxiliares ──────────────────────────────────┤
 * │                                                                          │
 * │  {!! MadSeek::of('Pessoa')                                               │
 * │      ->name('cliente_id')                                                │
 * │      ->label('Cliente')                                                  │
 * │      ->display('nome')                                                   │
 * │      ->value($cliente_id ?? '')                                          │
 * │      ->text($cliente_nome ?? '')                                         │
 * │      ->auxiliary('email', '#cliente_email')                              │
 * │      ->column('id',   'Cód.')->w(60)->sort()                            │
 * │      ->column('nome', 'Nome')->sort()->filter()                         │
 * │  !!}                                                                     │
 * │                                                                          │
 * ├─ Com "Novo" (cadastra e volta selecionado) ─────────────────────────────┤
 * │                                                                          │
 * │  {!! MadSeek::of('Pessoa')                                               │
 * │      ->name('cliente_id')->label('Cliente')->display('nome')             │
 * │      ->column('nome', 'Nome')->filter()                                  │
 * │      ->create('PessoaForm::onShowFromSeek', 'Novo cliente')              │
 * │  !!}                                                                     │
 * │  O onSave do PessoaForm devolve com $this->returnToCombo($id).           │
 * │                                                                          │
 * ├─ Com ação customizada ──────────────────────────────────────────────────┤
 * │                                                                          │
 * │  {!! MadSeek::of('Pessoa')                                               │
 * │      ->name('cliente_id')                                                │
 * │      ->label('Cliente')                                                  │
 * │      ->display('nome')                                                   │
 * │      ->column('id',   'Cód.')->w(60)->sort()                            │
 * │      ->column('nome', 'Nome')->sort()->filter()                         │
 * │      ->action('onCadastrar','plus','Novo')->primary()                   │
 * │      ->handler('ClienteHandler')                                         │
 * │  !!}                                                                     │
 * │                                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class MadSeek
{
    /**
     * Inicia o builder fluent.
     *
     * @param string $model Classe do model (ex: 'Pessoa')
     */
    public static function of(string $model): SeekBuilder
    {
        return new SeekBuilder($model);
    }

    /**
     * Renderiza o campo completo a partir de um config array.
     *
     * @internal Chamado pelo SeekBuilder e MadGridCompiler.
     */
    public static function _renderFromConfig(array $config): string
    {
        // `:query` do <mad-seek>: o Builder não serializa no estado do grid —
        // vira SQL + bindings, o mesmo caminho do ->query() fluente.
        if (array_key_exists('query', $config)) {
            $builder = $config['query'];
            unset($config['query']);
            if (is_object($builder)) {
                [$config['query_sql'], $config['query_bindings']] = \Mad\Database\QuerySource::compileSql($builder);
                if ($db = \Mad\Database\QuerySource::connectionName($builder)) {
                    $config['database'] = $db;
                }
            }
        }

        $gridHtml = MadSeekGrid::_renderFromConfig($config);

        // "Novo" (`create` do <mad-seek>): abre o cadastro por cima da tela com
        // a origem ASSINADA — o mesmo token do "Sem resultados → Cadastrar
        // novo" dos combos. O form alvo salva e o `returnToCombo()` dele
        // devolve o registro a ESTE campo (chave + texto + <mad-fill>).
        $create = \Mad\Form\MadNoResultsHelper::createAction([
            'createAction'   => (string) ($config['create'] ?? ''),
            'createLabel'    => (string) (($config['createLabel'] ?? '') ?: 'Novo'),
            'createIcon'     => (string) (($config['createIcon'] ?? '') ?: 'plus'),
            'allowClassOnly' => true,
            'name'           => (string) ($config['seekName'] ?? ''),
            'model'          => (string) ($config['model'] ?? ''),
            'database'       => (string) ($config['database'] ?? ''),
            'key'            => 'id',
            'display'        => (string) ($config['display'] ?? ''),
        ]);

        return MadBlade::render('components.dbseek-field', [
            'name'        => $config['seekName']    ?? '',
            'label'       => $config['label']       ?? '',
            'value'       => $config['value']       ?? '',
            'text'        => $config['text']        ?? '',
            'display'     => $config['display']     ?? '',
            'gridHtml'    => $gridHtml,
            'required'    => $config['required']    ?? false,
            'disabled'    => $config['disabled']    ?? false,
            'placeholder' => $config['placeholder'] ?? '',
            'hint'        => $config['hint']        ?? '',
            'modalTitle'  => $config['modalTitle']  ?? ($config['label'] ?? 'Buscar'),
            'modalSize'   => $config['modalSize']   ?? 'xl',
            'auxiliaries' => $config['auxiliaries']  ?? [],
            // Em branco grava NULL (default do dbseek-field); `empty-as` do <mad-seek>.
            'emptyAs'     => $config['emptyAs']     ?? 'null',
            'create'      => $create,
        ]);
    }
}

// ============================================================================
//  SeekBuilder — builder fluent; retornado por MadSeek::of()
// ============================================================================

class SeekBuilder
{
    private string $model       = '';
    private array  $colConfigs  = [];
    private array  $actConfigs  = [];
    private array  $options     = [];

    private ?SeekColumnBuilder $_pendingColumn = null;
    private ?SeekActionBuilder $_pendingAction = null;

    public function __construct(string $model)
    {
        $this->model = $model;
    }

    public function _flush(): void
    {
        if ($this->_pendingColumn !== null) {
            $this->colConfigs[] = $this->_pendingColumn->_config();
            $this->_pendingColumn = null;
        }
        if ($this->_pendingAction !== null) {
            $this->actConfigs[] = $this->_pendingAction->_config();
            $this->_pendingAction = null;
        }
    }

    // ── Opções do campo ───────────────────────────────────────────────────

    public function name(string $n): static        { $this->_flush(); $this->options['seekName']    = $n; return $this; }
    public function label(string $l): static       { $this->_flush(); $this->options['label']       = $l; return $this; }
    public function display(string $d): static     { $this->_flush(); $this->options['display']     = $d; return $this; }
    public function value(string $v): static       { $this->_flush(); $this->options['value']       = $v; return $this; }
    public function text(string $t): static        { $this->_flush(); $this->options['text']        = $t; return $this; }
    public function required(): static             { $this->_flush(); $this->options['required']    = true; return $this; }
    public function disabled(): static             { $this->_flush(); $this->options['disabled']    = true; return $this; }
    public function placeholder(string $p): static { $this->_flush(); $this->options['placeholder'] = $p; return $this; }
    public function hint(string $h): static        { $this->_flush(); $this->options['hint']        = $h; return $this; }
    public function modalTitle(string $t): static  { $this->_flush(); $this->options['modalTitle']  = $t; return $this; }
    public function modalSize(string $s): static   { $this->_flush(); $this->options['modalSize']   = $s; return $this; }

    /**
     * Botão "Novo" ao lado da lupa (e no topo do modal): abre o cadastro
     * ('Classe::metodo' ou 'Classe') por cima da tela; o form alvo salva e o
     * `returnToCombo()` dele devolve o registro selecionado a este campo.
     */
    public function create(string $action, string $label = '', string $icon = ''): static
    {
        $this->_flush();
        $this->options['create'] = $action;
        if ($label !== '') $this->options['createLabel'] = $label;
        if ($icon !== '')  $this->options['createIcon']  = $icon;
        return $this;
    }

    // ── Opções do grid ────────────────────────────────────────────────────

    public function perPage(int $n): static        { $this->_flush(); $this->options['perPage']  = $n; return $this; }
    public function handler(string $c): static     { $this->_flush(); $this->options['handler']  = $c; return $this; }
    public function database(string $db): static   { $this->_flush(); $this->options['database'] = $db; return $this; }

    /**
     * Fonte filtrada via Eloquent/Query Builder (builder-native).
     * Compila SQL+bindings (toBase aplica soft-delete) e embarca no gridConfig;
     * o grid lista de uma derived table. `model` segue valendo p/ onSelect/colunas.
     */
    public function query($builder): static
    {
        $this->_flush();
        [$sql, $binds] = \Mad\Database\QuerySource::compileSql($builder);
        $this->options['query_sql']      = $sql;
        $this->options['query_bindings'] = $binds;
        if ($db = \Mad\Database\QuerySource::connectionName($builder)) {
            $this->options['database'] = $db;
        }
        return $this;
    }

    /**
     * Campo auxiliar: preenche um campo da tela ao selecionar registro.
     *
     * @param string $targetField Nome do campo na tela (ex: 'estado_nome')
     * @param string $sourcePath  Caminho no modelo (ex: 'email', 'cidade->estado->nome')
     */
    public function auxiliary(string $targetField, string $sourcePath): static
    {
        $this->_flush();
        $this->options['auxiliaries'][$targetField] = $sourcePath;
        return $this;
    }

    // ── Definição de colunas ──────────────────────────────────────────────

    public function column(string $field, string $label): SeekColumnBuilder
    {
        $this->_flush();
        $this->_pendingColumn = new SeekColumnBuilder($this, $field, $label);
        return $this->_pendingColumn;
    }

    // ── Definição de ações ────────────────────────────────────────────────

    public function action(string $method, string $icon = '', string $label = ''): SeekActionBuilder
    {
        $this->_flush();
        $this->_pendingAction = new SeekActionBuilder($this, $method, $icon, $label);
        return $this->_pendingAction;
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

        return MadSeek::_renderFromConfig($config);
    }

    public function __toString(): string
    {
        return $this->render();
    }
}

// ============================================================================
//  SeekColumnBuilder — métodos fluent de coluna
// ============================================================================

class SeekColumnBuilder
{
    private SeekBuilder $seek;
    private array       $cfg;

    public function __construct(SeekBuilder $seek, string $field, string $label)
    {
        $this->seek = $seek;
        $this->cfg  = ['field' => $field, 'label' => $label];
    }

    public function _config(): array { return $this->cfg; }

    /** Largura: int (px) ou string ('10%', '80px'). */
    public function w(int|string $width): static
    {
        $this->cfg['width'] = is_int($width) ? "{$width}px" : $width;
        return $this;
    }

    public function sort(): static          { $this->cfg['sortable']  = true;     return $this; }
    public function center(): static        { $this->cfg['align']     = 'center'; return $this; }
    public function right(): static         { $this->cfg['align']     = 'right';  return $this; }
    public function hide(): static          { $this->cfg['hidden']    = true;     return $this; }
    public function total(string $f): static{ $this->cfg['totalFunc'] = $f;       return $this; }

    public function filter(string $type = 'text', array $opts = []): static
    {
        $this->cfg['filterable'] = true;
        $this->cfg['filterType'] = $type;
        $this->cfg['filterOpts'] = $opts;
        return $this;
    }

    public function badge(array $map = []): static
    {
        $this->cfg['renderType'] = 'badge';
        $this->cfg['badgeMap']   = $map;
        return $this;
    }

    public function money(string $prefix = ''): static
    {
        $this->cfg['renderType']  = 'money';
        $this->cfg['moneyPrefix'] = $prefix;
        return $this;
    }

    public function num(int $decimals = 2): static
    {
        $this->cfg['renderType']     = 'number';
        $this->cfg['numberDecimals'] = $decimals;
        return $this;
    }

    public function date(string $format = 'd/m/Y'): static
    {
        $this->cfg['renderType'] = 'date';
        $this->cfg['dateFormat'] = $format;
        return $this;
    }

    public function html(): static
    {
        $this->cfg['renderType'] = 'html';
        return $this;
    }

    public function transform(string $fn): static
    {
        $this->cfg['transform'] = $fn;
        return $this;
    }

    // ── Delegação ao SeekBuilder ─────────────────────────────────────────

    public function column(string $f, string $l): SeekColumnBuilder              { return $this->seek->column($f, $l); }
    public function action(string $m, string $i = '', string $l = ''): SeekActionBuilder { return $this->seek->action($m, $i, $l); }
    public function name(string $n): SeekBuilder        { return $this->seek->name($n); }
    public function label(string $l): SeekBuilder       { return $this->seek->label($l); }
    public function display(string $d): SeekBuilder     { return $this->seek->display($d); }
    public function value(string $v): SeekBuilder       { return $this->seek->value($v); }
    public function text(string $t): SeekBuilder        { return $this->seek->text($t); }
    public function perPage(int $n): SeekBuilder        { return $this->seek->perPage($n); }
    public function handler(string $c): SeekBuilder     { return $this->seek->handler($c); }
    public function database(string $db): SeekBuilder   { return $this->seek->database($db); }
    public function query($b): SeekBuilder              { return $this->seek->query($b); }
    public function required(): SeekBuilder             { return $this->seek->required(); }
    public function disabled(): SeekBuilder             { return $this->seek->disabled(); }
    public function auxiliary(string $f, string $t): SeekBuilder { return $this->seek->auxiliary($f, $t); }
    public function create(string $a, string $l = '', string $i = ''): SeekBuilder { return $this->seek->create($a, $l, $i); }

    public function render(): string     { return $this->seek->render(); }
    public function __toString(): string { return $this->seek->render(); }
}

// ============================================================================
//  SeekActionBuilder — métodos fluent de ação
// ============================================================================

class SeekActionBuilder
{
    private SeekBuilder $seek;
    private array       $cfg;

    public function __construct(SeekBuilder $seek, string $method, string $icon, string $label)
    {
        $this->seek = $seek;
        $this->cfg  = ['method' => $method, 'icon' => $icon, 'label' => $label];
    }

    public function _config(): array { return $this->cfg; }

    public function primary(): static          { $this->cfg['primary'] = true;  return $this; }
    public function danger(): static           { $this->cfg['danger']  = true;  return $this; }
    public function confirm(string $msg): static { $this->cfg['confirm'] = $msg; return $this; }
    public function idField(string $f): static { $this->cfg['idField'] = $f;    return $this; }

    // ── Delegação ao SeekBuilder ─────────────────────────────────────────

    public function column(string $f, string $l): SeekColumnBuilder              { return $this->seek->column($f, $l); }
    public function action(string $m, string $i = '', string $l = ''): SeekActionBuilder { return $this->seek->action($m, $i, $l); }
    public function name(string $n): SeekBuilder        { return $this->seek->name($n); }
    public function label(string $l): SeekBuilder       { return $this->seek->label($l); }
    public function display(string $d): SeekBuilder     { return $this->seek->display($d); }
    public function value(string $v): SeekBuilder       { return $this->seek->value($v); }
    public function text(string $t): SeekBuilder        { return $this->seek->text($t); }
    public function perPage(int $n): SeekBuilder        { return $this->seek->perPage($n); }
    public function handler(string $c): SeekBuilder     { return $this->seek->handler($c); }
    public function database(string $db): SeekBuilder   { return $this->seek->database($db); }
    public function query($b): SeekBuilder              { return $this->seek->query($b); }
    public function create(string $a, string $l = '', string $i = ''): SeekBuilder { return $this->seek->create($a, $l, $i); }

    public function render(): string     { return $this->seek->render(); }
    public function __toString(): string { return $this->seek->render(); }
}