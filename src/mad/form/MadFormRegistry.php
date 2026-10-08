<?php
namespace Mad\Form;
use Mad\Http\MadStateCrypt;
use Mad\Registry\MadVarRegistry;


/**
 * MadFormRegistry — Registro de campos de formulário para processamento estático.
 *
 * Durante o render do blade, cada componente de campo chama register().
 * Ao final do form, <mad-form-token> chama token() e emite um input hidden
 * com o schema criptografado.
 *
 * No controller, o método static onSave() usa fromRequest() para decodificar
 * o schema sem construir o formulário — ganho de performance significativo.
 *
 * Uso no controller:
 *   public static function onSave()
 *   {
 *       $schema = MadFormRegistry::fromRequest();
 *       $data   = MadFormRegistry::filterData($_POST, $schema);
 *       $errors = MadFormRegistry::validate($data, $schema);
 *       if ($errors) { ... }
 *       MadFormRegistry::fill($model, $data, $schema);
 *   }
 */
class MadFormRegistry
{
    /** @var array[] Campos registrados durante o render atual */
    private static array $fields = [];

    /** @var array[] Detail-forms registrados durante o render atual */
    private static array $detailForms = [];

    /** @var array[] Field-lists registrados durante o render atual: name => [field1, field2, ...] */
    private static array $fieldLists = [];

    /**
     * Colunas de arquivo (file/multifile) por detail (field-list ou detail-form).
     * Permite que o auto-save persista uploads por-linha vindos de
     * $_FILES['mad_fl_files'][detail__rowId__field].
     *
     *   ['anexos' => ['arquivo' => ['storage'=>'disk','folder'=>'uploads','fileName'=>'prefix','nameColumn'=>'arquivo_nome','multi'=>false]]]
     */
    private static array $detailFileColumns = [];

    /** @var string Chave secreta para AES-256-CBC */
    private static string $secret = '';

    // ── Configuração ──────────────────────────────────────────────────────────

    /**
     * Define a chave de criptografia.
     * Se não chamado, usa APP_KEY do ambiente ou um fallback fixo.
     */
    public static function setSecret(string $secret): void
    {
        self::$secret = $secret;
    }

    public static function getSecret(): string
    {
        if (self::$secret) {
            return self::$secret;
        }

        // 1) Fonte canônica: APP_KEY resolvido pelo Laravel (config('app.key')).
        //    AUTORITATIVO mesmo sob `php artisan config:cache` — cenário em que
        //    getenv('APP_KEY')/$_ENV ficam VAZIOS (o Dotenv não é recarregado).
        //    Usa a string crua (inclui prefixo base64:) para preservar a validade
        //    dos tokens já emitidos em deploys sem config:cache.
        $appKey = self::resolveAppKey();
        if ($appKey !== '') {
            return $appKey;
        }

        // 2) secret_key explícito em config/mad.php ([general] ou raiz).
        $ini = function_exists('mad_app_config') ? mad_app_config() : [];
        if (is_array($ini) && $ini) {
            $secret = $ini['secret_key'] ?? ($ini['general']['secret_key'] ?? '');
            if (is_string($secret) && $secret !== '') {
                return $secret;
            }
        }

        // 3) Seed do projeto — SOMENTE se for uma seed real. Rejeita os defaults
        //    públicos embarcados no template (a derivação com eles é adivinhável).
        $seed = is_array($ini) ? ($ini['general']['seed'] ?? ($ini['seed'] ?? '')) : '';
        $weakSeeds = ['', 's8dkld83kf73kf094', 'spike-dev-seed', 'spike-dev-token', 'spike-dev-rest-key'];
        if (is_string($seed) && !in_array($seed, $weakSeeds, true)) {
            $appName = defined('APPLICATION_NAME') ? APPLICATION_NAME : '';
            return hash('sha256', 'mad-state:' . $seed . ':' . $appName . ':' . __FILE__);
        }

        // 4) Nenhum segredo forte disponível. FAIL-CLOSED por padrão.
        //
        //    ALLOWLIST de ambientes de desenvolvimento, nunca denylist de
        //    produção. A denylist anterior (`in_array($env, ['production',
        //    'staging'])`) só reconhecia essas DUAS strings exatas: ambiente
        //    vazio — o que `config:cache` gerado com env vazia produz, e o que
        //    `APP_ENV=` sem valor produz, porque `env('APP_ENV','production')`
        //    devolve '' e não o default — mais 'prod', 'homolog', 'qa' e até
        //    'PRODUCTION' passavam batido e recebiam a chave fallback PÚBLICA,
        //    em silêncio.
        //
        //    Com chave pública o `mad_state` deixa de ser autenticado: quem lê
        //    o código do framework emite state válido, e com ele caem juntas a
        //    allowlist de escrita da grid (onInlineSave), a whitelist do ORDER
        //    BY (_isSortableField) e a de filtro (_declaredFilter) — todas
        //    confiam no state por ele ser inforjável. Abortar é mais seguro que
        //    operar comprometido.
        $envRaw = self::currentEnv();
        $env    = strtolower(trim($envRaw));

        if (!in_array($env, ['local', 'development', 'dev', 'testing', 'test'], true)) {
            throw new \RuntimeException(
                'MadFormRegistry: nenhuma chave de criptografia segura configurada (ambiente "'
                . ($envRaw !== '' ? $envRaw : '(vazio)') . '"). Defina APP_KEY '
                . '(php artisan key:generate) ou general.secret_key em config/mad.php. '
                . 'Recusando operar com chave fallback.'
            );
        }

        // 5) local/dev/testing: fallback fixo com aviso alto.
        if (function_exists('error_log')) {
            error_log('[MadFormRegistry] AVISO: usando chave de criptografia fallback (dev/local). '
                . 'Defina APP_KEY ou general.secret_key ANTES de ir a produção.');
        }
        return 'mad-form-registry-default-key-2024';
    }

    /**
     * Resolve o APP_KEY de forma segura a config:cache: prefere config('app.key')
     * (autoritativo quando o Dotenv não está carregado) e cai para o ambiente.
     * Retorna a string CRUA (mantém prefixo base64:) — igual ao valor usado
     * historicamente, para não invalidar tokens já emitidos.
     */
    private static function resolveAppKey(): string
    {
        if (function_exists('config')) {
            $key = (string) (config('app.key') ?? '');
            if ($key !== '') {
                return $key;
            }
        }
        $key = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
        return (string) $key;
    }

    /** Ambiente atual (config('app.env') → APP_ENV do ambiente). */
    private static function currentEnv(): string
    {
        if (function_exists('config')) {
            $env = (string) (config('app.env') ?? '');
            if ($env !== '') {
                return $env;
            }
        }
        return (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
    }

    // ── Ciclo de vida do render ───────────────────────────────────────────────

    /**
     * Limpa o registro. Chamar antes de renderizar o formulário.
     * Normalmente não precisa ser chamado manualmente — <mad-form-token>
     * gerencia isso automaticamente.
     */
    public static function reset(): void
    {
        self::$fields            = [];
        self::$detailForms       = [];
        self::$fieldLists        = [];
        self::$detailFileColumns = [];
    }

    /**
     * Registra um campo durante o render do componente.
     *
     * @param string $name     Nome do campo (atributo name do input)
     * @param string $type     Tipo do componente: input, select, textarea, switch, etc.
     * @param array  $props    Propriedades relevantes: required, maxlength, min, max, etc.
     */
    public static function register(string $name, string $type, array $props = []): void
    {
        if (!$name) {
            return;
        }
        // De onde saem as opções de uma seleção múltipla sobre tabela (Model e
        // coluna-chave): é da TELA, vai só para o estado cifrado — nunca para o
        // formulário `__mad_form`, que o navegador devolve (ver mais abaixo).
        $optionsSource = is_array($props['optionsSource'] ?? null) ? $props['optionsSource'] : [];
        unset($props['optionsSource']);

        // Remove props sem valor para manter o token enxuto
        $props = array_filter($props, fn($v) => $v !== '' && $v !== null && $v !== false);

        self::$fields[$name] = array_merge(['type' => $type], $props);

        // O formulário da tela fica sabendo que TEM este campo: é o que ele
        // aceita do navegador (MadForm::takesFromBrowser). O tipo vai junto: o
        // conteúdo de um Editor HTML é limpo pelo que a tela declarou, sem
        // depender do token (MadForm::cleanFromBrowser).
        // E fica sabendo ONDE o campo grava fora da coluna do registro (tabela
        // e chave estrangeira do Upload em outra tabela, tabela de ligação,
        // pasta): o formulário `__mad_form` é o navegador quem devolve, e o
        // Salvar só grava o campo descrito como a tela o desenhou
        // (MadForm::declareWrites).
        // E de que Model saem as opções de uma seleção múltipla sobre tabela: a
        // marca nova é conferida nessa consulta (MadForm::declareOptionsSource).
        foreach (self::screenForms() as $form) {
            $form->declareField($name, $type);
            $form->declareWrites($name, self::$fields[$name]);
            $form->declareOptionsSource($name, $optionsSource, (string) (self::$fields[$name]['separator'] ?? ''));
        }

        // Registra no VarRegistry para auto-bind de escalares
        MadVarRegistry::register($name, 'val');
    }

    // ── A tela que está declarando ────────────────────────────────────────────

    /** Tela cuja ação está rodando nesta requisição do wire (fora do render não há MadRenderContext). */
    private static ?\WeakReference $acting = null;

    /**
     * A tela desta requisição do wire. O campo `<mad-*>`, a lista e o diálogo
     * que a AÇÃO desenha (um trecho devolvido por `->html()`, um MadConfirm
     * com campos) são declarados no formulário dela — fora do render o
     * MadRenderContext está vazio.
     *
     * Referência fraca: não segura a tela viva depois da requisição (worker de
     * longa duração).
     *
     * @internal chamado por MadComponentHandler::process()
     */
    public static function actingComponent(?object $component): void
    {
        self::$acting = $component !== null ? \WeakReference::create($component) : null;
    }

    /**
     * Formulários da tela que está desenhando — ou, fora de um render, da
     * tela cuja ação está rodando.
     *
     * @return list<MadForm>
     */
    private static function screenForms(): array
    {
        $component = \Mad\Component\MadRenderContext::getComponent() ?? self::$acting?->get();
        if (!is_object($component)) {
            return [];
        }

        $forms = [];
        // Fora do escopo da classe: só as propriedades públicas já inicializadas.
        foreach (get_object_vars($component) as $value) {
            if ($value instanceof MadForm) {
                $forms[] = $value;
            }
        }

        return $forms;
    }

    /**
     * A tela que está desenhando — ou, fora de um render, a tela cuja ação
     * está rodando nesta requisição. Null fora de uma requisição de tela.
     *
     * @internal
     */
    public static function actingScreen(): ?object
    {
        $component = \Mad\Component\MadRenderContext::getComponent() ?? self::$acting?->get();

        return is_object($component) ? $component : null;
    }

    /**
     * A tag do campo `$name` traz a âncora do gerador (`data-mad-entity-id`):
     * a plataforma o criou para uma coluna da tabela `$entity`. É por ela que
     * o Salvar recusa o campo gerado cujo nome deixou de ser coluna, em vez de
     * descartar o valor — ver MadForm::_fieldsWithoutColumn().
     *
     * Devolve true para poder ser usado como valor de prop no Blade compilado.
     *
     * @internal chamado pelo Blade compilado (MadBladeCompiler::parseParams)
     */
    public static function anchored(string $name, int $entity): bool
    {
        foreach (self::screenForms() as $form) {
            $form->declareColumnField($name, $entity);
        }

        return true;
    }

    /**
     * Campos SÓ DE TELA, pelo que o Blade diz: o atributo `screen-only` da tag
     * e os campos que uma condição `*-when` usa. O valor deles não é do
     * registro — ver MadForm::screenOnly().
     *
     * @internal chamado pelo Blade compilado (MadBladeCompiler::parseParams)
     *
     * @param string|list<string> $names
     */
    public static function screenOnly(string|array $names): bool
    {
        $names = array_values(array_filter(array_map('strval', (array) $names), static fn ($n) => $n !== ''));
        if ($names) {
            foreach (self::screenForms() as $form) {
                $form->screenOnly(...$names);
            }
        }

        return true;
    }

    /**
     * Um campo que o CÓDIGO da tela está oferecendo ao navegador fora de uma
     * tag `<mad-*>` — o campo de um diálogo (MadConfirm::field).
     *
     * @internal
     */
    public static function declareScreenField(string $name): void
    {
        foreach (self::screenForms() as $form) {
            $form->declareField($name);
        }
    }

    /**
     * O `<mad-detail-fields>` de `$name` começa a ser desenhado: os campos
     * registrados até o fim dele são colunas da LINHA — o formulário da tela
     * não os toma por campos do registro principal.
     *
     * @internal chamado pelo Blade do detail-form
     */
    public static function beginDetailEditor(string $name): void
    {
        foreach (self::screenForms() as $form) {
            $form->beginDetailEditor($name);
        }
    }

    /** @internal fim do `<mad-detail-fields>` — `$html` é o que ele desenhou */
    public static function endDetailEditor(string $html): void
    {
        foreach (self::screenForms() as $form) {
            $form->endDetailEditor($html);
        }
    }

    /**
     * Colunas que `$name` (Lista de itens ou Detail Form) tem na tela, pelo
     * que foi registrado neste render — a grade e, quando já registrado, o
     * editor.
     *
     * @return list<string>
     */
    private static function detailColumnNames(string $name): array
    {
        if (isset(self::$fieldLists[$name])) {
            return array_values(array_filter(self::getFieldListFields($name), 'is_string'));
        }

        $names = [];
        foreach ((array) (self::$detailForms[$name]['columns'] ?? []) as $column) {
            $field = is_array($column) ? (string) ($column['field'] ?? '') : '';
            if ($field !== '') {
                $names[] = $field;
                $names[] = \Mad\Grid\GridRenderHelpers::rowDataKey($field);
            }
        }
        foreach ((array) (self::$detailForms[$name]['editor'] ?? []) as $field) {
            $names[] = (string) $field;
        }

        return $names;
    }

    /** Avisa o formulário da tela das colunas que `$name` tem (MadForm::declareDetail). */
    private static function declareDetail(string $name): void
    {
        $entry = self::$fieldLists[$name] ?? self::$detailForms[$name] ?? null;
        if (!$name || !is_array($entry)) {
            return;
        }
        foreach (self::screenForms() as $form) {
            $form->declareDetail(
                $name,
                self::detailColumnNames($name),
                (string) ($entry['model'] ?? ''),
                (string) ($entry['foreignKey'] ?? ''),
            );
        }
    }

    /**
     * Gera o token criptografado com o schema atual.
     * Retorna string base64 pronta para ser colocada em um input hidden.
     */
    public static function token(): string
    {
        if (empty(self::$fields) && empty(self::$fieldLists) && empty(self::$detailForms)) {
            return '';
        }

        $payload = self::$fields;

        // Embute metadata de details no token para uso no save (auto-save)
        if (!empty(self::$fieldLists)) {
            $payload['__mad_field_lists'] = self::$fieldLists;
        }
        if (!empty(self::$detailForms)) {
            $payload['__mad_detail_forms'] = self::$detailForms;
        }
        if (!empty(self::$detailFileColumns)) {
            $payload['__mad_detail_file_cols'] = self::$detailFileColumns;
        }

        return MadStateCrypt::encrypt($payload);
    }

    // ── Processamento no onSave ───────────────────────────────────────────────

    /**
     * Decodifica o schema a partir da requisição (POST/GET).
     * Retorna array com os campos registrados ou [] em caso de erro.
     *
     * @param string $field Nome do input hidden (padrão: __mad_form)
     */
    public static function fromRequest(string $field = '__mad_form'): array
    {
        $token = $_REQUEST[$field] ?? '';
        if (!$token) {
            return [];
        }
        $data = self::decode($token);

        // Restaura registries de details embutidos no token
        if (isset($data['__mad_field_lists'])) {
            self::$fieldLists = $data['__mad_field_lists'];
            unset($data['__mad_field_lists']);
        }
        if (isset($data['__mad_detail_forms'])) {
            self::$detailForms = $data['__mad_detail_forms'];
            unset($data['__mad_detail_forms']);
        }
        if (isset($data['__mad_detail_file_cols'])) {
            self::$detailFileColumns = $data['__mad_detail_file_cols'];
            unset($data['__mad_detail_file_cols']);
        }

        return $data;
    }

    /**
     * Props de UM campo no schema da requisição (`__mad_form`), só leitura.
     *
     * `fromRequest()` restaura de quebra os registries de details
     * (`$fieldLists`/`$detailForms`) a partir do token postado: certo no
     * começo da ação, errado no meio de um render — uma view que lesse
     * `$form->get()` trocaria o que o render atual já registrou. Aqui nada
     * muda. O token decifrado fica guardado enquanto for o MESMO token (um
     * só: em worker de longa duração não acumula).
     */
    public static function requestFieldProps(string $name, string $field = '__mad_form'): ?array
    {
        $token = $_REQUEST[$field] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }
        if (self::$requestSchemaToken !== $token) {
            self::$requestSchema      = self::decode($token);
            self::$requestSchemaToken = $token;
        }
        $props = self::$requestSchema[$name] ?? null;

        return is_array($props) ? $props : null;
    }

    /** @var array<string,mixed> schema decifrado do último token lido por requestFieldProps() */
    private static array $requestSchema = [];

    private static ?string $requestSchemaToken = null;

    /**
     * Data/hora no formato de EXIBIÇÃO, como `<mad-date-field>` e
     * `<mad-datetime-field>` postam: "24/09/2026", "24/09/2026 10:30[:00]".
     */
    public static function isDisplayDate(string $value): bool
    {
        return preg_match('~^\d{2}/\d{2}/\d{4}(?: \d{2}:\d{2}(?::\d{2})?)?$~', $value) === 1;
    }

    /**
     * Decodifica um token gerado por token().
     */
    public static function decode(string $token): array
    {
        return MadStateCrypt::decrypt($token) ?? [];
    }

    // ── Helpers para uso no controller ───────────────────────────────────────

    /**
     * Filtra $data mantendo apenas os campos presentes no schema.
     * Evita mass-assignment de campos não registrados.
     *
     * @param array $data   Dados brutos (ex: $_POST)
     * @param array $schema Schema decodificado por fromRequest()
     * @return array        Dados filtrados (somente campos do schema)
     */
    public static function filterData(array $data, array $schema): array
    {
        $filtered = [];
        foreach ($schema as $name => $props) {
            if (array_key_exists($name, $data)) {
                $filtered[$name] = $data[$name];
            } else {
                // Checkboxes e switches desmarcados não enviam valor.
                // Para switch (e checkbox com value_off explicito), respeita o
                // valor "off" registrado — suporta dual-value tipo 'A'/'I'.
                $type = $props['type'] ?? '';
                if (in_array($type, ['checkbox', 'switch'])) {
                    $filtered[$name] = $props['value_off'] ?? '0';
                } elseif (in_array($type, ['checkbox-group', 'db-checkbox-group', 'checklist', 'multi-search', 'db-multi-search'])) {
                    $filtered[$name] = [];
                }
            }
        }
        return $filtered;
    }

    /** Chegou arquivo novo em $_FILES para um campo de upload? */
    private static function _hasUploadedFile(string $name, string $type): bool
    {
        if (!in_array($type, ['file', 'image', 'multi-file'], true)) return false;
        if (!empty($_POST['__mad_file_removed'][$name])) return false;

        $f = $_FILES[$name] ?? null;
        if (!is_array($f)) return false;

        // multi-file chega com arrays paralelos.
        $tmp = $f['tmp_name'] ?? '';

        return is_array($tmp) ? !empty(array_filter($tmp)) : $tmp !== '';
    }

    /**
     * Valida $data contra as regras do schema.
     * Retorna array de erros: ['campo' => 'mensagem', ...]
     *
     * Regras suportadas (vindas das props do componente):
     *   required, min, max, maxlength, minlength
     *
     * @param array $data   Dados filtrados
     * @param array $schema Schema decodificado
     * @return array        Erros encontrados (vazio = válido)
     */
    public static function validate(array $data, array $schema): array
    {
        $errors = [];

        foreach ($schema as $name => $props) {
            $value = $data[$name] ?? '';
            $label = $props['label'] ?? $name;
            $type  = $props['type'] ?? 'input';

            // required
            // Campo de upload nao posta valor: o arquivo vive em $_FILES.
            // Sem esta checagem, imagem obrigatoria reprovava com o arquivo
            // anexado — mesmo ponto cego que o MadForm::validate tinha.
            if (!empty($props['required']) && $value === '' && !self::_hasUploadedFile($name, $type)) {
                $errors[$name] = "{$label} é obrigatório.";
                continue;
            }

            if ($value === '') {
                continue;
            }

            // minlength
            if (isset($props['minlength']) && strlen($value) < (int)$props['minlength']) {
                $errors[$name] = "{$label} deve ter pelo menos {$props['minlength']} caracteres.";
                continue;
            }

            // maxlength
            if (isset($props['maxlength']) && strlen($value) > (int)$props['maxlength']) {
                $errors[$name] = "{$label} deve ter no máximo {$props['maxlength']} caracteres.";
                continue;
            }

            // min/max numérico
            if (in_array($type, ['input', 'number', 'range'])) {
                if (isset($props['min']) && is_numeric($value) && (float)$value < (float)$props['min']) {
                    $errors[$name] = "{$label} deve ser no mínimo {$props['min']}.";
                    continue;
                }
                if (isset($props['max']) && is_numeric($value) && (float)$value > (float)$props['max']) {
                    $errors[$name] = "{$label} deve ser no máximo {$props['max']}.";
                    continue;
                }
            }
        }

        return $errors;
    }

    /**
     * Hidrata um objeto model com os dados filtrados, respeitando o whitelist do schema.
     *
     * @param object $model  Objeto a ser preenchido (ActiveRecord legado ou stdClass)
     * @param array  $data   Dados filtrados por filterData()
     * @param array  $schema Schema decodificado
     */
    public static function fill(object $model, array $data, array $schema): void
    {
        foreach ($schema as $name => $props) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            // Suporte a campos aninhados: "endereco[cep]" → ignora por ora
            if (strpos($name, '[') !== false) {
                continue;
            }
            $model->$name = $data[$name];
        }
    }

    /**
     * Retorna todos os campos registrados no render atual (para debug).
     */
    public static function getFields(): array
    {
        return self::$fields;
    }

    // ── Detail Forms ────────────────────────────────────────────────────────

    /**
     * Registra um detail-form durante o render do componente Blade.
     * Chamado pelo detail-form.blade.php ao renderizar.
     *
     * @param string $name       Nome do detail-form
     * @param array  $columns    Colunas com field, label, evaluate, etc.
     * @param string $model      Classe do model Eloquent (ex: 'DocReceitaItem') — habilita lazy-load no evaluate
     * @param string $foreignKey Coluna FK no model filho (ex: 'pedido_venda_id') — habilita auto-save/load
     * @param string $database   Conexão do banco (default: MAIN_DATABASE)
     */
    public static function registerDetailForm(string $name, array $columns, string $model = '', string $foreignKey = '', string $database = ''): void
    {
        self::$detailForms[$name] = [
            'columns'    => $columns,
            'model'      => $model,
            'foreignKey' => $foreignKey,
            'database'   => $database,
        ];
        self::declareDetail($name);
    }

    /**
     * O que o SAVE das linhas de um detail-form precisa saber das colunas
     * (hoje: `strip-mask`), lido dos campos do editor `<mad-detail-fields>`.
     * Mesmo formato do `colMeta` do field-list — o transformFieldListRows()
     * aplica igual. Vai no token junto com o detail-form: no request do save o
     * Blade não renderiza de novo.
     *
     * @param array<string, array{mask: string, stripMask: bool}> $colMeta
     */
    public static function registerDetailColMeta(string $name, array $colMeta): void
    {
        if (!$name || !$colMeta || !isset(self::$detailForms[$name])) {
            return;
        }
        self::$detailForms[$name]['colMeta'] = $colMeta;
    }

    /**
     * Campos do editor (`<mad-detail-fields>`) de um detail-form — os que o
     * sub-form registrou e que o formulário principal ainda não tinha.
     *
     * Eles entram no schema do formulário junto com os campos da tela, mas são
     * colunas da LINHA: um campo do editor chamado `status` não faz da coluna
     * `status` do registro principal um campo da tela. Vai no token, para o
     * Salvar (que não renderiza de novo) saber separar os dois — ver
     * MadForm::fillRecord().
     *
     * @param list<string> $fields
     */
    public static function registerDetailEditorFields(string $name, array $fields): void
    {
        if (!$name || !isset(self::$detailForms[$name])) {
            return;
        }
        self::$detailForms[$name]['editor'] = array_values(array_map('strval', $fields));
        self::declareDetail($name);
    }

    /**
     * Nomes que estão no schema só por serem campo do editor de algum
     * detail-form (nome => true). Token antigo, sem a lista: vazio.
     *
     * @return array<string,true>
     */
    public static function detailEditorFields(): array
    {
        $names = [];
        foreach (self::$detailForms as $entry) {
            foreach ((array) ($entry['editor'] ?? []) as $field) {
                $names[(string) $field] = true;
            }
        }

        return $names;
    }

    /**
     * Colunas da linha que um detail (Lista de itens ou Detail Form) TEM na
     * tela (nome => true): as colunas da grade e, no Detail Form, os campos do
     * editor. Null quando não dá para saber (detail não registrado, ou
     * detail-form de um token antigo, sem a lista do editor) — aí o Salvar
     * grava a linha inteira, como sempre gravou.
     *
     * @return array<string,true>|null
     */
    public static function detailShownFields(string $name): ?array
    {
        if (isset(self::$fieldLists[$name])) {
            $shown = [];
            foreach (self::getFieldListFields($name) as $field) {
                if (is_string($field) && $field !== '') {
                    $shown[$field] = true;
                }
            }

            return $shown;
        }

        $entry = self::$detailForms[$name] ?? null;
        if (!is_array($entry) || !is_array($entry['editor'] ?? null)) {
            return null;
        }

        $shown = array_fill_keys(array_map('strval', $entry['editor']), true);
        foreach ((array) ($entry['columns'] ?? []) as $column) {
            $field = is_array($column) ? (string) ($column['field'] ?? '') : '';
            if ($field !== '') {
                $shown[$field] = true;
                $shown[\Mad\Grid\GridRenderHelpers::rowDataKey($field)] = true;
            }
        }

        return $shown;
    }

    /**
     * Retorna os dados registrados de um detail-form.
     */
    public static function getDetailForm(string $name): array
    {
        return self::$detailForms[$name] ?? [];
    }

    /**
     * Resolve as colunas DISPLAY-ONLY (`{rel->col}`, `{rel->rel2->col}`,
     * `{a} - {b}` e a chain NUA `rel->col`) de UMA linha de detail-form.
     *
     * O `field` de `<mad-col>` é template de EXIBIÇÃO, não chave da linha. A
     * linha carregada do banco traz o template resolvido na própria chave
     * (MadForm::autoLoadDetailRows), mas a linha criada no navegador pelo botão
     * "Adicionar" só tem as chaves dos inputs do sub-form — o `name` nu. O
     * cliente NÃO consegue resolver um caminho de relacionamento: quem percorre
     * relação é o servidor. Sem isto a coluna de outra tabela fica VAZIA na
     * linha recém-adicionada (e STALE na editada, quando a FK muda).
     *
     * A instância do model é temporária e NUNCA é salva: existe só para o
     * `belongsTo` lazy-loadar a partir da FK que veio na linha.
     *
     * ⚠️ A `$row` vem do CLIENTE. Não é confiável para nada além de exibição:
     * aqui ela só hidrata atributos de uma instância descartável (chaves `__*`,
     * de template e de chain são puladas) e o retorno é texto para pintar
     * célula. Nada daqui vai para persistência.
     *
     * @param  string $name Nome do detail-form (`name=` do `<mad-detail-form>`)
     * @param  array  $row  Linha crua do cliente
     * @return array<string,string> ['{produto->nome}' => 'Teclado', ...]
     */
    public static function resolveDetailDisplay(string $name, array $row): array
    {
        $entry = self::$detailForms[$name] ?? null;   // restaurado do state cifrado
        if (!$entry) {
            return [];
        }

        // Só o que o cliente NÃO sabe montar: chain ou template composto.
        // Token simples (`{quantidade}`) já chega na chave nua — é o contrato
        // do GridRenderHelpers::rowDataKey/rowValueJs, não mexer aqui.
        $patterns = [];
        foreach (($entry['columns'] ?? []) as $col) {
            $field = is_array($col) ? (string) ($col['field'] ?? '') : '';
            if ($field === '') {
                continue;
            }
            // Chain NUA (`produto->nome`, sem chaves): mesma regra da carga
            // do banco (GridRenderHelpers::detectRenderFields) — a chave da
            // linha é o `field` como declarado, o pattern ganha as chaves.
            if (!str_contains($field, '{')) {
                if (str_contains($field, '->')) {
                    $patterns[$field] = '{' . trim($field) . '}';
                }
                continue;
            }
            if (\Mad\Grid\GridRenderHelpers::rowDataKey($field) === $field) {
                $patterns[$field] = $field;
            }
        }
        if (!$patterns) {
            return [];
        }

        $obj = null;
        if (!empty($entry['model'])) {
            try {
                $cls = ModelOptionsLoader::resolveModelClass((string) $entry['model']);
                $obj = new $cls();
                foreach ($row as $k => $v) {
                    $k = (string) $k;
                    // `__id`/`__*`: metadado do Alpine. `{`/`->`: chave de
                    // apresentação, não coluna — mesma regra do save
                    // (MadForm::isDisplayOnlyDetailKey).
                    if ($k === '' || str_starts_with($k, '__')) continue;
                    if (str_contains($k, '{') || str_contains($k, '->')) continue;
                    if (is_array($v) || is_object($v)) continue;
                    $obj->setAttribute($k, $v);
                }
            } catch (\Throwable $e) {
                // Fail-closed: sem model a chain não resolve, mas a tela não cai.
                // Sem este log o sintoma é uma coluna vazia sem nenhuma pista.
                error_log('[MadFormRegistry::resolveDetailDisplay] model "' . $entry['model']
                    . '" não instanciável no detail "' . $name . '" — coluna de '
                    . 'relacionamento vai ficar VAZIA: ' . $e->getMessage());
                $obj = null;
            }
        }

        $out = [];
        foreach ($patterns as $field => $pattern) {
            try {
                $out[$field] = \Mad\Grid\GridRenderHelpers::resolveTemplate(
                    $pattern,
                    $obj ?: (object) $row,
                    $row
                );
            } catch (\Throwable $e) {
                error_log('[MadFormRegistry::resolveDetailDisplay] "' . $pattern
                    . '" não resolveu no detail "' . $name . '": ' . $e->getMessage());
                $out[$field] = '';
            }
        }

        return $out;
    }

    // ── Field-list registry ──────────────────────────────────────────────────

    /**
     * Registra um field-list com seus campos.
     * Chamado automaticamente pelo template field-list.blade.php.
     *
     * @param string $name       Nome do field-list
     * @param array  $fields     Nomes dos campos (ex: ['produto_id', 'quantidade'])
     * @param string $model      Classe do model Eloquent (ex: 'PedidoVendaItem') — habilita auto-save/load
     * @param string $foreignKey Coluna FK no model filho (ex: 'pedido_venda_id') — habilita auto-save/load
     * @param string $database   Conexão do banco (default: MAIN_DATABASE)
     * @param array  $colMeta    Metadados por coluna que o SAVE precisa conhecer:
     *                           ['campo' => ['mask'=>, 'stripMask'=>, 'forceCase'=>]].
     *                           Só as colunas que declaram algo entram — field-list
     *                           sem máscara nenhuma não engorda o token.
     */
    public static function registerFieldList(string $name, array $fields, string $model = '', string $foreignKey = '', string $database = '', array $colMeta = []): void
    {
        self::$fieldLists[$name] = [
            'fields'     => $fields,
            'model'      => $model,
            'foreignKey' => $foreignKey,
            'database'   => $database,
        ];
        if ($colMeta) {
            self::$fieldLists[$name]['colMeta'] = $colMeta;
        }
        self::declareDetail($name);
    }

    /**
     * Registra colunas de arquivo (file/multifile) de um detail (field-list ou
     * detail-form). Chamado pelos templates field-list.blade.php / detail-form.blade.php.
     *
     * Habilita o auto-save por-linha de uploads: MadForm::_saveDetailRows() lê
     * $_FILES['mad_fl_files'][<detailName>__<rowId>__<field>] e persiste cada
     * arquivo no model filho (disco → path na coluna; db → BLOB base64).
     *
     * As views chamam SEMPRE, uma vez por render, com todas as colunas de
     * arquivo que o detail tem — inclusive nenhuma: o que estava registrado
     * antes (o que o `fromRequest()` restaurou do formulário recebido) é
     * trocado, e o formulário da tela anota como ESTE render as desenhou
     * (MadForm::declareDetailFiles). É por essa anotação, no estado cifrado,
     * que o Salvar não aceita as colunas de arquivo do formulário de outra
     * tela — nem a falta delas.
     *
     * @param string $name Nome do detail
     * @param array  $cols ['field' => ['storage'=>,'folder'=>,'fileName'=>,'nameColumn'=>,'multi'=>bool]]
     */
    public static function registerDetailFileColumns(string $name, array $cols): void
    {
        if (!$name) {
            return;
        }
        if (empty($cols)) {
            unset(self::$detailFileColumns[$name]);
        } else {
            self::$detailFileColumns[$name] = $cols;
        }
        foreach (self::screenForms() as $form) {
            $form->declareDetailFiles($name, $cols);
        }
    }

    /**
     * Retorna as colunas de arquivo registradas de um detail (ou [] se nenhuma).
     */
    public static function getDetailFileColumns(string $name): array
    {
        return self::$detailFileColumns[$name] ?? [];
    }

    /**
     * Retorna os campos registrados de um field-list.
     */
    public static function getFieldListFields(string $name): array
    {
        $entry = self::$fieldLists[$name] ?? [];
        // Backward compat: formato antigo era array plano de field names
        return $entry['fields'] ?? $entry;
    }

    /**
     * Aplica nas rows de um field-list o que a COLUNA declarou: force-case e
     * strip-mask.
     *
     * Por que no servidor: o client mascara para o humano ler, mas o que chega
     * no POST é a string mascarada. Sem isto, `strip-mask` numa coluna gravaria
     * '123.456.789-01' num campo que o schema espera cru — exatamente o que o
     * <mad-input-field> já evita no transformField(). O metadado vem do token
     * cifrado (registerFieldList → token() → fromRequest()), então vale também
     * para o request de save, onde as colunas não foram renderizadas de novo.
     *
     * Entry sem `colMeta` (token antigo, ou field-list sem máscara) devolve as
     * rows intocadas — custo zero para quem não usa.
     */
    public static function transformFieldListRows(string $name, array $rows): array
    {
        // field-list declara na coluna (FieldListColumn); detail-form herda do
        // campo do editor (`<mad-detail-fields>`) — ver registerDetailColMeta().
        $meta = self::$fieldLists[$name]['colMeta'] ?? self::$detailForms[$name]['colMeta'] ?? [];
        if (!$meta || !$rows) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($meta as $field => $cfg) {
                if (!array_key_exists($field, $row) || !is_string($row[$field]) || $row[$field] === '') {
                    continue;
                }
                $value = $row[$field];

                $case = strtolower((string) ($cfg['forceCase'] ?? ''));
                if ($case !== '') {
                    $value = match ($case) {
                        'upper' => mb_strtoupper($value),
                        'lower' => mb_strtolower($value),
                        'title' => mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8'),
                        default => $value,
                    };
                }

                if (!empty($cfg['stripMask']) && !empty($cfg['mask'])) {
                    $value = \Mad\Support\MadMask::strip($value);
                }

                $rows[$i][$field] = $value;
            }
        }

        return $rows;
    }

    /**
     * Retorna todos os details (field-lists + detail-forms) que declaram model + foreignKey.
     * Usado por MadForm::_autoSaveDetails() para auto-persistir itens.
     *
     * @return array<string, array{type: string, model: string, foreignKey: string, database: string}>
     */
    public static function getAutoSaveDetails(): array
    {
        $details = [];

        foreach (self::$fieldLists as $name => $entry) {
            $model = $entry['model'] ?? '';
            $fk    = $entry['foreignKey'] ?? '';
            if ($model && $fk) {
                $details[$name] = [
                    'type'       => 'field-list',
                    'model'      => $model,
                    'foreignKey' => $fk,
                    'database'   => $entry['database'] ?? '',
                ];
            }
        }

        foreach (self::$detailForms as $name => $entry) {
            $model = $entry['model'] ?? '';
            $fk    = $entry['foreignKey'] ?? '';
            if ($model && $fk) {
                $details[$name] = [
                    'type'       => 'detail-form',
                    'model'      => $model,
                    'foreignKey' => $fk,
                    'database'   => $entry['database'] ?? '',
                ];
            }
        }

        return $details;
    }

    // ── Transformação de valores ──────────────────────────────────────────────

    /**
     * Transforma um valor de campo de acordo com as props do schema.
     *
     * - date:     dd/mm/yyyy → database_mask (padrão yyyy-mm-dd); vazio → null
     * - datetime: dd/mm/yyyy HH:MM → database_mask; vazio → null
     * - time:     passthrough (display == banco); vazio → null
     * - number/numeric/money/spinner/range: passthrough; vazio → null
     *   (ou 0 / '' conforme a prop `empty-as` do campo)
     * - select/radio/seek/unique-search: vazio → null quando o campo declara
     *   `empty-as` (os de banco declaram; ver _convertChoiceEmpty)
     * - checkbox/switch: bool/string → '1'/'0'
     * - demais: passthrough
     *
     * Usado por MadForm::getData() (instância e estático).
     */
    public static function transformField(mixed $value, array $props): mixed
    {
        $type = $props['type'] ?? 'input';

        $transformed = match ($type) {
            'date'      => self::_convertDate((string) $value, $props['database_mask'] ?? 'yyyy-mm-dd'),
            'datetime'  => self::_convertDatetime((string) $value, $props['database_mask'] ?? 'yyyy-mm-dd HH:MM:SS'),
            // time nao converte formato (display == banco), mas o campo vazio
            // precisa do mesmo NULL que date/datetime — '' nao e TIME valido.
            'time'      => ((string) $value === '') ? null : $value,
            // Numericos: valor passa CRU (a normalizacao decimal e contrato do
            // hidden rawValue dos blades money/numeric), so o vazio e coagido.
            'number', 'numeric', 'money', 'spinner',
            'range'     => self::_convertNumericEmpty($value, $props),
            // Escolha única (combo, radio, busca, seek): vazio vira NULL só
            // quando o campo declara `emptyAs` — os de BANCO declaram
            // (default 'null'); os de lista fixa não. Ver _convertChoiceEmpty.
            'select', 'radio', 'seek',
            'unique-search' => self::_convertChoiceEmpty($value, $props),
            // checkbox/switch: dual-value via value_on/value_off (TCheckButton-like).
            // Se $value bate exatamente com value_on, retorna value_on. Senao, value_off.
            // Fallback: '1'/'0' quando props nao registradas (uso legado).
            'switch'    => self::_resolveBooleanValue($value, $props['value_on'] ?? '1', $props['value_off'] ?? '0'),
            'checkbox'  => self::_resolveBooleanValue($value, $props['value'] ?? '1', $props['value_off'] ?? '0'),
            // `checkbox-group` estava FORA desta lista — e era o único
            // multi-seleção assim. O `mad-livewire.js` serializa
            // `.mad-checkbox-group` com `JSON.stringify`, então o valor chegava
            // como a STRING `'["7","8"]'` em vez do CSV que a família inteira
            // entrega. Quem lia com `explode(',')` — o padrão do produto —
            // produzia `['["7"', '"8"]']`, cujo `intval` é 0: a escolha do
            // usuário virava ZERO em silêncio (pego no downgrade da cobrança,
            // que desativava justamente quem o cliente marcou para ficar).
            //
            // O irmão `db-checkbox-group` renderiza o MESMO DOM
            // (`.mad-checkbox-group`), posta o MESMO JSON e já convertia: o
            // contrato da família sempre foi CSV, só esta linha não sabia.
            'checkbox-group',
            'checklist',
            'multi-search',
            'db-multi-search',
            'db-checkbox-group' => self::_transformMultiSelect($value),
            default     => $value,
        };

        return self::stripMaskValue($transformed, $props);
    }

    /**
     * `strip-mask`: remove a máscara (tudo que não é dígito/letra) quando o
     * campo declarou `mask` + `stripMask` — o banco recebe '12345678901', não
     * '123.456.789-01'. Usado no getData() e no validate() (a validação confere
     * o MESMO valor que vai pro banco: `max` da coluna, `unique` contra as
     * linhas gravadas sem máscara).
     *
     * Não olha o `type`: `<mad-input-field>` registra o tipo HTML (`text`,
     * `tel`…) e `<mad-cep-field>`/`<mad-cnpj-field>` registram `text`. Um
     * `$type === 'input'` aqui deixava o strip-mask morto nos três — o CPF ia
     * pro banco com pontuação.
     *
     * Sem `mask` não remove nada: o valor não foi mascarado no client, e
     * "tirar a máscara" apagaria caractere legítimo do dado.
     */
    public static function stripMaskValue(mixed $value, array $props): mixed
    {
        if (empty($props['stripMask']) || empty($props['mask'])
            || !is_string($value) || $value === '') {
            return $value;
        }

        return \Mad\Support\MadMask::strip($value);
    }

    /**
     * Resolve valor de checkbox/switch com suporte a dual-value (value_on/value_off).
     *
     * Regras:
     * - Se $value bate exatamente com $valueOn (string compare): retorna $valueOn
     * - Se $value bate exatamente com $valueOff: retorna $valueOff
     * - Se $value e bool/int: usa truthiness ($value ? $valueOn : $valueOff)
     * - String vazia, null: retorna $valueOff
     * - Default: truthiness
     *
     * Suporta TCheckButton-like (indexValue / inactiveIndexValue como 'A'/'I')
     * sem quebrar uso legado (bool true/false → '1'/'0').
     */
    private static function _resolveBooleanValue(mixed $value, mixed $valueOn, mixed $valueOff): mixed
    {
        $on  = (string) $valueOn;
        $off = (string) $valueOff;
        if ($value === null || $value === '') {
            return $valueOff;
        }
        if (is_bool($value)) {
            return $value ? $valueOn : $valueOff;
        }
        $sval = (string) $value;
        if ($sval === $on)  { return $valueOn; }
        if ($sval === $off) { return $valueOff; }
        return $value ? $valueOn : $valueOff;
    }

    /**
     * Transforma valor de campo multi-select (checklist, multi-search, db-checkbox-group).
     * Aceita: array PHP, JSON string '["1","2"]', ou string CSV '1,2,3'.
     * Retorna: string CSV '1,2,3' (para persistência comma mode).
     */
    private static function _transformMultiSelect(mixed $value): string
    {
        if (is_array($value)) {
            $arr = $value;
        } elseif (is_string($value) && str_starts_with($value, '[')) {
            $arr = json_decode($value, true) ?: [];
        } else {
            return (string) $value;
        }
        return implode(',', array_filter(array_map('trim', array_map('strval', $arr)), fn($v) => $v !== ''));
    }

    /**
     * Máscara MAD → formato PHP date(). Cobre os tokens nas DUAS caixas:
     * os blades de date/datetime registram máscara minúscula ('yyyy-mm-dd
     * hh:ii') — um str_replace só de HH/MM/SS deixava 'hh'/'ii' crus no
     * formato e o DateTime::format duplicava hora/minuto no valor gravado
     * (ex: '09:00' → '0909:0000'). strtr não reaplica sobre o resultado.
     */
    private static function _maskToPhpFormat(string $mask): string
    {
        return strtr($mask, [
            'yyyy' => 'Y', 'yy' => 'y',
            'mm' => 'm', 'dd' => 'd',
            'HH' => 'H', 'hh' => 'H',
            'MM' => 'i', 'ii' => 'i',
            'SS' => 's', 'ss' => 's',
        ]);
    }

    /**
     * Campo NUMERICO vazio → NULL (default), 0 ou '' conforme `empty-as`.
     *
     * O valor preenchido passa CRU: quem normaliza o decimal e o hidden
     * `rawValue` dos blades money/numeric, nao o servidor (ver os testes de
     * passthrough em MadFormRegistryTransformTest). Aqui so o VAZIO muda.
     *
     * Por que NULL: `''` nao e literal numerico em banco nenhum. Medido em
     * 2026-09-09, coluna NULLABLE:
     *   MariaDB 11.4 estrito → 1366 Incorrect integer/decimal value: ''
     *   PostgreSQL 17        → 22P02 invalid input syntax for type integer: ""
     *   SQLite               → aceita e GRAVA o texto '' na coluna INTEGER;
     *                          `IS NULL` da falso e `WHERE qtd > 0` CASA a
     *                          linha (TEXT > numero no ordering do SQLite).
     * O SQLite e o pior dos tres: nao acusa nada no dev/CI e mente no filtro.
     *
     * `empty-as` existe para o unico caso em que a coercao quebra codigo que
     * funcionava: campo numerico apontado para coluna de TEXTO `NOT NULL`,
     * que antes recebia `''` e agora recusaria o NULL.
     *   empty-as="null"  (default) → NULL
     *   empty-as="zero"            → 0    (coluna numerica NOT NULL sem default)
     *   empty-as="empty"           → ''   (comportamento legado)
     * Valor desconhecido cai no default — a prop nao inventa comportamento.
     */
    private static function _convertNumericEmpty(mixed $value, array $props): mixed
    {
        $isEmpty = $value === null
            || (is_string($value) && trim($value) === '');

        if (!$isEmpty) {
            return $value;
        }

        return match (strtolower((string) ($props['emptyAs'] ?? 'null'))) {
            'zero'  => 0,
            'empty' => '',
            default => null,
        };
    }

    /**
     * Campo de ESCOLHA ÚNICA em branco → NULL, 0 ou '' conforme `empty-as`.
     *
     * O combo opcional sem seleção posta `''` (a option placeholder). Num
     * campo de banco (`<mad-dbcombo-field>`, `<mad-dbunique-search-field>`,
     * `<mad-dbradio-field>`, `<mad-dbseek-field>`, `<mad-seek-field>`) o valor é
     * a CHAVE de outra tabela — e `''` não é chave de nada: coluna inteira
     * recusa (`invalid input syntax for type integer: ""` no PostgreSQL, 1366
     * no MariaDB estrito) e o salvar quebra com erro de banco. NULL é "sem
     * vínculo". É também o que o Laravel entrega num request comum
     * (ConvertEmptyStringsToNull), que o POST do MadWire não atravessa.
     *
     * Por que SÓ quando o campo declara `emptyAs`:
     *   - os campos de banco registram `emptyAs` (default 'null');
     *   - os de LISTA FIXA (`<mad-select-field>`, `<mad-radio-field>`,
     *     `<mad-unique-search-field>`) não: lá `''` pode ser um valor escolhido
     *     de propósito (`:items="['' => 'Não informado', …]"`) numa coluna de
     *     texto NOT NULL — convertê-lo quebraria o que hoje funciona;
     *   - token antigo (sem a chave) mantém o comportamento de antes.
     *
     * Só a string vazia (ou só espaços) muda. Seleção múltipla chega como
     * array ou JSON (`'[]'`), nunca `''` — passa intacta, assim como qualquer
     * valor preenchido (inclusive `'0'`).
     *   empty-as="null"  (default dos campos de banco) → NULL
     *   empty-as="zero"                                → 0
     *   empty-as="empty"                               → ''  (comportamento antigo)
     */
    private static function _convertChoiceEmpty(mixed $value, array $props): mixed
    {
        if (!array_key_exists('emptyAs', $props)) {
            return $value;
        }
        if (!is_string($value) || trim($value) !== '') {
            return $value;
        }

        return match (strtolower((string) $props['emptyAs'])) {
            'zero'  => 0,
            'empty' => '',
            default => null,
        };
    }

    /**
     * Converte data no formato de display (dd/mm/yyyy) para o formato do banco.
     *
     * Campo vazio vira NULL, não ''. String vazia NUNCA é data válida: num
     * MySQL/MariaDB em modo estrito o INSERT com '' numa coluna DATE nullable
     * estoura `SQLSTATE[22007] 1292 Incorrect date value: ''`, e fora do modo
     * estrito grava o lixo '0000-00-00'. NULL é a representação correta de
     * "sem data" nos dois casos.
     */
    private static function _convertDate(string $value, string $dbMask): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat('d/m/Y', $value);
        if (!$dt) {
            return $value;
        }
        return $dt->format(self::_maskToPhpFormat($dbMask));
    }

    /**
     * Converte datetime no formato de display (dd/mm/yyyy hh:ii[:ss]) para o
     * formato do banco. Valor que não casa com o display (ex: já veio no
     * formato do banco via form->set) passa cru. Vazio vira NULL — ver
     * _convertDate().
     */
    private static function _convertDatetime(string $value, string $dbMask): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat('d/m/Y H:i', $value)
            ?: \DateTime::createFromFormat('d/m/Y H:i:s', $value);
        if (!$dt) {
            return $value;
        }
        return $dt->format(self::_maskToPhpFormat($dbMask));
    }
}