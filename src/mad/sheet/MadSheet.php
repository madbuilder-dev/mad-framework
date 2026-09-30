<?php
namespace Mad\Sheet;

use Illuminate\Support\Facades\DB;
use Mad\Component\MadComponent;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;

/**
 * MadSheet — planilha de lançamento em lote (entry-first).
 *
 * Diferente do MadDataGrid (grid-first: lista registros existentes e edita
 * inline), o MadSheet é digitação-first: uma grade vazia estilo spreadsheet
 * para entrada intensiva de dados (lançamento contábil, contagem de
 * inventário, orçamento), com navegação por teclado, paste do Excel,
 * fill-down e validação/persistência em lote transacional.
 *
 * ┌─ Como usar (declarativo) ───────────────────────────────────────────────┐
 * │                                                                         │
 * │  <mad-sheet model="StockCount" database="business" rows-min="10">       │
 * │      <mad-sheet-col field="produto_id" type="combo" source="Produto"   │
 * │                     source-label="nome" label="Produto" required />     │
 * │      <mad-sheet-col field="quantidade" type="number" total="sum" />     │
 * │      <mad-sheet-col field="valor" type="money" total="sum" />           │
 * │      <mad-sheet-col field="data" type="date" />                          │
 * │  </mad-sheet>                                                            │
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Ou por subclasse (SheetDevView é o exemplo vivo):
 *
 *   class LancamentoSheet extends MadSheet
 *   {
 *       protected string $model    = 'Lancamento';
 *       protected string $database = 'business';
 *       protected array  $columns  = [
 *           ['field' => 'conta', 'type' => 'text', 'required' => true],
 *           ['field' => 'valor', 'type' => 'money', 'total' => 'sum'],
 *       ];
 *   }
 *
 * Segurança:
 *   - model/source resolvidos via ModelOptionsLoader::resolveModelClass
 *     (registry — nunca classe crua vinda do request);
 *   - só campos DECLARADOS nas colunas entram no insert (payload extra é
 *     descartado); colunas readonly também são descartadas no save;
 *   - persistência via fill() → respeita $fillable/$guarded do model;
 *   - beforeSaveRow() é o seam pra tenant/unit/user scoping da subclasse.
 */
class MadSheet extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    // ── Configuração (subclasse ou <mad-sheet> declarativo) ──────────────

    /** Model Eloquent alvo dos inserts (short name ou FQCN). */
    protected string $model = '';

    /** Connection. Default: MAIN_DATABASE. */
    protected string $database = '';

    /** Configs de coluna (array de arrays — ver SheetColumn::fromConfig). */
    protected array $columns = [];

    /** Linhas em branco iniciais da grade. */
    protected int $rowsMin = 8;

    /** Guard de payload: máximo de linhas aceitas num batch. */
    protected int $maxRows = 2000;

    /** Exibe a linha de totais (colunas com total="sum|count"). */
    protected bool $showTotals = true;

    /**
     * Chave do cache de sessão da config inline desta sheet. Pública de
     * propósito: entra no state do wire, então cada sheet hidrata a PRÓPRIA
     * config (mesmo padrão do MadKanban::$kbCfgKey).
     */
    public string $shCfgKey = '';

    /** VOs de coluna (cache por request). */
    private ?array $_columnVos = null;

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function hydrate(): void
    {
        // O state do wire só carrega props públicas — a config declarativa
        // (model/columns) vem dos attrs do <mad-sheet> e é cacheada em sessão
        // pelo _renderInlineSheet. Restaura antes de qualquer action.
        $cfg = $this->shCfgKey !== ''
            ? session('mad_sheet_cfg.' . $this->shCfgKey)
            : null;
        if (!is_array($cfg)) {
            $latest = session('mad_sheet_cfg_latest.' . static::class);
            $cfg    = is_string($latest) && $latest !== '' ? session('mad_sheet_cfg.' . $latest) : null;
        }
        if (is_array($cfg)) {
            $this->_applyInlineSheetConfig($cfg);
        }
    }

    protected function view(): string|array
    {
        return ['components.sheet', ['__component' => $this]];
    }

    // ── Render inline (MadSheetCompiler) ──────────────────────────────────

    public function _renderInlineSheet(array $config): string
    {
        $this->_applyInlineSheetConfig($config);

        $this->shCfgKey = md5(static::class . '|' . json_encode(array_intersect_key($config, array_flip([
            'model', 'database', 'columns', 'rowsMin', 'maxRows', 'showTotals',
        ]))));
        session([
            'mad_sheet_cfg.' . $this->shCfgKey       => $config,
            'mad_sheet_cfg_latest.' . static::class   => $this->shCfgKey,
        ]);

        return \Mad\View\MadBlade::render('components.sheet', ['__component' => $this]);
    }

    protected function _applyInlineSheetConfig(array $config): void
    {
        foreach (['model', 'database'] as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (string) $config[$k];
            }
        }
        if (isset($config['columns']) && is_array($config['columns'])) {
            $this->columns    = $config['columns'];
            $this->_columnVos = null;
        }
        if (isset($config['rowsMin'])) {
            $this->rowsMin = max(1, (int) $config['rowsMin']);
        }
        if (isset($config['maxRows'])) {
            $this->maxRows = max(1, (int) $config['maxRows']);
        }
        if (isset($config['showTotals'])) {
            $this->showTotals = (bool) $config['showTotals'];
        }
    }

    // ── Colunas ───────────────────────────────────────────────────────────

    /** @return SheetColumn[] indexadas por field */
    public function getColumns(): array
    {
        if ($this->_columnVos !== null) {
            return $this->_columnVos;
        }
        $vos = [];
        foreach ($this->columns as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $col = SheetColumn::fromConfig($cfg);
            if ($col->field === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col->field)) {
                continue; // field é identificador de coluna — nada dinâmico passa
            }
            $vos[$col->field] = $col;
        }
        return $this->_columnVos = $vos;
    }

    /**
     * Config das colunas pro Alpine (options de combo já resolvidas; tokens
     * de busca/no-results construídos NO RENDER — nunca cacheados na sessão).
     */
    public function getClientColumns(): array
    {
        $out = [];
        foreach ($this->getColumns() as $col) {
            $options = [];
            try {
                $options = $col->resolveOptions($this->_db());
            } catch (\Throwable $e) {
                // model inválido: combo cai sem opções; o erro real estoura
                // na validação do save (mensagem por célula)
            }
            $searchToken = '';
            $payload = null;
            try {
                $searchToken = $col->buildSearchToken($this->_db());
            } catch (\Throwable $e) {
            }
            try {
                $payload = $col->buildNoResultsPayload($this->_db());
            } catch (\Throwable $e) {
            }
            $out[] = $col->toClientConfig($options, $searchToken, $payload);
        }
        return $out;
    }

    public function getRowsMin(): int   { return $this->rowsMin; }
    public function getMaxRows(): int   { return $this->maxRows; }
    public function hasTotals(): bool
    {
        if (!$this->showTotals) {
            return false;
        }
        foreach ($this->getColumns() as $col) {
            if ($col->total !== '') {
                return true;
            }
        }
        return false;
    }

    // ── Actions (wire) ────────────────────────────────────────────────────

    /** Valida o batch sem persistir (botão "Validar"). */
    public function onValidateBatch(string $rowsJson): MadResponse
    {
        [$rows, $err] = $this->_decodeRows($rowsJson);
        if ($err !== null) {
            return (new MadResponse())->toast($err, 'error');
        }

        ['errors' => $errors] = $this->_validateRows($rows);

        if (!empty($errors)) {
            return $this->_errorsResponse($errors);
        }

        return (new MadResponse())
            ->toast(mad_t('mad.sheet.valid_ok', ['count' => count($rows)]), 'success')
            ->script($this->_sheetEvent('mad-sheet:valid', ['count' => count($rows)]));
    }

    /** Persiste o batch numa transação única (rollback total em erro). */
    public function onSaveBatch(string $rowsJson): MadResponse
    {
        [$rows, $err] = $this->_decodeRows($rowsJson);
        if ($err !== null) {
            return (new MadResponse())->toast($err, 'error');
        }
        if (empty($rows)) {
            return (new MadResponse())->toast(mad_t('mad.sheet.nothing_to_save'), 'warning');
        }

        ['clean' => $clean, 'errors' => $errors] = $this->_validateRows($rows);

        if (!empty($errors)) {
            return $this->_errorsResponse($errors);
        }

        $modelClass = $this->_resolveModelFqcn($this->model);
        if ($modelClass === '' || !class_exists($modelClass)) {
            return (new MadResponse())->toast(mad_t('mad.sheet.model_missing'), 'error');
        }

        $saved = [];
        try {
            DB::connection($this->_db())->transaction(function () use ($modelClass, $clean, &$saved) {
                foreach ($clean as $index => $data) {
                    $data  = $this->beforeSaveRow($data, $index);
                    $model = new $modelClass();
                    $model->fill($data);
                    $model->save();
                    $saved[] = $model;
                }
            });
        } catch (\Throwable $e) {
            return (new MadResponse())
                ->toast(\Mad\Ui\MadUserError::isTechnical($e)
                    ? \Mad\Ui\MadUserError::message($e, mad_t('mad.error.save_failed'), static::class . '::onSaveBatch')
                    : mad_t('mad.sheet.save_failed', ['message' => $e->getMessage()]), 'error');
        }

        $this->afterSaveBatch($saved);

        return (new MadResponse())
            ->toast(mad_t('mad.sheet.saved', ['count' => count($saved)]), 'success')
            ->script($this->_sheetEvent('mad-sheet:saved', ['count' => count($saved)]));
    }

    // ── Hooks (subclasse) ─────────────────────────────────────────────────

    /**
     * Enriquece a linha antes do insert — seam para tenant/unit/user scoping.
     * Recebe apenas campos declarados+validados; o retorno vai pro fill().
     */
    protected function beforeSaveRow(array $row, int $index): array
    {
        return $row;
    }

    /** @param array $models modelos persistidos (mesma ordem do batch) */
    protected function afterSaveBatch(array $models): void
    {
    }

    // ── Validação ─────────────────────────────────────────────────────────

    /**
     * Valida as linhas contra as colunas declaradas.
     *
     * @return array{clean: array<int,array>, errors: array<int,array{row:int,field:string,msg:string}>}
     *         `clean` preserva o índice ORIGINAL da linha (mapeamento de erro
     *         no client usa esse índice).
     */
    protected function _validateRows(array $rows): array
    {
        $columns = $this->getColumns();
        $clean   = [];
        $errors  = [];

        // Membership de combos com source model: valida em lote (1 whereIn
        // por coluna) em vez de 1 exists() por célula.
        $comboSeen = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $data = [];
            foreach ($columns as $field => $col) {
                $value = $row[$field] ?? '';
                $value = is_scalar($value) ? trim((string) $value) : '';

                if ($col->readonly) {
                    continue; // nunca aceita valor de coluna readonly
                }

                if ($value === '') {
                    if ($col->required) {
                        $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.required', ['label' => $col->label])];
                    }
                    continue;
                }

                switch ($col->type) {
                    case 'number':
                    case 'money':
                        if (!is_numeric($value)) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.not_numeric', ['label' => $col->label])];
                            continue 2;
                        }
                        $num  = $value + 0;
                        $minB = $col->numericBoundary('min');
                        $maxB = $col->numericBoundary('max');
                        if ($minB !== null && $num < $minB) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.below_min', ['label' => $col->label, 'min' => $minB])];
                            continue 2;
                        }
                        if ($maxB !== null && $num > $maxB) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.above_max', ['label' => $col->label, 'max' => $maxB])];
                            continue 2;
                        }
                        if ($col->step !== null && $col->step > 0) {
                            $ratio = $num / $col->step;
                            if (abs($ratio - round($ratio)) > 1e-9) {
                                $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.bad_step', ['label' => $col->label, 'step' => $col->step])];
                                continue 2;
                            }
                        }
                        $data[$field] = $num;
                        break;

                    case 'date':
                        // Formato da coluna (database-mask custom suportada);
                        // '!' zera hora não presente na mask (comparação pura).
                        $fmt = SheetColumn::maskToPhp($col->databaseMask);
                        $dt  = \DateTime::createFromFormat('!' . $fmt, $value);
                        if (!$dt || $dt->format($fmt) !== $value) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.bad_date', ['label' => $col->label])];
                            continue 2;
                        }
                        $minD = $col->dateBoundary('min');
                        $maxD = $col->dateBoundary('max');
                        if (($minD && $dt < $minD) || ($maxD && $dt > $maxD)) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.date_out_of_range', ['label' => $col->label])];
                            continue 2;
                        }
                        $data[$field] = $value;
                        break;

                    case 'combo':
                        if (!empty($col->options)) {
                            if (!array_key_exists($value, $col->options)) {
                                $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.bad_option', ['label' => $col->label])];
                                continue 2;
                            }
                        } elseif ($col->model !== '') {
                            // Bucket por valor do PAI (cascade): membership do
                            // filho é validada DENTRO do subconjunto do pai.
                            $parent = $col->dependsOn !== ''
                                ? trim((string) ($row[$col->dependsOn] ?? ''))
                                : '';
                            $comboSeen[$field][$parent][$value][] = (int) $index;
                        }
                        $data[$field] = $value;
                        break;

                    default: // text
                        $value = $col->applyForceCase($value);
                        if ($col->mask !== '' && !$col->matchesMask($value)) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.bad_mask', ['label' => $col->label])];
                            continue 2;
                        }
                        if ($col->maxlength !== null && mb_strlen($value) > $col->maxlength) {
                            $errors[] = ['row' => (int) $index, 'field' => $field, 'msg' => mad_t('mad.sheet.too_long', ['label' => $col->label, 'max' => $col->maxlength])];
                            continue 2;
                        }
                        if ($col->stripMask) {
                            $value = preg_replace('/[^0-9A-Za-z]/', '', $value);
                        }
                        $data[$field] = $value;
                }
            }

            if (!empty($data)) {
                $clean[(int) $index] = $data;
            }
        }

        // Batch-check de combos com model: membership honra o where da coluna
        // e, com cascade, o filtro do pai — 1 whereIn por (coluna, pai
        // distinto). Cap de pais distintos evita explosão de queries num
        // batch patológico.
        $maxParents = 200;
        foreach ($comboSeen as $field => $byParent) {
            $col = $columns[$field];

            if (count($byParent) > $maxParents) {
                foreach ($byParent as $valuesMap) {
                    foreach ($valuesMap as $indices) {
                        foreach ($indices as $index) {
                            $errors[] = ['row' => $index, 'field' => $field, 'msg' => mad_t('mad.sheet.bad_option', ['label' => $col->label])];
                        }
                    }
                }
                continue;
            }

            foreach ($byParent as $parent => $valuesMap) {
                try {
                    $modelClass = ModelOptionsLoader::resolveModelClass($col->model);
                    $keyName = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col->keyField)
                        ? $col->keyField
                        : (new $modelClass())->getKeyName();
                    $q = $modelClass::query();
                    $col->applyWhere($q);
                    if ($col->dependsOn !== '' && (string) $parent !== '') {
                        $depCol = $col->dependsColumn !== '' ? $col->dependsColumn : $col->dependsOn;
                        $q->where($depCol, $parent);
                    }
                    $existing = $q->whereIn($keyName, array_keys($valuesMap))
                        ->pluck($keyName)
                        ->map(fn ($v) => (string) $v)
                        ->all();
                    $missing = array_diff(array_keys($valuesMap), $existing);
                } catch (\Throwable $e) {
                    $missing = array_keys($valuesMap); // model irresolúvel: tudo inválido
                }
                foreach ($missing as $value) {
                    foreach ($valuesMap[$value] as $index) {
                        $errors[] = ['row' => $index, 'field' => $field, 'msg' => mad_t('mad.sheet.bad_option', ['label' => $col->label])];
                        unset($clean[$index][$field]);
                        if (empty($clean[$index])) {
                            unset($clean[$index]);
                        }
                    }
                }
            }
        }

        // Linha com QUALQUER erro sai do clean — batch é tudo-ou-nada.
        foreach ($errors as $e) {
            unset($clean[$e['row']]);
        }

        return ['clean' => $clean, 'errors' => $errors];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @return array{0: array, 1: ?string} [rows, erro] */
    private function _decodeRows(string $rowsJson): array
    {
        $rows = json_decode($rowsJson, true);
        if (!is_array($rows)) {
            return [[], mad_t('mad.sheet.bad_payload')];
        }
        if (count($rows) > $this->maxRows) {
            return [[], mad_t('mad.sheet.too_many_rows', ['max' => $this->maxRows])];
        }
        return [$rows, null];
    }

    private function _errorsResponse(array $errors): MadResponse
    {
        return (new MadResponse())
            ->toast(mad_t('mad.sheet.has_errors', ['count' => count($errors)]), 'error')
            ->script($this->_sheetEvent('mad-sheet:errors', ['errors' => array_values($errors)]));
    }

    /** JS que despacha um CustomEvent no container desta sheet. */
    private function _sheetEvent(string $name, array $detail): string
    {
        $key    = json_encode($this->shCfgKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $event  = json_encode($name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $data   = json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        return "document.querySelectorAll('[data-mad-sheet=' + JSON.stringify({$key}) + ']')"
            . ".forEach(function (el) { el.dispatchEvent(new CustomEvent({$event}, { detail: {$data} })); });";
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
