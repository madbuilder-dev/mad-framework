<?php
namespace Mad\Form;
use Mad\Http\MadResponse;


/**
 * MadDetailFormRef — Referência a um detail-form definido no Blade.
 *
 * Obtido via $this->form->getDetailForm('itens').
 * Fornece métodos para processar rows (validar, computar evaluate, responder).
 *
 * O model (classe Eloquent) é definido no Blade via <mad-detail-form model="DocReceitaItem">
 * e registrado automaticamente no MadFormRegistry. Quando presente, o evaluate
 * suporta lazy-load de relacionamentos: {produto->preco_venda}.
 *
 * Uso simples:
 *   return $this->form->getDetailForm('itens')->processRow($rowData, $editIndex);
 *
 * Com rules:
 *   return $this->form->getDetailForm('itens')
 *       ->rules(['ingrediente' => 'required'])
 *       ->processRow($rowData, $editIndex);
 *
 * Com lógica customizada:
 *   $df = $this->form->getDetailForm('itens');
 *   $errors = $df->validateRow($rowData);
 *   if ($errors) return $errors;
 *   $df->evaluateRow($rowData);
 *   return $df->addRow($rowData, $editIndex);
 */
class MadDetailFormRef
{
    private string $name;
    private string $model;
    private array  $columns;    // [ ['field'=>..., 'evaluate'=>..., 'label'=>...], ... ]
    private array  $rules = [];
    private string $database = 'business';

    public function __construct(string $name, array $columns = [], string $model = '')
    {
        $this->name    = $name;
        $this->columns = $columns;
        $this->model   = $model;
    }

    /**
     * Define regras de validação (illuminate/validation syntax).
     */
    public function rules(array $rules): static
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Define o database connection (padrão: 'business').
     */
    public function database(string $db): static
    {
        $this->database = $db;
        return $this;
    }

    /**
     * Valida uma row. Retorna MadResponse com dfFieldErrors se inválido, ou null se válido.
     */
    public function validateRow(array $rowData): ?MadResponse
    {
        if (empty($this->rules)) {
            return null;
        }

        $errors = MadValidator::validate($rowData, $this->rules);
        if (empty($errors)) {
            return null;
        }

        $response = new MadResponse();
        foreach ($errors as $field => $msg) {
            $response->dfFieldError($this->name, $field, $msg);
        }
        return $response;
    }

    /**
     * Computa os campos evaluate definidos nas colunas. Modifica $rowData in-place.
     *
     * Suporta:
     *   - Campos simples: {valor_unitario} * {quantidade}
     *   - Lazy-load (se model definido): {produto->preco_venda} * {quantidade}
     *
     * Quando a expressão contém '->' e existe um model, cria uma instância
     * temporária do record legado e usa o lazy-load do legado para resolver.
     */
    public function evaluateRow(array &$rowData): void
    {
        // Detecta se alguma expressão usa relacionamentos (->)
        $needsModel = false;
        foreach ($this->columns as $col) {
            if (!empty($col['evaluate']) && str_contains($col['evaluate'], '->')) {
                $needsModel = true;
                break;
            }
        }

        // Se precisa de model e temos um, cria instância temporária
        $record = null;
        if ($needsModel && $this->model) {
            try {
                $modelClass = $this->model;
                $record = new $modelClass();
                // Preenche o record temporário com os dados da row
                foreach ($rowData as $key => $val) {
                    if (!str_starts_with($key, '__')) {
                        $record->$key = $val;
                    }
                }
            } catch (\Throwable $e) {
                // O catch é legítimo (model inexistente vira Error, prop tipada
                // recusa o valor da row), mas a consequência não é neutra: sem
                // $record, TODA expressão `{rel->campo}` cai no ramo de campo
                // simples, não acha a chave e resolve '0' — a coluna calculada
                // (total, subtotal) é gravada como 0, indistinguível de um zero
                // real. Sem log não há como saber depois por que o total zerou.
                error_log('[MadDetailFormRef::evaluateRow] model "' . $this->model
                    . '" não instanciável no detail "' . $this->name . '" — colunas '
                    . 'calculadas com relação vão resolver 0: ' . $e->getMessage());
                $record = null;
            }
        }

        foreach ($this->columns as $col) {
            $expr = $col['evaluate'] ?? '';
            if (!$expr) continue;

            $field = $col['field'] ?? '';
            if (!$field) continue;

            $math = preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($rowData, $record) {
                $key = trim($m[1]);

                // Relacionamento via lazy-load: {produto->preco_venda}
                if (str_contains($key, '->') && $record) {
                    $val = $this->_resolveObjectPath($record, $key);
                    return is_numeric($val) ? (string)(float)$val : '0';
                }

                // Campo simples
                $val = $rowData[$key] ?? 0;
                return is_numeric($val) ? (string)(float)$val : '0';
            }, $expr);

            // Sanitiza e executa
            $safe = preg_replace('/[^0-9.+\-*\/() ]/', '', $math);
            $result = @eval("return ({$safe});");

            // Chave NUA, igual ao gêmeo JS (madDetailForm._applyEvaluates). O
            // `field` é template de exibição: gravar em `{total}` fazia o valor
            // calculado ser descartado no save — MadForm::isDisplayOnlyDetailKey
            // pula qualquer chave com `{`. A célula lia pelo fallback, então o
            // total aparecia na tela e sumia do banco, sem erro nenhum.
            $key = \Mad\Grid\GridRenderHelpers::rowDataKey($field);
            $rowData[$key] = (is_numeric($result) && is_finite((float)$result))
                ? (float)$result
                : 0;
        }
    }

    /**
     * Retorna MadResponse com op df_add (instrui o Alpine a inserir a row).
     */
    public function addRow(array $rowData, int $editIndex = -1): MadResponse
    {
        return (new MadResponse())->dfAdd($this->name, $rowData, $editIndex);
    }

    /**
     * Processa em um passo: valida → evalua → retorna dfAdd ou erros.
     *
     * Se chamado após validate($rules), lança MadValidationException (catch no controller).
     * Se chamado após rules($rules), retorna MadResponse com erros inline (sem throw).
     *
     * @throws MadValidationException se validate() foi usado (modo throw)
     */
    public function processRow(array $rowData, int $editIndex = -1): MadResponse
    {
        if (!empty($this->rules)) {
            $errors = MadValidator::validate($rowData, $this->rules);
            if ($errors) {
                throw new MadValidationException($errors, array_keys($this->rules), $this->name);
            }
        }

        $this->evaluateRow($rowData);

        return $this->addRow($rowData, $editIndex);
    }

    /**
     * Retorna MadResponse com op df_delete (instrui o Alpine a remover a row).
     */
    public function deleteRow(int $index): MadResponse
    {
        return (new MadResponse())->dfDelete($this->name, $index);
    }

    public function getName(): string    { return $this->name; }
    public function getModel(): string   { return $this->model; }
    public function getColumns(): array  { return $this->columns; }

    /**
     * Resolve um caminho de propriedade em um objeto: 'produto->tipo_produto->nome'
     * Usa o lazy-load do record legado (get_nome, get_tipo_produto, etc.)
     */
    private function _resolveObjectPath(object $obj, string $path): mixed
    {
        $parts = explode('->', $path);
        $current = $obj;

        foreach ($parts as $part) {
            $part = trim($part);
            if (!is_object($current)) return null;

            try {
                $current = $current->$part;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $current;
    }
}