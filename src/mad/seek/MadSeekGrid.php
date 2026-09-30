<?php
namespace Mad\Seek;
use Mad\Form\MadRecordPath;
use Mad\Grid\MadDataGrid;
use Mad\Grid\GridAction;
use Mad\Http\MadResponse;


/**
 * MadSeekGrid — Grid reativo para uso dentro do dbseek-field.
 *
 * Estende MadDataGrid com:
 *   - Config serializada no state (gridConfig)
 *   - Action embutida "Selecionar" que despacha evento JS
 *   - Delegação de ações customizadas ao handler
 *
 * Não deve ser instanciado diretamente — use MadSeek::of() ou <mad-seek>.
 */
class MadSeekGrid extends MadDataGrid
{
    protected static string $wrapper = self::INTERNAL;

    public array $gridConfig = [];

    /**
     * Classe COMPLETA no estado e no endpoint: o canal reativo só hidrata classe
     * que existe (`class_exists`) e `MadSeekGrid` sem namespace não existe no
     * app — o "Selecionar" respondia "Classe inválida: MadSeekGrid".
     */
    protected function _wireClassName(): string
    {
        return static::class;
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        if (!empty($params['config'])) {
            $this->gridConfig = $params['config'];
        }
        if (!empty($this->gridConfig['perPage'])) {
            $this->perPage = (int)$this->gridConfig['perPage'];
        }
        $this->_restoreConfig();
        parent::mount($params);
    }

    public function hydrate(): void
    {
        $this->_restoreConfig();
        parent::hydrate();
    }

    /**
     * Restaura propriedades não-serializadas a partir do gridConfig.
     */
    private function _restoreConfig(): void
    {
        if (!empty($this->gridConfig['model']))    $this->model    = $this->gridConfig['model'];
        if (!empty($this->gridConfig['database'])) $this->database = $this->gridConfig['database'];
        // order-by / :filters do <mad-seek> — as props declarativas do MadDataGrid
        // (mesmo mapeamento do MadGrid: antes do parent::mount e a cada hydrate).
        if (!empty($this->gridConfig['orderBy'])) $this->baseOrder = (string) $this->gridConfig['orderBy'];
        if (!empty($this->gridConfig['filters']) && is_array($this->gridConfig['filters'])) {
            $this->baseFilters = $this->gridConfig['filters'];
        }
    }

    /**
     * Auto-query do grid. Com :query (gridConfig['query_sql']) a listagem sai de
     * uma DERIVED TABLE (fromRaw); filtros/sort/paginação do grid aplicam por cima
     * via _buildQuery, 100% Query Builder (F4c-4). Sem :query, delega ao
     * _autoQuery builder-native do MadDataGrid. `model` segue valendo p/ onSelect.
     * SQL+bindings sobrevivem ao round-trip (gridConfig é serializável).
     */
    protected function _autoQuery(): array
    {
        $sql = (string) ($this->gridConfig['query_sql'] ?? '');
        if ($sql === '') {
            return parent::_autoQuery();
        }
        // Defesa em profundidade: gridConfig['query_sql'] vem de estado assinado
        // (mad_state) e não é mais assignável via mad_model (_MODEL_STRUCTURAL_BLOCKED).
        // Ainda assim, recusa empilhamento/comentário e cai no caminho nativo seguro
        // se o template for adulterado por qualquer canal futuro.
        if (preg_match('/;|\/\*|\*\/|\x00/', $sql)) {
            return parent::_autoQuery();
        }

        $binds = (array) ($this->gridConfig['query_bindings'] ?? []);
        $db    = $this->database ?: (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');

        // Fonte = derived table (fromRaw); as 7 camadas do grid aplicam por cima
        // via _buildQuery, 100% Query Builder nativo (Fase 4c-4).
        $base = \Illuminate\Support\Facades\DB::connection($db)
            ->query()->fromRaw("({$sql}) as mad_q", $binds);

        return $this->_runQuery($this->_buildQuery($base));
    }

    // ── Colunas e ações ────────────────────────────────────────────────────

    protected function columns(): array
    {
        return array_map(
            fn($c) => static::_colFromConfig($c),
            $this->gridConfig['colConfigs'] ?? []
        );
    }

    protected function actions(): array
    {
        // Ação embutida: "Selecionar"
        $selectAction = GridAction::make('onSelect')
            ->icon('check')
            ->label('Selecionar')
            ->primary();

        // Ações customizadas do config
        $customActions = array_values(array_filter(array_map(
            fn($a) => static::_actFromConfig($a),
            $this->gridConfig['actConfigs'] ?? []
        )));

        return array_merge([$selectAction], $customActions);
    }

    // ── Action: Selecionar registro ────────────────────────────────────────

    public function onSelect(int|string $id): MadResponse
    {
        $model        = $this->gridConfig['model']    ?? '';
        $displayField = $this->gridConfig['display']  ?? '';
        $seekName     = $this->gridConfig['seekName'] ?? '';
        $auxiliaries  = $this->gridConfig['auxiliaries'] ?? [];

        if (empty($model) || !class_exists($model)) {
            return (new MadResponse)->toast('Model não configurado.', 'danger');
        }

        try {
            $record  = $model::find($id);
            $row     = [];
            $display = '';
            $auxValues = [];

            if ($record) {
                $row = is_object($record) && method_exists($record, 'toArray')
                    ? $record->toArray()
                    : (array)$record;
                $display = $row[$displayField] ?? (string)$id;

                // Resolve campos auxiliares (path simples ou template com {path})
                foreach ($auxiliaries as $targetField => $sourcePath) {
                    $auxValues[$targetField] = self::_resolveSource($record, $sourcePath);
                }
            }
        } catch (\Throwable $e) {
            return (new MadResponse)->toast(\Mad\Ui\MadUserError::isTechnical($e)
                ? \Mad\Ui\MadUserError::message($e, mad_t('mad.error.load_failed'), static::class . '::onSelect')
                : 'Erro ao buscar registro: ' . $e->getMessage(), 'danger');
        }

        $detail = json_encode([
            'name'      => $seekName,
            'id'        => $id,
            'display'   => $display,
            'row'       => $row,
            'auxValues' => (object)$auxValues,
        ], JSON_UNESCAPED_UNICODE);

        return (new MadResponse)->script(
            "window.dispatchEvent(new CustomEvent('mad:seek-selected', { detail: {$detail} }))"
        );
    }

    /**
     * Resolve o source de um campo auxiliar (path simples · relação · template).
     *
     * Delega ao Mad\Form\MadRecordPath — fonte única compartilhada com o
     * auto-fill dos selects de banco (Mad\Service\MadAutoFillService).
     *
     * Formatos aceitos:
     *   'nome'                              → $record->nome (path simples)
     *   'cidade->estado->nome'              → $record->cidade->estado->nome (relacionamento)
     *   '{cidade->nome} - {cidade->estado->sigla}'  → template com múltiplos paths
     */
    private static function _resolveSource(object $record, string $source): string
    {
        return MadRecordPath::resolve($record, $source);
    }

    // ── Delegação de ações ao handler ──────────────────────────────────────

    public function __call(string $name, array $args): mixed
    {
        $actConfigs   = $this->gridConfig['actConfigs'] ?? [];
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
            "Método '{$name}' não encontrado no MadSeekGrid. "
            . "Configure ->handler('MinhaClasse') com o método '{$name}' público."
        );
    }

    // ── Fábrica ───────────────────────────────────────────────────────────

    public static function _renderFromConfig(array $config): string
    {
        $grid             = new static();
        $grid->gridConfig = $config;
        if (!empty($config['perPage'])) {
            $grid->perPage = (int)$config['perPage'];
        }
        $grid->boot();
        $grid->mount([]);
        return $grid->_renderWrapped();
    }
}