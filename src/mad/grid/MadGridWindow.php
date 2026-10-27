<?php
namespace Mad\Grid;

use Illuminate\Database\Eloquent\Builder;
use Mad\Http\MadResponse;
use Mad\Http\MadStateCrypt;

/**
 * Relatório por janelas (#439): o relatório sem paginação (`per-page="0"`)
 * grande abre com as primeiras linhas e carrega o resto ao rolar.
 *
 * Antes a tela lia e desenhava o resultado inteiro numa resposta só: 30 mil
 * lançamentos eram 463 MB no servidor e 108 MB de HTML — com 128M a tela nem
 * abria, e a #437 passou a recusar e pedir filtro. Aqui cada resposta custa o
 * mesmo, qualquer que seja o tamanho do relatório:
 *
 *  - a consulta é a da tela com as chaves da quebra NA FRENTE da ordenação
 *    (cada grupo chega inteiro e em sequência, como na exportação em fluxo) e
 *    a chave primária no fim;
 *  - os números da quebra (contagem e totais de cada grupo, total geral) saem
 *    de UMA passada em lotes (GridGroupStream em coleta → GridGroupTotals),
 *    feita a cada abertura (e a cada filtro/ordenação) e guardada no cache
 *    do app só para as janelas DESSA abertura — reabrir soma de novo, então
 *    um lançamento incluído entra nos subtotais;
 *  - as linhas saem em janelas de `reportWindowRows`: o motor de quebra
 *    (GridGroupStream) produz cabeçalho, linhas e subtotais exatamente como o
 *    `_flattenGroups()` + `_applyRunning()` de sempre, e o GridRowRenderer as
 *    desenha em HTML enxuto. O que atravessa as janelas (grupo aberto, saldo,
 *    rótulos) vai cifrado na linha-sentinela do fim do corpo;
 *  - o navegador pede a próxima janela (`onGridWindow`) quando a sentinela
 *    chega perto da tela (mad-ui.js), e a op `grid_window` (mad.js) a insere.
 *
 * Só o relatório SÓ leitura entra: com ações, seleção, edição na célula,
 * destaque, linha expansível, cartões, `sourceQuery()` ou gancho de carga do
 * app, a grade segue a carga em lotes da #437.
 */
trait MadGridWindow
{
    /**
     * Linhas por janela (mínimo 100, o menor lote da leitura em lotes).
     * Relatório com até isso de linhas abre inteiro, como sempre; acima, abre
     * com as primeiras e carrega o resto ao rolar. 0 desliga o modo por janela.
     */
    protected int $reportWindowRows = 300;

    /**
     * Linhas por lote da passada que soma a quebra pelo model. A passada
     * enxuta (só as colunas da chave, do total e do saldo) usa lotes 5×
     * maiores: cada lote é uma consulta que o banco reordena.
     */
    protected int $reportWindowBatch = 2000;

    /** Janela pendente desta requisição: ['total' => N] da carga; o render a desenha. */
    private ?array $_window = null;

    /** A carga é a que antecede uma ação do app: ela pode ler as linhas, então lê tudo. */
    private bool $_windowFullLoad = false;

    /** Total do relatório que a ação do app leu inteiro: o render volta às janelas. */
    private ?int $_windowHeld = null;

    /**
     * A requisição é a de uma janela (onGridWindow): o handler redesenha o
     * componente depois da ação mesmo sem render completo, e esse HTML não
     * vai ao navegador. Sem esta marca o redesenho abria o relatório de novo
     * (passada inteira da quebra + 1ª janela) a cada janela.
     */
    private bool $_windowOnlyOp = false;

    /**
     * Chamado pelo loadData() no lugar da carga em lotes: relatório grande e
     * só leitura não lê linha nenhuma agora — só conta. O render desenha a 1ª
     * janela (`_windowRender`).
     *
     * @param GridColumn[] $columns
     * @param-out int|null $counted o total, quando contou (a carga da #437 reaproveita)
     */
    private function _windowDefer(Builder $q, array $columns, ?int &$counted): bool
    {
        $this->_window     = null;
        $this->_windowHeld = null;
        if ($this->reportWindowRows <= 0 || $this->_loadColumns !== null || !$this->_windowAllowed($columns)) {
            return false;
        }

        $countQ = clone $q;
        $countQ->getQuery()->orders = null;
        $countQ->getQuery()->bindings['order'] = [];
        $counted = (int) $countQ->count();
        // Até uma janela abre inteiro; acima do teto declarado a tela pede filtro, como antes.
        if ($counted <= $this->_windowSize() || ($this->reportMaxRows > 0 && $counted > $this->reportMaxRows)) {
            return false;
        }
        if ($this->_windowFullLoad) {
            // Ação do app (botão do cabeçalho): ela enxerga as linhas da tela,
            // como sempre — carga em lotes da #437. O render depois dela volta
            // às janelas (`_windowRender`).
            $this->_windowHeld = $counted;
            return false;
        }

        $this->_window          = ['total' => $counted];
        $this->_rows            = [];
        $this->_rawItems        = [];
        $this->_evaluated       = [];
        $this->_groupLabelCache = [];
        $this->_total           = $counted;
        $this->_rowsBatched     = true;

        return true;
    }

    /**
     * A grade pode abrir por janelas? Relatório só leitura, consulta do
     * próprio framework. No AJAX de um `<mad-grid self>` as ações e o destaque
     * do Blade ainda não chegaram: vale o que o último render decidiu.
     *
     * @param GridColumn[] $columns
     */
    private function _windowAllowed(array $columns): bool
    {
        if ($this->perPage > 0 || $this->selectable || $this->_usesSource() || !empty($this->exportMeta['windowOff'])
            || $this->_keylessModel()) {
            return false;
        }
        if ($this->_inlineConfig === null && !empty($this->exportColConfigs)) {
            return !empty($this->exportMeta['window']);
        }
        if (!empty($this->_inlineConfig['cardView'])) {
            return false;
        }

        $visible = $this->_windowVisible($columns);
        if (!GridRowRenderer::supports($visible, $this->_effectiveActions() !== [] || $this->_effectiveActionGroups() !== [],
            false, $this->_effectiveRowExpand() !== null)) {
            return false;
        }

        return $this->_effectiveRowHighlights() === [];
    }

    /** @param GridColumn[] $columns @return GridColumn[] as colunas que a tela desenha */
    private function _windowVisible(array $columns): array
    {
        return array_values(array_filter($columns, fn ($c) => $c instanceof GridColumn && !$c->hidden && $c->checkDisplay()));
    }

    /**
     * No render: a 1ª janela, ou null (fora do modo — a carga da #437 roda
     * agora, com o Blade inteiro em mãos).
     *
     * @param GridColumn[] $columns
     * @return array{html:string, more:?string, totals:array, sortWithinGroup:bool}|null
     */
    private function _windowRender(array $columns): ?array
    {
        $this->exportMeta['window'] = $this->_windowAllowed($columns);
        if ($this->_window === null && $this->_windowHeld !== null) {
            // A ação do app já usou as linhas: a tela continua por janelas.
            $this->_window      = ['total' => $this->_windowHeld];
            $this->_windowHeld  = null;
            $this->_rows        = [];
            $this->_rawItems    = [];
            $this->_tooManyRows = 0;
            $this->_rowsBatched = true;
        }
        if ($this->_window === null) {
            return null;
        }
        $total = (int) $this->_window['total'];
        if ($this->_windowOnlyOp && $this->exportMeta['window']) {
            // Redesenho de bastidor da requisição de uma janela: corpo vazio.
            return ['html' => '', 'more' => null, 'totals' => [], 'sortWithinGroup' => false];
        }

        $built = $this->exportMeta['window'] ? $this->_windowFirst($columns) : null;
        if ($built === null) {
            // Não deu para abrir por janelas (o Blade trouxe ação/seleção, a
            // quebra não ordena no banco, grupo que reaparece): carga de sempre.
            $this->_window      = null;
            $this->_rowsBatched = false;
            if (($q = $this->_reportQuery()) !== null) {
                $this->_loadUnpaged($q, $columns, $total);
            }
            return null;
        }

        return $built;
    }

    /**
     * Abre a 1ª janela: mapa da quebra (cache ou passada nova) e as primeiras
     * linhas.
     *
     * @param GridColumn[] $columns
     */
    private function _windowFirst(array $columns): ?array
    {
        $q = $this->_exportStreamQuery();
        if ($q === null) {
            return null;
        }
        $fp   = $this->_windowFingerprint($q, $columns);
        $map  = $this->_windowCollect($q, $columns);
        if ($map === null) {
            return null;
        }
        $open = bin2hex(random_bytes(8));
        $this->_windowKeep($fp, $open, $map);

        $slice = $this->_windowSlice($q, $columns, $map, 0, []);
        if ($slice === null) {
            return null;
        }
        $this->_rows  = $slice['rows'];
        $this->_total = $map->rows();

        return [
            'html'   => $slice['html'],
            'more'   => $slice['more'] ? $this->_windowToken($fp, $open, $slice['next'], $slice['state']) : null,
            'totals' => $map->grandTotals(),
            'sortWithinGroup' => $this->_windowSortsWithinGroup(),
        ];
    }

    /**
     * A próxima janela, pedida pelo navegador quando a sentinela chega perto
     * da tela. Só devolve a op (`grid_window`): a grade não é redesenhada.
     * Token inválido: nenhuma op (o navegador desiste depois de 3 tentativas).
     *
     * O pedido leva o estado da tela do mesmo render que fez a sentinela; se
     * mesmo assim a consulta sai outra (ordenação ou filtro montados só no
     * mount(), ou um valor que muda a cada requisição, como now()), continuar
     * do deslocamento mostraria linhas de outra consulta. Aí a tela sai das
     * janelas e é redesenhada pela carga de sempre.
     */
    public function onGridWindow(string $token = '', string $gridKey = ''): MadResponse
    {
        $this->skipFullRender();
        $this->_windowOnlyOp = true;
        $response = new MadResponse();

        $cur = MadStateCrypt::decryptFor('grid-window', $token);
        if (!is_array($cur) || !isset($cur['fp'], $cur['off']) || ($this->_filterGateOn() && !$this->_hasUserFilter())) {
            return $response;
        }

        [$columns] = $this->_prepareExportColumns([]);
        $this->_detectRenderFields($columns);
        $q = $this->_exportStreamQuery();
        if ($q === null) {
            return $response;
        }
        if (!hash_equals((string) $cur['fp'], $this->_windowFingerprint($q, $columns))) {
            $this->exportMeta['windowOff'] = true;
            $this->_windowOnlyOp   = false;
            $this->_skipFullRender = false;
            $this->forceFullRender();
            static::_warnOnce(
                'report-window-off',
                'relatório por janelas desligado nesta tela: a consulta mudou entre a abertura e a próxima janela '
                . '(ordenação/filtro definidos no mount() ou valor que muda a cada requisição). A grade voltou à carga inteira.'
            );

            return $response;
        }
        $open = (string) ($cur['k'] ?? '');
        $map  = $this->_windowMap($q, $columns, (string) $cur['fp'], $open);
        if ($map === null) {
            return $response;
        }
        $slice = $this->_windowSlice($q, $columns, $map, (int) $cur['off'], (array) ($cur['st'] ?? []));
        if ($slice === null) {
            return $response;
        }

        $response->ops[] = [
            'op'      => 'grid_window',
            'gridKey' => str_replace('\\', '_', static::class),
            'from'    => $token,
            'html'    => $slice['html'],
            'more'    => $slice['more'] ? $this->_windowToken((string) $cur['fp'], $open, $slice['next'], $slice['state']) : null,
        ];

        return $response;
    }

    /**
     * Uma janela: as linhas de [$offset, $offset + reportWindowRows) pelo
     * caminho de lotes da #437 (fórmulas, relações, delta do saldo), o motor
     * da quebra continuando do estado da janela anterior, e o HTML enxuto.
     *
     * @param GridColumn[] $columns
     * @return array{html:string, rows:array, state:array, next:int, more:bool}|null
     */
    private function _windowSlice(Builder $q, array $columns, GridGroupTotals $map, int $offset, array $state): ?array
    {
        $size = $this->_windowSize();
        $page = intdiv($offset, $size) + 1;
        $lean = !$this->_reportNeedsRecord($columns, true);

        $rows = [];
        foreach ($this->_reportBatches($q, $columns, $lean, $size, null, $page) as $batch) {
            $rows = $batch;
            break;
        }

        $engine = $this->_windowEngine($columns, $map, $state);
        $start  = $engine->delivered();
        $last   = $engine->lastRow();
        $items  = $engine->push($rows);
        $next   = $offset + count($rows);
        $more   = count($rows) === $size && $next < $map->rows();
        if (!$more) {
            array_push($items, ...$engine->finish());
        }

        // Cabeçalho de grupo sem contagem no mapa = a chave da passada não bate
        // com a da linha (fuso, cast): melhor a carga de sempre que um número errado.
        foreach ($items as $item) {
            if ($item['type'] === 'group' && $item['count'] === 0) {
                return null;
            }
        }

        $visible  = $this->_windowVisible($columns);
        GridColumn::assignShapes($visible, $rows);   // código/tipo inteiros nas linhas que chegam (fw#494b)
        GridColumn::assignCardRoles($visible, $this->_rowLookViewData()['gridCardColumns'], $rows);   // cartão do celular (fw#495)
        $renderer = new GridRowRenderer($visible, static::class . '_', $this->rowIdField());   // = $_rowPrefix do template
        $html     = $renderer->items($items, $start, $last, [
            'visibleColumns'  => $visible,
            'groupBandCells'  => $this->groupBand === 'cells',
            'hasGroupFooters' => $this->groupTotal,
            'selectable'      => false,
            'hasExpand'       => false,
            'hasActions'      => false,
            'actionSide'      => $this->actionSide,
        ]);

        return [
            'html'  => $html,
            'rows'  => array_values(array_map(fn ($i) => $i['data'], array_filter($items, fn ($i) => $i['type'] === 'row'))),
            'state' => $engine->state(),
            'next'  => $next,
            'more'  => $more,
        ];
    }

    /** Linhas por janela: o lote da leitura em lotes não fica abaixo de 100, e o deslocamento é contado em lotes. */
    private function _windowSize(): int
    {
        return max(100, $this->reportWindowRows);
    }

    /** @param GridColumn[] $columns */
    private function _windowEngine(array $columns, ?GridGroupTotals $map, array $state): GridGroupStream
    {
        $fields = $this->_groupFields();

        return new GridGroupStream(
            $columns,
            $fields,
            $fields === [] ? [] : $this->_groupMasks($fields),
            $this->groupTotal,
            $this->groupTotalLabel,
            $this->rowDetail,
            fn (string $mask, array $row) => static::_renderMask($mask, $row),
            fn (GridColumn $col, array $row) => static::_runningDelta($col, $row),
            fn (GridColumn $col, array $row) => static::_runningStart($col, $row),
            $map,
            $state
        );
    }

    /**
     * Contagem e totais de cada grupo, para uma janela seguinte: os da
     * abertura que fez a sentinela (cache), para cabeçalho e subtotal baterem
     * com a 1ª janela. Sumiu do cache (expirou, cache trocado): soma de novo.
     * null = algum grupo reaparece depois de fechado — nenhuma janela.
     *
     * @param GridColumn[] $columns
     */
    private function _windowMap(Builder $q, array $columns, string $fp, string $open): ?GridGroupTotals
    {
        try {
            $cached = $open !== '' ? cache()->get(self::_windowCacheKey($fp, $open)) : null;
        } catch (\Throwable $e) {
            $cached = null;
        }
        if (is_array($cached)) {
            return GridGroupTotals::fromExport($columns, $cached);
        }

        $map = $this->_windowCollect($q, $columns);
        if ($map !== null && $open !== '') {
            $this->_windowKeep($fp, $open, $map);
        }

        return $map;
    }

    /** Guarda o mapa da abertura para as janelas dela (1 h). */
    private function _windowKeep(string $fp, string $open, GridGroupTotals $map): void
    {
        try {
            cache()->put(self::_windowCacheKey($fp, $open), $map->export(), 3600);
        } catch (\Throwable $e) {
            // Sem cache: cada janela soma de novo (uma passada, nunca o conjunto).
        }
    }

    private static function _windowCacheKey(string $fp, string $open): string
    {
        return 'mad-grid-window:' . $fp . ':' . $open;
    }

    /**
     * A passada que soma a quebra. Enxuta quando dá — sem model, só as colunas
     * que chave, total e saldo leem (30 mil linhas: 0,3 s, ~1 MB) —, senão
     * pelos mesmos lotes da tela (fórmula, relação, cast, accessor).
     *
     * @param GridColumn[] $columns
     */
    private function _windowCollect(Builder $q, array $columns): ?GridGroupTotals
    {
        $engine = $this->_windowEngine($columns, null, []);
        $map    = $engine->collect();
        $size   = max(100, $this->reportWindowBatch);

        $select = $this->_windowLeanSelect($q, $columns);
        $done   = false;
        if ($select !== null) {
            $lean = $size * 5;
            try {
                $base = (clone $q)->toBase();
                $base->columns = null;
                $base->select($select);
                for ($page = 1; ; $page++) {
                    // cursor(): o lote vira só arrays (sem a coleção de objetos ao lado).
                    $chunk = [];
                    foreach ((clone $base)->forPage($page, $lean)->cursor() as $o) {
                        $chunk[] = (array) $o;
                    }
                    if ($chunk !== []) {
                        $engine->push($chunk);
                    }
                    if (count($chunk) < $lean) {
                        break;
                    }
                }
                $done = true;
            } catch (\Throwable $e) {
                // Coluna que não existe na tabela (atributo do Model): lotes da tela.
                $engine = $this->_windowEngine($columns, null, []);
                $map    = $engine->collect();
            }
        }
        if (!$done) {
            foreach ($this->_reportBatches($q, $columns, true, $size) as $batch) {
                $engine->push($batch);
            }
        }
        $engine->finish();

        return $engine->contiguous() ? $map : null;
    }

    /**
     * As colunas da passada enxuta, ou null quando algum valor só existe no
     * model: chave da quebra por relação, fórmula, saldo que começa de uma
     * expressão, atributo com accessor ou cast que muda o valor.
     *
     * @param GridColumn[] $columns
     * @return string[]|null
     */
    private function _windowLeanSelect(Builder $q, array $columns): ?array
    {
        $model  = $q->getModel();
        $fields = [];
        foreach ($this->_groupFields() as $g) {
            $fields[] = GridRenderHelpers::splitGroupGrain((string) $g)[0];
        }
        foreach ($columns as $col) {
            if (!$col instanceof GridColumn || (empty($col->totalFunc) && !$col->isRunning())) {
                continue;
            }
            if ($col->evaluate !== '') {
                return null;
            }
            if (!$col->isRunning()) {
                $fields[] = $col->field;
                continue;
            }
            // Saldo: a coluna é calculada; a passada lê o que a expressão lê
            // (`self` = o próprio campo).
            $start = trim($col->runningStart);
            if ($start !== '' && !is_numeric($start)) {
                return null;
            }
            if ($col->running === 'self' || $col->running === '') {
                $fields[] = $col->field;
            } elseif (preg_match_all('/\{([^}]+)\}/', $col->running, $m)) {
                array_push($fields, ...$m[1]);
            } else {
                return null;
            }
        }

        $okCasts = ['int', 'integer', 'float', 'double', 'real', 'string', 'date', 'immutable_date'];
        $casts   = $model->getCasts();
        $select  = [$model->getQualifiedKeyName()];
        foreach (array_unique($fields) as $f) {
            $f = GridRenderHelpers::stripBraces(trim((string) $f));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $f)) {
                return null;
            }
            if ($model->hasGetMutator($f) || $model->hasAttributeMutator($f)) {
                return null;
            }
            $cast = strtolower((string) ($casts[$f] ?? ''));
            if ($cast !== '' && !in_array($cast, $okCasts, true) && !str_starts_with($cast, 'decimal')) {
                return null;
            }
            $select[] = $model->qualifyColumn($f);
        }

        return array_values(array_unique($select));
    }

    /**
     * Impressão da consulta e de tudo que entra na conta da quebra: muda o
     * filtro, a busca, a ordenação, a quebra ou o total → mapa novo.
     *
     * @param GridColumn[] $columns
     */
    private function _windowFingerprint(Builder $q, array $columns): string
    {
        $cols = [];
        foreach ($columns as $col) {
            if ($col instanceof GridColumn) {
                $cols[] = [$col->field, $col->totalFunc, $col->running, $col->runningReset, $col->runningStart, $col->evaluate];
            }
        }

        return hash('sha256', json_encode([
            static::class,
            $q->toSql(),
            $q->getBindings(),
            $cols,
            $this->_groupFields(),
            session('userid'),
            $this->_windowSize(),
        ]) ?: '');
    }

    /** @param string $open a abertura (chave do mapa da quebra no cache) */
    private function _windowToken(string $fp, string $open, int $offset, array $state): string
    {
        return MadStateCrypt::encryptFor('grid-window', ['fp' => $fp, 'k' => $open, 'off' => $offset, 'st' => $state]);
    }

    /**
     * Ordenação do usuário por coluna fora da quebra: no modo por janela ela
     * vale DENTRO de cada grupo (os grupos seguem a ordem da quebra). O
     * cabeçalho da coluna diz isso.
     */
    private function _windowSortsWithinGroup(): bool
    {
        $fields = $this->_groupFields();
        if ($fields === [] || $this->sortBy === '') {
            return false;
        }
        $sorted = GridRenderHelpers::unbraceChain($this->sortBy);
        foreach ($fields as $g) {
            if (GridRenderHelpers::unbraceChain(GridRenderHelpers::splitGroupGrain((string) $g)[0]) === $sorted) {
                return false;
            }
        }

        return true;
    }
}
