<?php
namespace Mad\Form;
use Mad\Component\MadComponent;
use Mad\Component\MadRenderContext;
use Mad\Http\MadResponse;
use Mad\Service\MadUploadStorage;
use Mad\Ui\MadToast;


/**
 * MadForm — objeto de valor para formulários de MadComponent.
 *
 * Uso como propriedade pública do componente:
 *
 *   class ClienteForm extends MadComponent
 *   {
 *       public MadForm $form;
 *
 *       public function mount(array $params = []): void
 *       {
 *           $this->form = new MadForm('form');
 *       }
 *
 *       public function onEdit(int $id): void
 *       {
 *           $record = Cliente::find($id)->toArray();
 *           $this->form->fill($record);
 *       }
 *
 *       public function salvar(): MadResponse
 *       {
 *           $data = $this->form->getData();    // retorna object (cached)
 *           // $data->data_nasc  → 'yyyy-mm-dd'
 *           // $data->ativo      → '1' ou '0'
 *
 *           // Popular o model direto:
 *           $this->form->fillRecord($record);
 *           $record->save();
 *       }
 *   }
 */
class MadForm
{
    /** Nome do formulário — usado como prefixo no contexto de render. */
    public string $name;

    /** Campos do formulário: ['campo' => valor, ...] */
    public array $fields = [];

    /**
     * Bucket de options para campos de coleção (combo, radio, checkbox-group,
     * multi-entry, sort-list, checklist).
     *
     *   ['unit_id' => ['1' => 'Matriz', '2' => 'Filial'], ...]
     *
     * Preenchido via `$this->form->setItems($name, $items, $selected = null)`.
     * Quando alterado durante uma action, o MadComponentHandler detecta o diff
     * no auto-bind e emite a op `reload_*` correspondente ao tipo do campo.
     */
    public array $items = [];

    /**
     * Placeholders por campo (opcional, usado apenas pelo combo).
     *
     *   ['unit_id' => '— Selecione —']
     */
    public array $placeholders = [];

    /**
     * Elementos escondidos no DOM. Map `nome => scope`, onde scope ∈ 'field' | 'tab' | 'row'.
     *
     *   ['nome' => 'field', 'abaExtras' => 'tab', 'row-abc' => 'row']
     *
     * Alterado via `$this->form->hide($name, $scope = 'field')` / `show(...)`.
     * Diff detectado pelo MadComponentHandler → emite ops `mad_hide` / `mad_show`.
     */
    public array $hidden = [];

    /**
     * Campos em modo read-only (valor continua indo no POST).
     *
     *   ['email' => true, ...]
     *
     * Alterado via `$this->form->readonly($name)` / `editable($name)`.
     * Diff → op `mad_readonly`.
     */
    public array $readonly = [];

    /**
     * Botões desabilitados (bloqueiam clique — diferente do readonly de campo).
     *
     *   ['btn_salvar' => true, ...]
     *
     * Alterado via `$this->form->disable($name)` / `enable($name)`.
     * Diff → op `mad_disabled`. Aplica o atributo `disabled` em `button[data-mad-btn="X"]`.
     */
    public array $disabled = [];

    /**
     * Ops fl_combo pendentes geradas por setItems('campo[]').
     * Coletadas pelo MadComponentHandler após a action e mescladas no response.
     * Não serializada (consumida no mesmo request).
     */
    private array $_pendingFlOps = [];

    /**
     * Campo que deve receber o cursor (`focus()`). Vale para UMA resposta: não
     * entra no estado serializado, senão o foco voltaria a cada ação.
     */
    private ?string $_pendingFocus = null;

    /**
     * Campo marcado com `autofocus` no Blade. Só a ABERTURA da tela o usa, e
     * só quando o código não chamou `focus()`: num redesenho depois de uma
     * ação ele não rouba o cursor de onde o usuário está.
     */
    private ?string $_autofocus = null;

    /** Cache do resultado de getData() — invalidado ao alterar fields. */
    private ?object $_dataCache = null;

    /**
     * Escopo do editor de detail-form ativo durante um `mad:change` disparado
     * dentro de `<mad-detail-fields>` — ver beginDetailScope()/endDetailScope().
     * Não serializado: vive só dentro do request da action.
     */
    private ?string $_detailScope = null;

    /** Campos do editor do detail como chegaram do cliente (base do diff). */
    private array $_detailScopeInput = [];

    /** Campos do master guardados enquanto o escopo do detail está aberto. */
    private array $_detailScopeBackup = [];

    /** Record passado ao fill() — usado pelo Blade para auto-load de details. */
    private ?object $_sourceRecord = null;

    /** @var array<string, callable> Hook pre-store por detail: fn($detalhe, $mestre, $formRow): void */
    private array $_detailSaveHooks = [];

    /** @var array<string, callable> Hook de transform no load: fn($detalhe, $mestre): array */
    private array $_detailLoadHooks = [];


    /** Cache normalizado de $_FILES['mad_fl_files'] (uploads por-linha de details). */
    private ?array $_madFlFilesCache = null;

    /** @var array<string, array<string,true>> colunas que aceitam NULL, por "conexão|tabela" — ver _emptyToNull() */
    private array $_nullableColumnsCache = [];

    public function __construct(string $name = 'form')
    {
        $this->name = $name;
    }

    // ── API de instância ──────────────────────────────────────────────────────

    /**
     * Popula os campos com dados vindos do banco de dados.
     * Aceita array ou objeto (model Eloquent, stdClass, etc.).
     *
     * Campos BLOB (storage="db") são tratados automaticamente durante o render
     * pelo componente Blade file-field — o base64 bruto é extraído para tmp/
     * e o path é sincronizado de volta para fields via MadRenderContext::updateField().
     *
     *   $this->form->fill($pedido);                       // objeto direto
     *   $this->form->fill($pedido, ['frete' => '0']);     // objeto + extras/defaults
     *   $this->form->fill(['nome' => 'João']);            // array como antes
     */
    public function fill(array|object $data, array $extra = []): void
    {
        if (is_object($data)) {
            $this->_sourceRecord = $data;
            $data = method_exists($data, 'toArray') ? $data->toArray() : (array) $data;
        }

        foreach ($data as $key => $value) {
            $this->fields[$key] = $value;
        }

        foreach ($extra as $key => $value) {
            $this->fields[$key] = $value;
        }

        $this->_dataCache = null;
    }

    /**
    /**
     * Retorna os dados transformados de acordo com o schema do formulário.
     *
     * - Campos date: dd/mm/yyyy → database_mask (padrão yyyy-mm-dd)
     * - Campos datetime: dd/mm/yyyy HH:MM → database_mask
     * - Campos checkbox/switch: bool → '1'/'0'
     * - Demais: passthrough
     *
     * Requer <mad-form-token /> no template para que o schema chegue em POST.
     */
    /**
     * Retorna os dados transformados como objeto (padrão legado).
     * Resultado é cacheado — chamadas múltiplas não re-transformam.
     *
     * @return object stdClass com propriedades do formulário
     */
    /**
     * Retorna fields brutos sem aplicar schema filter.
     *
     * Use em contextos onde a request tem multiplos `<mad-form>` (ex:
     * MadDashboard com varios filtros em popovers) — o $_REQUEST['__mad_form']
     * so contem o token do ultimo form renderizado, e getData() filtraria
     * incorretamente os fields registrados em outros forms.
     */
    public function getDataRaw(): object
    {
        // Mesma leitura tolerante do getData(): campo declarado e ausente lê
        // null (ver MadFormData). Os valores continuam crus.
        return MadFormData::make($this->fields, array_keys(MadFormRegistry::fromRequest()));
    }

    /**
     * O objeto devolvido é um {@see MadFormData}: campo DECLARADO no
     * formulário que não veio no POST (rádio sem opção, campo desabilitado,
     * primeiro render da página) lê `null` — como o `TForm::getData()` do
     * Adianti 4.0 —, mas só na leitura: `(array) $data`, `foreach`,
     * `fillRecord()`/`save()` continuam vendo apenas o que chegou, então nada
     * passa a gravar NULL em coluna que o usuário não tocou.
     */
    public function getData(string $tokenField = '__mad_form'): object
    {
        if ($this->_dataCache !== null) {
            return $this->_dataCache;
        }

        $schema = MadFormRegistry::fromRequest($tokenField);

        // Se não há schema (form-token ausente ou fora de ordem), retorna fields
        // brutos. É o GET inicial: os campos declarados vêm do registry do
        // render corrente, consultado na leitura pelo MadFormData.
        if (empty($schema)) {
            $this->_dataCache = MadFormData::make($this->fields);
            return $this->_dataCache;
        }

        $result = [];
        foreach ($schema as $name => $props) {
            if (!array_key_exists($name, $this->fields)) {
                continue;
            }
            $result[$name] = MadFormRegistry::transformField($this->fields[$name], $props);
        }

        // Inclui dados de field-lists (arrays não presentes no schema do form)
        // E scalars setados via form->set() que nao tem entry no schema (ex:
        // applyFilterFromLabel em dashboards seta props sem passar pelo input).
        foreach ($this->fields as $name => $value) {
            // array_key_exists, NÃO isset: campo do schema que já transformou
            // para null (date/datetime/time vazios) tem isset() false e seria
            // sobrescrito aqui pelo '' cru — desfazendo a coerção pra NULL.
            if (!array_key_exists($name, $result)) {
                // Rows de field-list passam pelo mesmo transform do
                // getFieldList() — senão $data->itens sairia mascarado enquanto
                // o auto-save grava cru, e as duas leituras discordariam.
                $result[$name] = is_array($value)
                    ? MadFormRegistry::transformFieldListRows($name, $value)
                    : $value;
            }
        }

        $this->_dataCache = MadFormData::make($result, array_keys($schema));
        return $this->_dataCache;
    }

    /**
     * Popula um model Eloquent (ou qualquer objeto) com os dados transformados do formulário.
     * Usa fromArray() quando o objeto expõe (whitelist própria do objeto); senão seta propriedade a propriedade.
     * Campos do tipo array (field-lists/detail-forms) são ignorados.
     *
     *   $this->form->fillRecord($pedido);
     *   $pedido->save();
     *
     * @return object O próprio record (para encadeamento)
     */
    public function fillRecord(object $record): object
    {
        $data = $this->getData();

        // Remove campos storage="db" do data antes do fromArray
        // (esses campos são tratados pelo _afterStore via prepared statement)
        $schema = MadFormRegistry::fromRequest();
        $dataArray = (array) $data;
        if (!empty($schema)) {
            foreach ($schema as $fieldName => $props) {
                $t = $props['type'] ?? '';
                if (($t === 'file' || $t === 'image') && ($props['storage'] ?? '') === 'db') {
                    unset($dataArray[$fieldName]);
                }
                // image com storage=disk: remove base64 do fromArray (será salvo como arquivo)
                if ($t === 'image' && ($props['storage'] ?? '')) {
                    unset($dataArray[$fieldName]);
                }
                // Multi-select table mode: IDs vão na pivot, não na tabela pai.
                // Multi-select manual mode: dev persiste manualmente no controller.
                if (in_array(($props['mode'] ?? ''), ['table', 'manual'], true)) {
                    unset($dataArray[$fieldName]);
                }
                // HTML editor: sanitiza output do Quill antes de persistir.
                // Defesa contra Stored XSS — render via {!! !!} so e seguro
                // se o conteudo passou por whitelist de tags/atributos.
                if ($t === 'html-editor' && isset($dataArray[$fieldName]) && is_string($dataArray[$fieldName])) {
                    $dataArray[$fieldName] = \Mad\Util\MadHtmlSanitizer::sanitize($dataArray[$fieldName]);
                }
            }
        }

        if ($record instanceof \Illuminate\Database\Eloquent\Model) {
            // Eloquent puro: fill() honra $fillable — campos do form que não são
            // coluna (ex: checklists) são descartados em vez de virar INSERT inválido.
            $record->fill($this->_emptyToNull(
                $record,
                array_filter($dataArray, fn ($v) => !is_array($v)),
                $schema,
            ));
        } else {
            foreach ($dataArray as $key => $value) {
                if (!is_array($value)) {
                    $record->$key = $value;
                }
            }
        }

        // Processa uploads automaticamente baseado no schema
        $this->_processFileUploads($record);

        return $record;
    }

    /**
     * Campo em branco (`''` ou só espaços) → NULL nas colunas que ACEITAM NULL.
     *
     * O POST do MadWire não passa pelo ConvertEmptyStringsToNull do Laravel:
     * um `<mad-input-field>` vazio chegava ao INSERT como `''`. Numa coluna
     * única opcional (CPF, e-mail, código) o segundo registro sem o valor
     * estourava o índice — `1062 Duplicate entry '' for key
     * 'clientes_cpf_unique'` —, e a rule `unique` não avisa antes porque o
     * validator pula regra não implícita em valor vazio. Vazio não é
     * duplicado: o índice único aceita vários NULL.
     *
     * Quem decide é a coluna, não o tipo do campo:
     *   - aceita NULL → NULL;
     *   - NOT NULL    → `''`, como sempre (NULL lá quebraria o INSERT de uma
     *     coluna de texto sem `required` que hoje funciona);
     *   - campo que declara `empty-as` (numérico, escolha) já foi resolvido
     *     no getData() — `empty-as="empty"` continua gravando `''`.
     * Schema indisponível (driver, permissão) → nada muda.
     *
     * @param  array<string,mixed>                $values
     * @param  array<string,array<string,mixed>>  $schema
     * @return array<string,mixed>
     */
    private function _emptyToNull(\Illuminate\Database\Eloquent\Model $record, array $values, array $schema): array
    {
        $empty = [];
        foreach ($values as $key => $value) {
            if (is_string($value) && trim($value) === ''
                && !array_key_exists('emptyAs', $schema[$key] ?? [])) {
                $empty[] = $key;
            }
        }
        if ($empty === []) {
            return $values;
        }

        $nullable = $this->_nullableColumns($record);
        foreach ($empty as $key) {
            if (isset($nullable[strtolower((string) $key)])) {
                $values[$key] = null;
            }
        }

        return $values;
    }

    /**
     * Colunas da tabela do record que aceitam NULL (chave em minúsculas).
     * Cache por instância — um save por request; estático ficaria velho no
     * Octane depois de uma migration.
     *
     * @return array<string,true>
     */
    private function _nullableColumns(\Illuminate\Database\Eloquent\Model $record): array
    {
        $connection = $record->getConnection();
        $key = $connection->getName() . '|' . $record->getTable();

        if (!isset($this->_nullableColumnsCache[$key])) {
            $nullable = [];
            try {
                foreach ($connection->getSchemaBuilder()->getColumns($record->getTable()) as $column) {
                    if (!empty($column['nullable'])) {
                        $nullable[strtolower((string) $column['name'])] = true;
                    }
                }
            } catch (\Throwable) {
                // sem schema: segue gravando o que veio (comportamento anterior)
            }
            $this->_nullableColumnsCache[$key] = $nullable;
        }

        return $this->_nullableColumnsCache[$key];
    }

    /**
     * Processa campos de arquivo registrados com storage no schema.
     * Chamado automaticamente por fillRecord().
     *
     * Quando um <mad-file-field> declara storage="disk", folder="..." e name-column="...",
     * este método salva o arquivo no disco e seta as colunas no record automaticamente.
     * Se nenhum arquivo novo foi enviado, o valor existente no record é mantido.
     */
    /**
     * Extensoes proibidas em uploads (executaveis server-side, configs Apache, etc).
     * Bloqueio defensivo — ainda que o webserver normalmente nao deva executar
     * PHP no folder de uploads, este e o backup que sobrevive a misconfig.
     */
    private const FORBIDDEN_UPLOAD_EXTENSIONS = [
        // PHP variants
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'pht', 'phar', 'phpt', 'inc',
        // Apache config
        'htaccess', 'htpasswd',
        // Other server-side scripts
        'cgi', 'pl', 'py', 'sh', 'rb', 'asp', 'aspx', 'jsp', 'jspx',
        // Windows executables
        'exe', 'bat', 'cmd', 'msi', 'com', 'scr',
        // Conteudo ATIVO (renderiza/executa same-origin -> stored XSS): svg com
        // <script>, HTML, XML/XHTML, etc. Nunca aceitar no upload.
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'mathml', 'vtt',
    ];

    /**
     * Normaliza folder de upload: rejeita path absoluto e parent traversal (..).
     * Fonte única das regras: MadUploadPath (compartilhada com os blades, que
     * validam no render — ver MadUploadPath::assertValidFolder).
     */
    private function _normalizeUploadFolder(string $folder): string
    {
        return MadUploadPath::normalize($folder);
    }

    /**
     * Colunas de METADADO do upload no `mode="table"`, por convenção. Chave =
     * papel do dado; valores = nomes de coluna aceitos (o primeiro que existir
     * na tabela filha vence). Só preenche coluna que EXISTE e que ainda não
     * recebeu valor — nunca sobrescreve o que o dev mapeou/setou.
     */
    private const UPLOAD_META_COLUMNS = [
        'original' => ['original_name', 'nome_original', 'name_original'],
        'size'     => ['size', 'file_size', 'tamanho', 'bytes'],
        'mime'     => ['mime_type', 'mimetype', 'content_type', 'tipo_mime'],
        'disk'     => ['disk', 'storage_disk'],
    ];

    /** Prop explícita (kebab → camel no parse de props) por papel. */
    private const UPLOAD_META_PROPS = [
        'original' => 'originalNameColumn',
        'size'     => 'sizeColumn',
        'mime'     => 'mimeColumn',
        'disk'     => 'diskColumn',
    ];

    /**
     * Preenche metadados do arquivo no registro filho (mode="table").
     * Regra ÚNICA em {@see \Mad\Service\MadUploadIngest::fillMeta} — o
     * `<mad-db-blocks>` grava item-a-item sem passar por aqui e precisa do
     * mesmo comportamento.
     *
     * @param array{original:string,size:int,mime:string,tmp:string} $meta
     */
    private function _fillUploadMeta(object $child, array $props, array $meta): void
    {
        \Mad\Service\MadUploadIngest::fillMeta($child, [
            'original' => $meta['original'],
            'size'     => $meta['size'] > 0
                ? $meta['size']
                : (is_file($meta['tmp']) ? (int) filesize($meta['tmp']) : 0),
            'mime'     => $meta['mime'] !== ''
                ? $meta['mime']
                : (is_file($meta['tmp']) && function_exists('mime_content_type')
                    ? (string) @mime_content_type($meta['tmp'])
                    : ''),
            'disk'     => \Mad\Service\MadUploadStorage::diskName(),
        ], $props);
    }

    /**
     * Sanitiza nome de arquivo do usuario:
     *   - remove null bytes, separators (/ \), parent traversal
     *   - trim espacos
     *   - rejeita extensoes proibidas (server-side, ainda que client tenha "permitido")
     *   - rejeita "double extensions" do tipo evil.php.jpg (Apache mod_mime fallback)
     */
    private function _sanitizeUploadName(string $name): string
    {
        // Strip path components agressivamente (basename + filtros)
        $clean = basename(str_replace(['\\', "\0"], ['/', ''], $name));
        $clean = trim($clean);
        if ($clean === '' || $clean === '.' || $clean === '..') {
            $clean = 'file';
        }

        $lower = strtolower($clean);

        // Rejeita extensao final proibida
        $ext = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::FORBIDDEN_UPLOAD_EXTENSIONS, true)) {
            throw new \RuntimeException(__('form.upload_ext_denied', ['ext' => $ext]));
        }

        // Rejeita "double extension" anywhere no nome (ex: evil.php.jpg)
        $forbiddenPattern = '/\.(?:' . implode('|', self::FORBIDDEN_UPLOAD_EXTENSIONS) . ')(?:\.|$)/i';
        if (preg_match($forbiddenPattern, $lower)) {
            throw new \RuntimeException(__('form.upload_ext_token'));
        }

        // Tira caracteres exoticos — mantem letras/digitos/.-_ e espaco; substitui resto por _
        $clean = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $clean);

        return $clean ?: 'file';
    }

    private function _buildFileName(string $originalName, string $mode, ?object $record = null): string
    {
        $clean = $this->_sanitizeUploadName($originalName);
        $ext   = pathinfo($clean, PATHINFO_EXTENSION);
        $pk    = $record ? ($record->{$record->getKeyName()} ?? null) : null;

        // Prefixos randomicos com 16 bytes hex (128 bits) — evita colisao de uniqid()
        // e remove dependencia do PID/microtime.
        $rand = bin2hex(random_bytes(8));

        return match ($mode) {
            'unique'   => $rand . ($ext ? ".{$ext}" : ''),
            'original' => $clean,
            'record'   => ($pk ?? $rand) . '_' . $clean,
            default    => $rand . '_' . $clean,
        };
    }

    // Persistência/remoção de arquivo de upload: centralizada em
    // Mad\Service\MadUploadStorage (put/delete), sempre via API de Filesystem
    // do Laravel — disco local default 'mad_uploads' (raiz do projeto) ou o
    // disco de MAD_UPLOAD_DISK (S3 etc). Paths RELATIVOS continuam no banco.

    /**
     * Normaliza $_FILES['mad_fl_files'] (uploads por-linha de field-list/detail-form)
     * para [ "detail__rowId__field" => [ ['name','tmp_name','error','size'], ... ] ].
     *
     * O cliente envia cada arquivo como mad_fl_files[<key>][<i>] (ver
     * mad-livewire.js / madFileCell), então o PHP monta a estrutura aninhada
     * padrão de $_FILES por sub-array.
     */
    private function _collectMadFlFiles(): array
    {
        if ($this->_madFlFilesCache !== null) {
            return $this->_madFlFilesCache;
        }
        $out = [];
        $f   = $_FILES['mad_fl_files'] ?? null;
        if (is_array($f) && isset($f['name']) && is_array($f['name'])) {
            foreach ($f['name'] as $key => $names) {
                if (!is_array($names)) {
                    continue;
                }
                foreach (array_keys($names) as $i) {
                    $out[$key][] = [
                        'name'     => $f['name'][$key][$i]     ?? '',
                        'tmp_name' => $f['tmp_name'][$key][$i] ?? '',
                        'error'    => $f['error'][$key][$i]    ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $f['size'][$key][$i]     ?? 0,
                    ];
                }
            }
        }
        $this->_madFlFilesCache = $out;
        return $out;
    }

    /**
     * Arquivos enviados para uma (detail, linha, coluna) específica.
     * Retorna apenas os uploads OK.
     *
     * @return array<int, array{name:string,tmp_name:string,error:int,size:int}>
     */
    private function _madFlFilesFor(string $detailName, string $rowId, string $field): array
    {
        if ($rowId === '') {
            return [];
        }
        $all   = $this->_collectMadFlFiles();
        $files = $all["{$detailName}__{$rowId}__{$field}"] ?? [];
        return array_values(array_filter(
            $files,
            fn ($f) => !empty($f['tmp_name']) && (int) ($f['error'] ?? 1) === UPLOAD_ERR_OK
        ));
    }

    /** Tipos do MadFormRegistry cujo VALOR nao viaja em $_POST. */
    private const UPLOAD_FIELD_TYPES = ['file', 'image', 'multi-file'];

    /**
     * Concilia dados e rules dos campos de UPLOAD antes de validar.
     *
     * O problema: validate() roda sobre $this->fields, que e o POST do
     * formulario — e um `<input type="file">` nao posta valor nenhum, o arquivo
     * vive em $_FILES. Uma coluna NOT NULL com imagem gerava
     * `'foto' => 'required'`, e o usuario via "campo obrigatorio" com o arquivo
     * visivelmente anexado na tela. A gravacao sempre esteve certa
     * (_processFileUploads mantem o valor existente quando nao ha arquivo
     * novo); so a validacao estava cega.
     *
     * Dois ajustes, ambos restritos aos campos de upload declarados no schema:
     *   - `required` passa a olhar $_FILES e o valor ja persistido; remocao
     *     explicita (`__mad_file_removed`) continua reprovando, que e o
     *     comportamento correto;
     *   - rules de TAMANHO de string (max/min/size) saem: com storage no disco
     *     o campo posta uma URL assinada de download, e em base64 posta a data
     *     URI inteira — nenhum dos dois e o que vai pro banco, entao medir o
     *     texto postado reprovava registro intocado.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $rules
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function _reconcileUploadRules(array $fields, array $rules): array
    {
        $schema = MadFormRegistry::fromRequest();
        if (empty($schema) || empty($rules)) {
            return [$fields, $rules];
        }

        $removed = $_POST['__mad_file_removed'] ?? [];

        foreach ($schema as $fieldName => $props) {
            if (!isset($rules[$fieldName])) continue;
            if (!in_array($props['type'] ?? '', self::UPLOAD_FIELD_TYPES, true)) continue;

            $rules[$fieldName] = self::_stripLengthRules($rules[$fieldName]);

            if (!empty($removed[$fieldName])) {
                $fields[$fieldName] = '';
                continue;
            }

            $hasNewFile = !empty($_FILES[$fieldName]['tmp_name'])
                && (int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            if ($hasNewFile && ($fields[$fieldName] ?? '') === '') {
                // Sentinela: o valor real (path ou base64) so existe depois do
                // upload. Aqui basta provar ao `required` que ha arquivo.
                $fields[$fieldName] = (string) $_FILES[$fieldName]['name'];
            }
        }

        return [$fields, $rules];
    }

    /** Remove max/min/size de uma rule (string pipe ou array). */
    private static function _stripLengthRules(mixed $rule): mixed
    {
        $drop = static fn ($r) => !(is_string($r) && preg_match('/^(max|min|size):/i', $r));

        if (is_string($rule)) {
            return implode('|', array_filter(explode('|', $rule), $drop));
        }
        if (is_array($rule)) {
            return array_values(array_filter($rule, $drop));
        }

        return $rule;
    }

    private function _processFileUploads(object $record): void
    {
        $schema  = MadFormRegistry::fromRequest();
        $removed = $_POST['__mad_file_removed'] ?? [];

        if (empty($schema)) {
            return;
        }

        foreach ($schema as $fieldName => $props) {
            $type    = $props['type'] ?? '';
            $storage = $props['storage'] ?? '';

            if (($type !== 'file' && $type !== 'image') || !$storage) {
                continue;
            }

            $nameColumn = $props['nameColumn'] ?? '';

            // Arquivo removido pelo usuário (clicou X no existente)
            if (!empty($removed[$fieldName])) {
                if ($storage === 'disk') {
                    // Deleta arquivo do storage (disco configurado E local legado)
                    MadUploadStorage::delete((string) ($record->$fieldName ?? ''));
                    $record->$fieldName = null;
                    if ($nameColumn) {
                        $record->$nameColumn = null;
                    }
                }
                // storage="db" → remoção tratada pelo _processBlobUpload no _afterStore
                // (requer prepared statement para limpar a coluna BLOB)
                continue;
            }

            // Sem arquivo novo enviado → mantém valor existente
            if (empty($_FILES[$fieldName]['tmp_name'])
                || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $originalName = $_FILES[$fieldName]['name'];
            // Sanitiza upfront para usar tanto no fs quanto no nameColumn —
            // tambem aborta upload se extensao proibida (php, htaccess, etc).
            $cleanName = $this->_sanitizeUploadName($originalName);

            if ($storage === 'disk') {
                // Deleta arquivo antigo do storage se existir
                MadUploadStorage::delete((string) ($record->$fieldName ?? ''));

                $folder   = $this->_normalizeUploadFolder((string) ($props['folder'] ?? 'uploads'));
                $fileName = $this->_buildFileName($originalName, $props['fileName'] ?? 'prefix', $record);
                $relPath  = "{$folder}/{$fileName}";          // path relativo gravado no banco

                MadUploadStorage::put($_FILES[$fieldName]['tmp_name'], $relPath);
                $record->$fieldName = $relPath;
            }

            if ($nameColumn) {
                // Armazena nome sanitizado (NAO o cru do POST — evita XSS quando
                // o nome e renderizado como link de download).
                $record->$nameColumn = $cleanName;
            }
        }
    }

    /**
     * Encapsula o ciclo completo de persistência: fillRecord + store + afterStore.
     *
     * - fillRecord(): preenche campos do form + processa single-file uploads
     * - store(): persiste o record no banco (gera ID se novo)
     * - _afterStore(): processa campos que dependem do ID (multi-file table, etc.)
     *
     * O dev pode setar campos extras ANTES de chamar save() — eles serão
     * preservados desde que não colidam com campos do formulário.
     *
     *   $record->negociacao_id = $this->negociacaoId;
     *   $this->form->save($record);
     *
     * $extra (simétrico ao fill($data, $extra)) aplica valores APÓS o
     * fromArray e ANTES do store — ideal pra coerções de unidade/formato que
     * divergem entre UI e banco (ex: progresso 0..100 → 0..1), normalização
     * de null, ou geração de PK slug. Cada valor pode ser literal ou Closure
     * `fn($currentValue, $record) => $newValue`:
     *
     *   $this->form->save($task, [
     *       'progress'  => max(0, min(1, $pct / 100)),          // literal
     *       'milestone' => fn($v) => !empty($v) && $v !== '0' ? 1 : 0,  // closure
     *       'id'        => $this->generateUniqueId('GanttTask', $data->name),
     *   ]);
     *
     * Alternativamente, $extra pode ser uma Closure ÚNICA `fn($record)` que
     * recebe o record já prepopulado e o muta direto (mais natural pra lógica
     * imperativa / cross-campo):
     *
     *   $this->form->save($task, function ($task) {
     *       $task->progress  = $task->progress > 1 ? $task->progress / 100 : $task->progress;
     *       $task->owner_id  = trim((string) $task->owner_id) ?: null;
     *       if (empty($task->id)) $task->id = $this->generateUniqueId('GanttTask', (string) $task->name);
     *   });
     *
     * @param object         $record Record a persistir
     * @param array|\Closure $extra  Array campo=>valor|Closure (pós-fromArray),
     *                               ou Closure única fn($record) que muta o record
     * @return object O próprio record (para encadeamento)
     */
    public function save(object $record, array|\Closure $extra = []): object
    {
        $this->fillRecord($record);

        if ($extra instanceof \Closure) {
            // Closure única: recebe o record já prepopulado (pós-fillRecord) e
            // o muta direto, ANTES do store. Não precisa devolver nada — o
            // próprio $record é a saída. Ideal pra coerções de unidade/formato
            // (progress 0..100 → 0..1), null e PK slug, sem montar array.
            //   $this->form->save($task, function ($task) {
            //       $task->progress = $task->progress / 100;
            //       if (empty($task->id)) $task->id = $this->generateUniqueId(...);
            //   });
            $extra($record);
        } else {
            foreach ($extra as $field => $value) {
                $record->$field = $value instanceof \Closure
                    ? $value($record->$field ?? null, $record)
                    : $value;
            }
        }

        try {
            $record->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw $this->_uniqueViolation($e, $record);
        } catch (\Mad\Database\UnitScopeViolation) {
            throw $this->_unitViolation();
        }
        $this->_afterStore($record);
        return $record;
    }

    /**
     * unit_id de uma unidade que não é do usuário (BelongsToUnit) → erro no
     * campo `unit_id`; sem o campo na tela vira erro órfão e o asInline() leva
     * a mensagem pro toast — nunca o diálogo de erro técnico.
     */
    private function _unitViolation(): MadValidationException
    {
        try {
            $message = mad_t('mad.error.unit_not_allowed');
        } catch (\Throwable) {
            $message = '';
        }
        if ($message === '' || $message === 'mad.error.unit_not_allowed') {
            $message = 'Você não tem acesso à unidade escolhida.';
        }

        return new MadValidationException(
            ['unit_id' => $message],
            ['unit_id'],
            '',
            $this->_knownFieldNames($this->fields),
        );
    }

    /**
     * Valor repetido recusado pelo ÍNDICE ÚNICO do banco → erro no campo, com a
     * mesma mensagem da rule `unique` ("O campo CPF já está sendo utilizado.").
     *
     * Sem isto a exceção subia até o `catch (\Throwable)` do `onSave` e o
     * usuário via o SQL cru num diálogo. Como MadValidationException, o
     * `catch (MadValidationException $e) { return $e->asInline(); }` que todo
     * form já tem pinta o campo — e o `DB::transaction()` em volta desfaz o que
     * veio antes. Coluna que a tela não tem (ou índice não identificado) vira
     * erro órfão: o asInline() leva a mensagem pro toast.
     */
    private function _uniqueViolation(\Illuminate\Database\UniqueConstraintViolationException $e, object $record): MadValidationException
    {
        $known   = $this->_knownFieldNames($this->fields);
        $labels  = $this->_fieldLabels();
        $columns = MadUniqueViolation::columns(
            $e,
            $record instanceof \Illuminate\Database\Eloquent\Model ? $record : null,
        );

        $errors = [];
        foreach ($columns as $column) {
            if (in_array($column, $known, true)) {
                $errors[$column] = MadValidator::ruleMessage('unique', (string) ($labels[$column] ?? $column));
            }
        }

        if ($errors === []) {
            $errors[$columns[0] ?? '__unique'] = self::_duplicateRecordMessage();
        }

        return new MadValidationException($errors, array_keys($errors), '', $known);
    }

    /** "Já existe um registro com estes dados." */
    private static function _duplicateRecordMessage(): string
    {
        try {
            $message = mad_t('mad.error.duplicate');
        } catch (\Throwable) {
            $message = '';
        }

        // Chave ausente no catálogo o mad_t devolve a própria chave.
        return $message !== '' && $message !== 'mad.error.duplicate'
            ? $message
            : 'Já existe um registro com estes dados.';
    }

    /**
     * Processa campos que dependem do registro já estar persistido (ter ID).
     * Chamado automaticamente por save() após store().
     *
     * Hooks pós-store:
     * - single-file storage="db": salva BLOB via prepared statement
     * - multi-file: salva arquivos (disco ou BLOB) conforme mode e storage
     */
    private function _afterStore(object $record): void
    {
        $schema = MadFormRegistry::fromRequest();

        if (!empty($schema)) {
            foreach ($schema as $fieldName => $props) {
                $type    = $props['type'] ?? '';
                $storage = $props['storage'] ?? '';

                // multi-file (mode comma OU table) é tratado PRIMEIRO — senão o
                // mode="table" cairia no ramo de pivot de multi-select abaixo
                // (que sai vazio por faltar pivotModel/itemKey) e os arquivos
                // nunca seriam persistidos.
                if ($type === 'multi-file') {
                    if ($storage) {
                        $this->_processMultiFileUpload($record, $fieldName, $props);
                    }
                    continue;
                }

                // Multi-select table mode — sync pivot table
                if (($props['mode'] ?? '') === 'table') {
                    $this->_processPivotTable($record, $fieldName, $props);
                    continue;
                }

                if (!$storage) {
                    continue;
                }

                if (($type === 'file' || $type === 'image') && $storage === 'db') {
                    $this->_processBlobUpload($record, $fieldName, $props);
                }
            }
        }

        // Auto-save field-lists e detail-forms com model + foreign-key
        $this->_autoSaveDetails($record);
    }

    /**
     * Deriva a coluna FK do pai por convenção quando `foreign-key` não foi
     * informado no componente: singular da tabela do record + '_id'
     * (pessoa → pessoa_id). Usada no save (_processPivotTable) e no
     * auto-load do render (MadRenderContext::loadPivotSelected) — os dois
     * lados TÊM que derivar igual, senão o edit mostra vazio e o save grava.
     * Devolve '' quando o record não expõe tabela (não-Eloquent).
     */
    public static function derivePivotForeignKey(?object $record): string
    {
        if (!$record || !method_exists($record, 'getTable')) {
            return '';
        }
        $table = (string) $record->getTable();
        if ($table === '') {
            return '';
        }
        return \Illuminate\Support\Str::singular($table) . '_id';
    }

    /**
     * Sincroniza tabela pivot para multi-select com mode=table.
     * Deleta registros existentes e insere os selecionados (idempotente).
     */
    private function _processPivotTable(object $record, string $fieldName, array $props): void
    {
        $pivotModel = $props['pivotModel'] ?? '';
        $foreignKey = $props['foreignKey'] ?? '';
        $itemKey    = $props['itemKey']    ?? '';

        // FK do pai omitida → deriva por convenção da tabela do record.
        $fkDerived = false;
        if (!$foreignKey) {
            $foreignKey = self::derivePivotForeignKey($record);
            $fkDerived  = $foreignKey !== '';
        }

        if (!$pivotModel || !$foreignKey || !$itemKey) {
            \Illuminate\Support\Facades\Log::warning(
                "MadForm: campo '{$fieldName}' mode=table sem pivot-model/foreign-key/item-key — pivô NÃO sincronizado."
            );
            return;
        }

        // FK derivada por convenção: valida contra a tabela antes do delete —
        // um palpite errado apagaria nada e falharia o INSERT com 500 depois
        // do pai já salvo. Prop explícita errada continua estourando alto.
        if ($fkDerived) {
            try {
                $probe = new $pivotModel();
                $hasColumn = \Illuminate\Support\Facades\Schema::connection($probe->getConnectionName())
                    ->hasColumn($probe->getTable(), $foreignKey);
            } catch (\Throwable $e) {
                $hasColumn = false;
            }
            if (!$hasColumn) {
                \Illuminate\Support\Facades\Log::warning(
                    "MadForm: campo '{$fieldName}' mode=table sem foreign-key e a convenção '{$foreignKey}' não existe na pivot — informe foreign-key no componente. Pivô NÃO sincronizado."
                );
                return;
            }
        }

        $pk       = $record->getKeyName();
        $parentId = $record->$pk;

        // IDs selecionados (do MadWire via $this->fields, ou fallback $_POST)
        $selectedIds = $this->fields[$fieldName] ?? $_POST[$fieldName] ?? [];
        if (is_string($selectedIds)) {
            // JSON array string do MadWire (ex: '["5","4"]')
            if (str_starts_with($selectedIds, '[')) {
                $selectedIds = json_decode($selectedIds, true) ?: [];
            } else {
                $selectedIds = $selectedIds !== '' ? explode(',', $selectedIds) : [];
            }
        }
        $selectedIds = array_filter(array_map('trim', (array) $selectedIds), fn($v) => $v !== '');

        // Delete all + insert selected
        $pivotModel::where($foreignKey, $parentId)->delete();

        foreach ($selectedIds as $itemId) {
            $pivot = new $pivotModel();
            $pivot->$foreignKey = $parentId;
            $pivot->$itemKey    = $itemId;
            $pivot->save();
        }
    }

    /**
     * Salva um arquivo como BLOB base64 no banco de dados.
     * Requer que o record já esteja persistido (tem ID).
     *
     * Suporta MySQL (LONGBLOB), PostgreSQL (BYTEA), SQL Server (VARBINARY), Oracle (BLOB).
     */
    private function _processBlobUpload(object $record, string $fieldName, array $props): void
    {
        $removed    = $_POST['__mad_file_removed'] ?? [];
        $nameColumn = $props['nameColumn'] ?? '';

        // Arquivo removido pelo usuário
        if (!empty($removed[$fieldName])) {
            if ($nameColumn) {
                $record->$nameColumn = null;
            }
            unset($record->$fieldName);
            $record->save();
            // Limpa o BLOB no banco via NULL
            $this->_saveBlobToDb($record, $fieldName, '');
            return;
        }

        // Sem arquivo novo enviado → mantém existente
        if (empty($_FILES[$fieldName]['tmp_name'])
            || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        $originalName = $_FILES[$fieldName]['name'];
        $content      = base64_encode(file_get_contents($_FILES[$fieldName]['tmp_name']));

        // Atualiza nome do arquivo via ORM
        if ($nameColumn) {
            $record->$nameColumn = $originalName;
            // Evita que o ORM tente gravar o campo BLOB (vai por prepared statement)
            unset($record->$fieldName);
            $record->save();
        }

        // Grava BLOB via prepared statement (driver-specific)
        $this->_saveBlobToDb($record, $fieldName, $content);
    }

    /**
     * Grava conteúdo base64 em uma coluna BLOB via prepared statement.
     * Suporta MySQL, PostgreSQL, SQLite, SQL Server, Oracle.
     */
    private function _saveBlobToDb(object $record, string $column, string $base64Content): void
    {
        $pk      = $record->getKeyName();
        $pkValue = $record->$pk;
        $table   = $record->getTable();
        $dbConn  = \Illuminate\Support\Facades\DB::connection($record->getConnectionName());
        $conn    = $dbConn->getPdo();
        $driver  = $dbConn->getDriverName();

        // String vazia → NULL (remoção de arquivo)
        $value = $base64Content !== '' ? $base64Content : null;

        if (in_array($driver, ['sqlsrv', 'dblib'])) {
            $stmt = $conn->prepare(
                "UPDATE {$table} SET {$column} = " . ($value ? "CONVERT(varbinary(max), ?)" : "NULL") . " WHERE {$pk} = ?"
            );
            if ($value) {
                $stmt->bindParam(1, $value, \PDO::PARAM_LOB);
                $stmt->bindParam(2, $pkValue);
            } else {
                $stmt->bindParam(1, $pkValue);
            }
            $stmt->execute();
        } elseif ($driver === 'oci') {
            $attr = $value ? 'empty_blob()' : 'NULL';
            $stmt = $conn->prepare(
                "UPDATE {$table} SET {$column} = {$attr} WHERE {$pk} = ? RETURNING {$column} INTO ?"
            );
            $blob = null;
            $stmt->bindParam(1, $pkValue);
            $stmt->bindParam(2, $blob, \PDO::PARAM_LOB);
            $stmt->execute();
            if ($value) {
                fwrite($blob, $value);
                fclose($blob);
            }
        } else {
            // MySQL, PostgreSQL, SQLite, Firebird
            $stmt = $conn->prepare("UPDATE {$table} SET {$column} = ? WHERE {$pk} = ?");
            $stmt->bindParam(1, $value, $value ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
            $stmt->bindParam(2, $pkValue);
            $stmt->execute();
        }
    }

    /**
     * Carrega um BLOB do banco para um arquivo temporário.
     * Útil no onEdit() para que o formulário possa referenciar o arquivo existente.
     *
     *   $this->form->loadBlob($record, 'conteudo_arquivo', 'nome_arquivo');
     *   $this->form->fill($record);
     */
    public function loadBlob(object $record, string $blobColumn, string $nameColumn): ?string
    {
        $pk      = $record->getKeyName();
        $pkValue = $record->$pk;
        $table   = $record->getTable();
        $conn    = \Illuminate\Support\Facades\DB::connection($record->getConnectionName())->getPdo();

        $stmt = $conn->prepare(
            "SELECT {$blobColumn}, {$nameColumn} FROM {$table} WHERE {$pk} = ?"
        );
        $stmt->bindParam(1, $pkValue);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        if (!$row || !$row[0]) {
            return null;
        }

        $lob      = is_string($row[0]) ? $row[0] : stream_get_contents($row[0]);
        $binary   = base64_decode($lob);
        $fileName = $row[1] ?? 'arquivo';

        // Scratch de edição via Storage (disco mad_tmp) — chave relativa.
        $key = "blob/{$table}/{$pkValue}/{$fileName}";
        \Mad\Service\MadScratchStorage::putContent($key, $binary);

        $record->$blobColumn = $key;

        return $key;
    }

    /**
     * Processa upload de múltiplos arquivos.
     *
     * storage="disk":
     *   mode="comma": salva arquivos no disco, grava caminhos separados por vírgula.
     *   mode="table": salva arquivos no disco, cria registro por arquivo na tabela relacionada.
     *
     * storage="db":
     *   mode="table": salva BLOB base64 por arquivo na tabela relacionada.
     */
    private function _processMultiFileUpload(object $record, string $fieldName, array $props): void
    {
        $storage    = $props['storage'] ?? 'disk';
        $rawFolder  = (string) ($props['folder'] ?? 'uploads');
        $mode       = $props['mode'] ?? 'comma';
        $nameColumn = $props['nameColumn'] ?? '';

        if (!in_array($storage, ['disk', 'db'])) {
            return;
        }

        // Normaliza folder uma vez (rejeita .., paths absolutos, null bytes)
        $folder = ($storage === 'disk') ? $this->_normalizeUploadFolder($rawFolder) : $rawFolder;

        // ── mode=comma: paths existentes mantidos pelo client + novos uploads ──
        if ($mode === 'comma' && $storage === 'disk') {
            // Paths existentes que o usuário MANTEVE (enviados via hidden inputs)
            $keptPaths = $_POST['__mad_existing_files'][$fieldName] ?? [];
            if (is_string($keptPaths)) {
                $keptPaths = [$keptPaths];
            }

            // Paths existentes no banco (para detectar removidos e deletar do disco)
            $oldPaths = [];
            $oldValue = trim((string) ($record->$fieldName ?? ''));
            if ($oldValue !== '') {
                $oldPaths = array_filter(array_map('trim', explode(',', $oldValue)));
            }
            $removedPaths = array_diff($oldPaths, $keptPaths);
            foreach ($removedPaths as $rp) {
                MadUploadStorage::delete((string) $rp);
            }

            // Novos uploads
            $newPaths = [];
            $files = $_FILES[$fieldName] ?? [];
            if (!empty($files['tmp_name']) && is_array($files['tmp_name'])) {
                $count = count($files['tmp_name']);
                for ($i = 0; $i < $count; $i++) {
                    if (empty($files['tmp_name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    // _buildFileName ja sanitiza e bloqueia extensoes proibidas.
                    $fileName  = $this->_buildFileName($files['name'][$i], $props['fileName'] ?? 'prefix', $record);
                    $filePath  = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                    MadUploadStorage::put($files['tmp_name'][$i], $filePath);
                    $newPaths[] = $filePath;
                }
            }

            // Junta mantidos + novos
            $allPaths = array_merge($keptPaths, $newPaths);
            $record->$fieldName = !empty($allPaths) ? implode(',', $allPaths) : null;
            $record->save();
            return;
        }

        // ── mode=table: registros na tabela filha ──────────────────────────
        if ($mode === 'table') {
            $model      = $props['model'] ?? '';
            $foreignKey = $props['foreignKey'] ?? '';
            $pathColumn = $props['pathColumn'] ?? '';

            if (!$model || !$foreignKey || !$pathColumn) {
                return;
            }

            $pk = $record->getKeyName();

            // Reconciliação na edição (disk): mantém os arquivos existentes
            // enviados em __mad_existing_files e deleta (linha + arquivo no disco)
            // os que o usuário removeu. Sem isto, a edição nunca apagava anexos
            // antigos e re-uploads duplicavam.
            if ($storage === 'disk') {
                $kept = $_POST['__mad_existing_files'][$fieldName] ?? [];
                if (is_string($kept)) {
                    $kept = [$kept];
                }
                $kept = array_filter(array_map('strval', (array) $kept), fn ($v) => $v !== '');
                foreach ($model::where($foreignKey, $record->$pk)->get() as $existingChild) {
                    $cp = (string) ($existingChild->$pathColumn ?? '');
                    if (!in_array($cp, $kept, true)) {
                        MadUploadStorage::delete($cp);
                        $existingChild->delete();
                    }
                }
            }

            $files = $_FILES[$fieldName] ?? [];
            if (empty($files['tmp_name']) || !is_array($files['tmp_name'])) {
                return;
            }

            $count = count($files['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['tmp_name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $originalName = $files['name'][$i];
                // Sanitiza upfront — usado tanto no fs quanto no nameColumn.
                $cleanName    = $this->_sanitizeUploadName($originalName);

                $child = new $model();
                $child->$foreignKey = $record->$pk;

                if ($nameColumn) {
                    $child->$nameColumn = $cleanName;
                }

                // Metadados do upload (original_name / size / mime_type / disk).
                // Tabelas de anexo do mundo real declaram essas colunas NOT NULL
                // — antes o INSERT saía só com (fk, name, path) e estourava
                // "NOT NULL constraint failed: ticket_attachments.original_name",
                // empurrando o dev pro upload manual. Mapeamento explícito via
                // props (`original-name-column` etc.) ou, sem prop, por
                // CONVENÇÃO quando a coluna existe na tabela filha.
                $this->_fillUploadMeta($child, $props, [
                    'original' => $originalName,
                    'size'     => (int) ($files['size'][$i] ?? 0),
                    'mime'     => (string) ($files['type'][$i] ?? ''),
                    'tmp'      => (string) $files['tmp_name'][$i],
                ]);

                if ($storage === 'disk') {
                    $fileName = $this->_buildFileName($originalName, $props['fileName'] ?? 'prefix', $record);
                    $filePath = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                    MadUploadStorage::put($files['tmp_name'][$i], $filePath);
                    $child->$pathColumn = $filePath;
                    $child->save();
                } elseif ($storage === 'db') {
                    $child->save();
                    $content = base64_encode(file_get_contents($files['tmp_name'][$i]));
                    $this->_saveBlobToDb($child, $pathColumn, $content);
                }
            }
        }
    }

    /**
     * Valida os campos do formulário com as regras do illuminate/validation.
     *
     * Retorna array de erros ['campo' => 'mensagem'] ou [] se válido.
     * Mensagens em pt-BR por padrão.
     *
     * Uso recomendado — rules no método da classe (disponível em qualquer action):
     *
     *   protected function rules(): array {
     *       return [
     *           'nome'  => 'required|string|max:255',
     *           'email' => 'required|email',
     *       ];
     *   }
     *
     *   public function salvar(): MadResponse {
     *       $errors = $this->form->validate($this->rules());
     *       if ($errors) {
     *           $response = new MadResponse();
     *           foreach ($errors as $field => $msg) {
     *               $response->fieldError($field, $msg);
     *           }
     *           return $response->merge(MadToast::warning('Corrija os erros.'));
     *       }
     *   }
     *
     * @param array $rules    Regras illuminate (ex: ['nome' => 'required|string|max:255'])
     * @param array $messages Mensagens customizadas por regra (opcional)
     * @param array $attrs    Nomes amigáveis dos atributos (opcional)
     * @return array          ['campo' => 'primeira mensagem'] ou [] se válido
     */
    /**
     * Valida os campos do formulário.
     *
     * As chaves das rules podem conter o label do campo após '|':
     *   'nome|Nome do Produto' => 'required|string|max:255'
     *
     * O label é extraído automaticamente e usado nas mensagens de erro.
     * Se $attrs for informado explicitamente, tem prioridade.
     *
     * @throws MadValidationException se houver erros de validação
     */
    public function validate(array $rules, array $messages = [], array $attrs = []): static
    {
        // Extrai labels de chaves no formato 'campo|Label'
        $parsedRules = [];
        foreach ($rules as $key => $rule) {
            if (str_contains($key, '|') && !isset($attrs[$key])) {
                [$field, $label] = explode('|', $key, 2);
                $parsedRules[$field] = $rule;
                if (!isset($attrs[$field])) {
                    $attrs[$field] = $label;
                }
            } else {
                $parsedRules[$key] = $rule;
            }
        }

        $attrs = $this->_fieldLabels($attrs);

        [$data, $parsedRules] = $this->_reconcileUploadRules($this->_withoutMask($this->fields), $parsedRules);

        $errors = MadValidator::validate($data, $parsedRules, $messages, $attrs);

        if ($errors) {
            throw new MadValidationException(
                $errors,
                array_keys($parsedRules),
                '',
                $this->_knownFieldNames($data),
            );
        }

        return $this;
    }

    /**
     * Campos que o FORMULÁRIO realmente tem — schema registrado no render
     * (MadFormRegistry) unido às chaves postadas.
     *
     * Serve pro MadValidationException detectar erro ÓRFÃO: rule sobre um campo
     * que a tela não renderiza (típico de `Model::rules()` cobrindo coluna
     * NOT NULL que o form não expõe — `unit_id`/`tenant_id`, coluna nova depois
     * do CRUD gerado). O `fieldError()` mira `[data-field-error="campo"]`; sem
     * o campo na tela o seletor não casa e a mensagem some — sobrava só
     * "Corrija os erros antes de continuar." e nenhum campo marcado.
     *
     * União (schema ∪ POST) de propósito: campo que não posta (checkbox
     * desmarcado) está no schema; campo injetado por `set()` fora do schema
     * está no POST. Órfão = ausente nos DOIS.
     *
     * @param  array<string,mixed>  $data  payload validado
     * @return array<int,string>
     */
    private function _knownFieldNames(array $data): array
    {
        $schema = MadFormRegistry::fromRequest();
        $names = is_array($schema) ? array_keys($schema) : [];

        return array_values(array_unique(array_merge($names, array_keys($data))));
    }

    /**
     * Campos com `strip-mask` sem a máscara — o valor que o save grava. A
     * validação confere ESTE valor: `max:11` da coluna do CPF não pode reprovar
     * o '123.456.789-01' digitado, e `unique` tem que comparar com as linhas
     * gravadas sem pontuação.
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    private function _withoutMask(array $fields): array
    {
        $schema = MadFormRegistry::fromRequest();
        foreach (is_array($schema) ? $schema : [] as $name => $props) {
            if (array_key_exists($name, $fields) && is_array($props)) {
                $fields[$name] = MadFormRegistry::stripMaskValue($fields[$name], $props);
            }
        }

        return $fields;
    }

    /**
     * Rótulo de cada campo para as mensagens de erro ("O campo CPF…", não
     * "O campo cpf…"). Precedência, da mais forte:
     *   1. `$attrs` explícito / chave `'campo|Rótulo'` da rule;
     *   2. `mad_labels` — o texto do `<label>` que o MadWire coleta na tela;
     *   3. `label` que o campo registrou no schema do form (servidor).
     *
     * O 3º é o que faltava: sem ele, qualquer POST sem `mad_labels` (JS antigo,
     * chamada manual) caía no nome da coluna. E antes um único `'campo|Rótulo'`
     * nas rules descartava o `mad_labels` INTEIRO — os outros campos voltavam ao
     * nome cru. Agora as três fontes se somam.
     *
     * @param  array<string,string>  $attrs
     * @return array<string,string>
     */
    private function _fieldLabels(array $attrs = []): array
    {
        $posted = $_POST['mad_labels'] ?? [];
        $labels = $attrs + (is_array($posted) ? $posted : []);

        $schema = MadFormRegistry::fromRequest();
        foreach (is_array($schema) ? $schema : [] as $name => $props) {
            $label = trim((string) ($props['label'] ?? ''));
            if ($label !== '' && !isset($labels[$name])) {
                $labels[$name] = $label;
            }
        }

        return $labels;
    }

    /**
     * Obtém um campo individual — data e hora no mesmo formato de
     * `getData()->campo`, o do banco.
     *
     * `<mad-date-field>`/`<mad-datetime-field>` postam o texto digitado
     * ("24/09/2026"). `getData()` sempre o entregou no formato do banco
     * ("2026-09-24", conforme o `database-mask` do campo); `get()` devolvia
     * o texto cru. O mesmo `get('data')` voltava em dois formatos: o do banco
     * ao abrir o registro (`fill`) ou depois de um `set()`, o de exibição
     * depois de qualquer ação da tela — e `new DateTime($this->form->get('data'))`
     * quebrava com dia acima de 12 e trocava dia e mês nos demais (o PHP lê
     * n/n/n como mês/dia/ano), sem erro.
     *
     * Só o formato muda, e só em campo de data/hora do formulário postado:
     * vazio continua vazio, os outros tipos continuam como chegaram (número
     * já vem sem máscara) e o `$value` que a ação `mad:change` do próprio
     * campo recebe continua como digitado. Valor já no formato do banco
     * passa igual.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        $value = $this->fields[$field] ?? $default;
        if (is_string($value) && isset($this->fields[$field]) && MadFormRegistry::isDisplayDate($value)) {
            $props = MadFormRegistry::requestFieldProps($field);
            if (in_array($props['type'] ?? null, ['date', 'datetime'], true)) {
                return MadFormRegistry::transformField($value, $props);
            }
        }

        return $value;
    }

    /**
     * Define um campo individual.
     *
     * Convencao: nome terminado em `[]` indica campo de field-list — gera op
     * `fl_val` que sera roteada pela JS para a row de origem do change event
     * (via `el.closest('.mad-fl-row')`). So funciona dentro de actions
     * disparadas por `data-mad-fl-change` (onChange de coluna do field-list).
     *
     *   // Form principal
     *   $this->form->set('nome', 'Joao');
     *
     *   // Field-list (no onChange de coluna — patcha row de origem)
     *   public function onChangeProduto($value): void
     *   {
     *       $p = new Produto($value);
     *       $this->form->set('valor[]', $p->preco);
     *       $this->form->set('unidade[]', $p->unidade);
     *   }
     */
    public function set(string $field, mixed $value): void
    {
        if (str_ends_with($field, '[]')) {
            $this->_pendingFlOps[] = [
                'op'      => 'fl_val',
                'target'  => substr($field, 0, -2),
                'content' => (string) $value,
            ];
            return;
        }
        $this->fields[$field] = $value;
        $this->_dataCache = null;
    }

    /**
     * Substitui todas as rows de um field-list (sem re-render do componente).
     *
     * Equivalente a `FieldListColumn::setRows()` mas via auto-bind do MadForm.
     * Aceita array de stdClass / models Eloquent / arrays — normaliza e injeta `__id`
     * para estabilidade do `:key` no Alpine x-for.
     *
     *   public function onCarregarParcelas(): void
     *   {
     *       $rows = Parcela::where('pedido_id', '=', $this->pedidoId)->get();
     *       $this->form->setRows('parcelas', $rows);
     *   }
     *
     * @param string $name Nome do field-list (atributo `name` do componente)
     * @param array  $rows Rows a definir
     */
    public function setRows(string $name, array $rows): void
    {
        $this->_pendingFlOps[] = [
            'op'     => 'fl_rows',
            'target' => $name,
            'rows'   => FieldListColumn::normalizeRows($rows),
        ];
    }

    /**
     * Carrega options de um campo via model Eloquent (atalho de ModelOptionsLoader).
     * Funciona tanto para form principal quanto para field-list (`nome[]`).
     *
     * Form principal — emite op `reload_combo` via `setItems`:
     *   $this->form->loadOptionsFromModel('cidade_id', 'Cidade');
     *
     * Field-list (no onChange) — emite op `fl_combo` na row de origem:
     *   $this->form->loadOptionsFromModel('produto_id[]', 'Produto');
     *
     * @param string          $field    Nome do campo (com `[]` para field-list)
     * @param string          $model    Classe do model Eloquent (a conexão vem do model)
     * @param string          $key      Campo PK
     * @param string          $display  Campo de exibicao
     * @param string|null     $orderBy  Ordenacao (null = display)
     */
    public function loadOptionsFromModel(
        string      $field,
        string      $model,
        string      $key      = 'id',
        string      $display  = 'nome',
        ?string     $orderBy  = null
    ): void {
        $items = \Mad\Form\ModelOptionsLoader::items(
            $model, $key, $display, $orderBy
        );

        $this->setItems($field, $items);
    }

    /**
     * Define as options de um campo de coleção (combo, radio, checkbox-group,
     * multi-entry, sort-list, checklist) e opcionalmente pré-seleciona valor(es).
     *
     * Ao alterar o bucket `items`, o MadComponentHandler detecta o diff no
     * auto-bind e emite a op `reload_*` correta baseada no tipo do campo
     * registrado no MadFormRegistry durante o render.
     *
     *   // Combo
     *   $this->form->setItems('unit_id', ['1' => 'Matriz', '2' => 'Filial'], '1');
     *
     *   // Checkbox group (seleção múltipla)
     *   $this->form->setItems('perms', ['read' => 'Ler', 'write' => 'Escrever'], ['read']);
     *
     *   // Combo com placeholder
     *   $this->form->setItems('cidade_id', $cidades, null, '— Selecione —');
     *
     * @param string              $field       Nome do campo
     * @param array               $items       Mapa [value => label]
     * @param string|array|null   $selected    Valor(es) a pré-selecionar
     * @param string|null         $placeholder Texto da option vazia (apenas combo)
     */
    public function setItems(string $field, array $items, string|array|null $selected = null, ?string $placeholder = null): void
    {
        // Campo de field-list: nome termina com []
        if (str_ends_with($field, '[]')) {
            $cleanName = substr($field, 0, -2);
            $this->_pendingFlOps[] = [
                'op'      => 'fl_combo',
                'target'  => $cleanName,
                // Combo do field-list: lista de objetos [['value' => …, 'label' => …]]
                // vira mapa (\Mad\Support\MadItems) — o JS lê chave => texto.
                'options' => \Mad\Support\MadItems::normalize($items),
            ];
            return;
        }

        $this->items[$field] = $items;

        if ($placeholder !== null) {
            $this->placeholders[$field] = $placeholder;
        }

        if ($selected !== null) {
            $this->fields[$field] = $selected;
            $this->_dataCache = null;
        }
    }

    /**
     * Esconde um elemento no DOM.
     *
     *   $this->form->hide('nome');                  // campo (default)
     *   $this->form->hide('aba_extras', 'tab');     // tab inteira (botão + panel)
     *   $this->form->hide($rowId,       'row');     // linha de detail-form/field-list
     *
     * Ao mudar o bucket `hidden`, o MadComponentHandler emite op `mad_hide`
     * com o seletor correto baseado no scope.
     *
     * @param string $name  Nome do campo, tab ou ID da row
     * @param string $scope 'field' | 'tab' | 'row'
     */
    public function hide(string $name, string $scope = 'field'): void
    {
        $this->hidden[$name] = $scope;
    }

    /**
     * Inverso de hide(). O scope é necessário apenas por simetria — o nome é
     * suficiente para localizar a entrada no bucket, mas passamos scope
     * também para manter a API consistente.
     */
    public function show(string $name, string $scope = 'field'): void
    {
        unset($this->hidden[$name]);
    }

    /**
     * Marca um campo como read-only. Diferente de disabled: o valor continua
     * sendo enviado no POST.
     *
     *   $this->form->readonly('email');
     *
     * Aplicado:
     * - <input>/<textarea>: atributo `readonly` nativo
     * - <select> + MAD Select: classe `.mad-readonly-select` + `_madSelect.lock()`
     * - checkbox/radio/switch: classe `.mad-readonly` (pointer-events:none)
     */
    public function readonly(string $name): void
    {
        $this->readonly[$name] = true;
    }

    /** Inverso de readonly(). */
    public function editable(string $name): void
    {
        unset($this->readonly[$name]);
    }

    /**
     * Desabilita um botão (aplica atributo `disabled` no `<button data-mad-btn="X">`).
     * Bloqueia clique e o hover-efeito. Use para botões de ação como "Salvar",
     * "Aprovar", etc, quando o registro está em estado que não permite a ação.
     *
     *   $this->form->disable('btn_salvar');
     */
    public function disable(string $name): void
    {
        $this->disabled[$name] = true;
    }

    /** Inverso de disable(). */
    public function enable(string $name): void
    {
        unset($this->disabled[$name]);
    }

    /**
     * Põe o cursor num campo, pelo `name`. Funciona na abertura da tela
     * (mount()/onEdit()) e dentro de uma ação.
     *
     *   public function mount(array $params = []): void
     *   {
     *       $this->form = new MadForm('form');
     *       $this->form->focus('nome');           // tela nova
     *       if (! empty($params['id'])) {
     *           $this->onEdit($params['id']);
     *       }
     *   }
     *
     *   public function onEdit(int|string $id): void
     *   {
     *       // ...
     *       $this->form->focus('cpf');            // edição
     *   }
     *
     * Só um campo tem o cursor: vale a última chamada.
     */
    public function focus(string $name): void
    {
        $this->_pendingFocus = $name;
    }

    /**
     * Registra o campo marcado com `autofocus` no Blade (chamado no render,
     * pelo próprio campo). Com mais de um campo marcado vale o primeiro.
     */
    public function autofocus(string $name): void
    {
        $this->_autofocus ??= $name;
    }

    /**
     * Retira o foco da abertura da tela (null = nenhum): o `focus()` do código
     * ou, na falta dele, o campo com `autofocus` no Blade. Usado pelo
     * MadComponent, onde não há resposta de ação para carregar o op.
     */
    public function pullPendingFocus(): ?string
    {
        $name = $this->_pendingFocus ?? $this->_autofocus;
        $this->_pendingFocus = $this->_autofocus = null;

        return $name;
    }

    /**
     * Retorna referência a um detail-form registrado no MadFormRegistry.
     *
     * O detail-form se auto-registra ao renderizar (via detail-form.blade.php).
     * O objeto retornado permite validar, computar evaluates e responder.
     *
     * Uso:
     *   $df = $this->form->getDetailForm('itens');
     *   return $df->processRow($rowData, $editIndex);
     *
     *   // Com rules customizadas:
     *   $df = $this->form->getDetailForm('itens')
     *       ->rules(['ingrediente' => 'required', 'quantidade' => 'required|numeric']);
     *   return $df->processRow($rowData, $editIndex);
     *
     * @param string $name Nome do detail-form (atributo name="" do <mad-detail-form>)
     */
    public function getDetailForm(string $name): MadDetailFormRef
    {
        $registered = MadFormRegistry::getDetailForm($name);

        return new MadDetailFormRef(
            $name,
            $registered['columns'] ?? [],
            $registered['model']   ?? ''
        );
    }

    /**
     * Retorna as rows de um field-list a partir do POST.
     *
     * Os campos são descobertos automaticamente via MadFormRegistry
     * (registrados pelo template field-list.blade.php no render).
     *
     * Uso:
     *   $rows = $this->form->getFieldList('acoes');
     *   // [['act_name' => 'Editar', 'act_method' => 'onEdit'], ...]
     *
     * @param string $name Nome do field-list (atributo name="" do <mad-field-list>)
     * @return array Rows não-vazias: [['campo1' => val, 'campo2' => val], ...]
     */
    public function getFieldList(string $name): array
    {
        $data = $this->fields[$name] ?? [];
        if (!is_array($data)) {
            return [];
        }

        // Filtra rows vazias
        $rows = array_values(array_filter($data, function ($row) {
            if (!is_array($row)) return false;
            foreach ($row as $val) {
                if (trim((string) $val) !== '') return true;
            }
            return false;
        }));

        // force-case / strip-mask declarados na coluna. Aplicado AQUI porque
        // este é o ponto por onde passam tanto o _autoSaveDetails() quanto o
        // código do usuário — aplicar só no getData() deixaria o auto-save
        // gravando o valor mascarado.
        return MadFormRegistry::transformFieldListRows($name, $rows);
    }

    // ── Detail auto-save/load hooks ─────────────────────────────────────────

    /**
     * Registra um hook pre-store para cada linha de um detail (field-list ou detail-form).
     * Equivalente ao parâmetro $each do saveDetailItems().
     *
     * O callable recebe:
     *   $detalhe  — instância do model filho, já populada com os dados do form
     *   $mestre   — o model pai (já com ID)
     *   $formRow  — array cru vindo do formulário (para acessar valores não mapeados)
     *
     * @param string   $name Nome do field-list ou detail-form
     * @param callable $fn   fn($detalhe, $mestre, $formRow): void
     */
    public function onSaveDetail(string $name, callable $fn): static
    {
        $this->_detailSaveHooks[$name] = $fn;
        return $this;
    }

    /**
     * Registra um hook de transform para auto-load de um detail (field-list ou detail-form).
     * Equivalente ao parâmetro $transform do loadDetailRows().
     *
     * O callable recebe:
     *   $detalhe — o model filho carregado do banco
     *   $mestre  — o model pai
     *
     * Deve retornar array de campos extras para injetar na row.
     *
     * @param string   $name Nome do field-list ou detail-form
     * @param callable $fn   fn($detalhe, $mestre): array
     */
    public function onLoadDetail(string $name, callable $fn): static
    {
        $this->_detailLoadHooks[$name] = $fn;
        return $this;
    }

    /**
     * (Detail sempre carregado por foreign-key — sem filtro extra.)
     */

    /**
     * Retorna o record original passado a fill().
     * Usado pelos Blade templates para auto-load de details no render.
     */
    public function getSourceRecord(): ?object
    {
        return $this->_sourceRecord;
    }

    /**
     * Retorna o hook de load registrado para um detail, ou null.
     */
    public function getLoadDetailHook(string $name): ?callable
    {
        return $this->_detailLoadHooks[$name] ?? null;
    }

    /**
     * Auto-carrega rows de um detail (field-list ou detail-form) a partir do banco.
     * Chamado pelos Blade templates no render quando model+fk estão definidos e rows estão vazias.
     *
     * @param string $name       Nome do field-list/detail-form
     * @param string $model      Classe do model Eloquent do filho
     * @param string $foreignKey Coluna FK no model filho
     * @param string $database   Conexão do banco (vazio = MAIN_DATABASE)
     * @param array<string,string> $renderFields  [field => pattern] das colunas
     *        cujo `field` é um caminho de relacionamento (`{cidade->estado->pais->nome}`).
     *        Elas NÃO existem no toArray() da linha: o detail monta as linhas a
     *        partir do model filho, e uma chain só resolve percorrendo as relações
     *        do registro. Sem isso a célula vinha VAZIA (o grid/data-table já
     *        resolvia; o detail-form/field-list, não).
     * @return array             Rows normalizadas, ou [] se não conseguiu carregar
     */
    public function autoLoadDetailRows(
        string $name,
        string $model,
        string $foreignKey,
        string $database = '',
        array  $renderFields = []
    ): array {
        $sourceRecord = $this->_sourceRecord;
        if (!$sourceRecord) {
            return [];
        }

        $pk       = method_exists($sourceRecord, 'getKeyName') ? $sourceRecord->getKeyName() : 'id';
        $parentId = $sourceRecord->$pk ?? null;
        if (!$parentId) {
            return [];
        }

        $db = $database ?: (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');

        try {
            // Detalhe por FK, builder-native.
            $cls   = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $q     = $cls::query()->where($foreignKey, '=', $parentId);
            // Ordena pela CHAVE do model: 'id' fixo era "unknown column" numa
            // tabela filha cuja PK tem nome proprio, e o erro derrubava o
            // carregamento inteiro do detalhe (detailLoadFailed).
            $order = (new $cls())->getKeyName();

            $objects  = \Mad\Database\QuerySource::recordsFromQuery($q, $order);
            $loadHook = $this->_detailLoadHooks[$name] ?? null;
            $rows     = [];

            foreach ($objects as $obj) {
                $row = method_exists($obj, 'toArray') ? $obj->toArray() : (array) $obj;
                // Chains de relacionamento ANTES do hook — o hook continua podendo
                // sobrescrever o valor resolvido, como já fazia com as colunas reais.
                foreach ($renderFields as $field => $pattern) {
                    try {
                        $row[$field] = \Mad\Grid\GridRenderHelpers::resolveTemplate($pattern, $obj, $row);
                    } catch (\Throwable $e) {
                        // O '' não é inofensivo: a coluna de outra tabela some da
                        // tela sem erro, sem log e sem diferença visível de um
                        // valor realmente vazio — o sintoma clássico de
                        // "relacionamento não funciona no detalhe". Registrar é o
                        // que torna isso rastreável depois.
                        error_log('[MadForm::autoLoadDetailRows] "' . $pattern
                            . '" não resolveu no detail "' . $name . '" (model='
                            . $model . ') — célula fica VAZIA: ' . $e->getMessage());
                        $row[$field] = '';
                    }
                }
                if ($loadHook) {
                    $extra = ($loadHook)($obj, $sourceRecord);
                    if (is_array($extra)) {
                        $row = array_merge($row, $extra);
                    }
                }
                $rows[] = $row;
            }

            $normalized = FieldListColumn::normalizeRows($rows);
            $this->fields[$name] = $normalized;

            return $normalized;

        } catch (\Throwable $e) {
            // ⚠️ Este [] vazio NÃO é inofensivo: ele é o precursor de perda de
            // dados. O detail renderiza sem linhas, o usuário salva achando que
            // está tudo lá, e o _saveDetailRows() do submit seguinte roda o
            // `where(fk, parentId)->delete()` do reconcile — apagando TODOS os
            // filhos reais do registro. Model irresolúvel, conexão fora do ar ou
            // FK renomeada bastam. Devolver vazio segue sendo o comportamento
            // (lançar aqui quebraria o render inteiro do form), mas silenciar era
            // o que tornava o apagamento impossível de rastrear depois.
            error_log('[MadForm::autoLoadDetailRows] falha ao carregar detail "'
                . $name . '" (model=' . $model . ', fk=' . $foreignKey
                . ', parent=' . $parentId . ') — detail renderiza VAZIO; o save '
                . 'subsequente NAO vai reconciliar este detail: ' . $e->getMessage());

            // Marca a carga como FALHA. O reconcile do save consulta isto e se
            // recusa a apagar: sem a lista real dos filhos, "nenhuma linha no
            // form" é indistinguível de "não consegui ler", e apagar no escuro
            // destrói dado do usuário.
            self::$detailLoadFailed[$name] = true;

            return [];
        }
    }

    /**
     * Auto-persiste rows de field-lists e detail-forms que declaram model + foreign-key.
     * Chamado por _afterStore() após o record pai já ter ID.
     */
    private function _autoSaveDetails(object $record): void
    {
        $details = MadFormRegistry::getAutoSaveDetails();
        if (empty($details)) {
            return;
        }

        foreach ($details as $name => $meta) {
            // getFieldList() funciona para ambos (field-list e detail-form):
            // ambos armazenam rows em $this->fields[$name]
            $rows     = $this->getFieldList($name);
            $hook     = $this->_detailSaveHooks[$name] ?? null;
            $fileCols = MadFormRegistry::getDetailFileColumns($name);

            $this->_saveDetailRows($meta['model'], $meta['foreignKey'], $record, $rows, $hook, $name, $fileCols);
        }
    }

    /**
     * Smart-sync de detail rows: update existentes, insert novas, delete removidas.
     * Lógica extraída do MadFieldListTrait::saveDetailItems().
     *
     * @param string      $model   Classe do model Eloquent do filho
     * @param string      $fk      Coluna FK no model filho
     * @param object      $mestre  Record pai (já com ID)
     * @param array       $rows    Rows do formulário
     * @param callable|null $hook  fn($detalhe, $mestre, $formRow): void
     */
    /**
     * Details cujo carregamento FALHOU neste request (nome => true).
     *
     * Estático porque o form que renderiza e o que recebe o submit são
     * instâncias diferentes dentro do mesmo ciclo de wire.
     *
     * @var array<string,bool>
     */
    private static array $detailLoadFailed = [];

    /** O detail falhou ao carregar neste request? */
    public static function detailLoadFailed(string $name): bool
    {
        return !empty(self::$detailLoadFailed[$name]);
    }

    /** Limpa o registro de falhas (usado nos testes). */
    public static function flushDetailLoadFailures(): void
    {
        self::$detailLoadFailed = [];
    }

    /**
     * Chave de linha de detail que é APRESENTAÇÃO, não coluna: o `field` de uma
     * coluna com caminho de relacionamento (`{cidade->estado->pais->nome}`). O
     * valor é resolvido no load para exibir, mas mandá-lo pro `fill()` tenta
     * gravar uma coluna inexistente — silencioso sob `$fillable`, fatal de SQL
     * num model com `$guarded = []`.
     */
    private static function isDisplayOnlyDetailKey(string $key): bool
    {
        return str_contains($key, '->') || str_contains($key, '{');
    }

    private function _saveDetailRows(
        string      $model,
        string      $fk,
        object      $mestre,
        array       $rows,
        ?callable   $hook = null,
        string      $detailName = '',
        array       $fileColumns = []
    ): void {
        $pk       = $mestre->getKeyName();
        $parentId = $mestre->$pk;

        // Carga do detail falhou no render? Então NÃO reconcilia. O reconcile
        // apaga o que não veio no post — e o que não veio pode ser
        // simplesmente o que não conseguimos ler. Gravar as linhas enviadas
        // ainda é seguro; apagar as ausentes, não.
        if ($detailName !== '' && self::detailLoadFailed($detailName)) {
            error_log('[MadForm::_saveDetailRows] detail "' . $detailName
                . '" nao carregou no render — reconcile PULADO para nao apagar '
                . 'filhos existentes do registro ' . $parentId . '.');
            return;
        }

        // Filtra rows completamente vazias
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            foreach ($row as $k => $v) {
                if (str_starts_with($k, '__')) continue;
                if (self::isDisplayOnlyDetailKey($k)) continue;
                if (trim((string)($v ?? '')) !== '') return true;
            }
            return false;
        }));

        // Chave do model FILHO — nem toda tabela de detalhe se chama `id`. Com a
        // chave errada `$hasId` era false e o save caia no branch delete+insert:
        // toda linha filha era destruida e recriada a cada gravacao (PK nova, e
        // qualquer coluna fora da field-list perdida).
        $detailPk = 'id';
        try {
            $__cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            if ($__cls && class_exists($__cls)) $detailPk = (new $__cls())->getKeyName();
        } catch (\Throwable $e) {}

        $hasId = !empty($rows) && array_key_exists($detailPk, $rows[0]);

        if ($hasId) {
            // Smart sync: update/insert + delete ausentes
            $savedIds = [];
            foreach ($rows as $row) {
                // Sem (int): PK de texto ('ABC-1') viraria 0 e o whereKey erraria.
                $pkVal    = !empty($row[$detailPk]) ? $row[$detailPk] : null;
                // Eloquent: carregar por pk p/ que save() faça UPDATE (setar id num
                // model novo causaria INSERT). ESCOPADO ao pai (fk): sem isso um
                // cliente podia passar o id de uma linha de OUTRO pai/tenant e
                // re-parenteá-la (IDOR de linha). Fora do escopo do pai → trata como
                // nova (INSERT), nunca sequestra a linha alheia.
                $instance = $pkVal
                    ? ($model::query()->where($fk, '=', $parentId)->whereKey($pkVal)->first() ?? new $model())
                    : new $model();
                $this->_persistDetailInstance($instance, $fk, $parentId, $row, $hook, $mestre, $detailName, $fileColumns);
                $savedIds[] = $instance->getKey();
            }
            // Delete builder-native — remove órfãos do pai.
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__dq = $__m::query()->where($fk, '=', $parentId);
            if ($savedIds) {
                $__dq->whereNotIn($detailPk, $savedIds);
            }
            $__dq->delete();
        } else {
            // Delete + Insert
            $__m = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__m::query()->where($fk, '=', $parentId)->delete();

            foreach ($rows as $row) {
                $instance = new $model();
                $this->_persistDetailInstance($instance, $fk, $parentId, $row, $hook, $mestre, $detailName, $fileColumns);
            }
        }
    }

    /**
     * Popula, processa uploads por-linha e persiste UMA instância de detail.
     *
     * Colunas de arquivo (registradas em MadFormRegistry::registerDetailFileColumns)
     * NÃO são copiadas como escalar: o arquivo enviado em
     * $_FILES['mad_fl_files'][detail__rowId__field] é gravado no disco (path na
     * coluna, antes do save) ou como BLOB base64 (depois do save). Sem arquivo
     * novo, o valor existente é mantido (linha carregada via find()).
     */
    private function _persistDetailInstance(
        object      $instance,
        string      $fk,
        mixed       $parentId,
        array       $row,
        ?callable   $hook,
        object      $mestre,
        string      $detailName,
        array       $fileColumns
    ): object {
        $rowId = (string) ($row['__id'] ?? '');

        // Coleta os escalares da linha e aplica via fill() para RESPEITAR o
        // $fillable/$guarded do model (defesa de mass-assignment): o cliente NÃO
        // grava colunas não-fillable (user_id/tenant_id/price/created_by/…) numa
        // linha de detail. Antes era atribuição direta ($instance->$k = $v), que
        // ignora o $fillable — o vetor de mass-assignment de detail-row.
        $assign = [];
        foreach ($row as $k => $v) {
            if (str_starts_with($k, '__')) continue;
            if (self::isDisplayOnlyDetailKey($k)) continue;  // chain `{a->b->c}`: não é coluna
            if (isset($fileColumns[$k])) continue;   // coluna de arquivo: tratada abaixo
            $assign[$k] = ($v === '') ? null : $v;
        }
        if ($instance instanceof \Illuminate\Database\Eloquent\Model) {
            $instance->fill($assign);
        } else {
            foreach ($assign as $k => $v) {
                $instance->$k = $v;
            }
        }
        $instance->$fk = $parentId;   // FK setada direto (pode não estar no $fillable)
        if ($hook) {
            ($hook)($instance, $mestre, $row);
        }

        // Uploads por-linha: disco ANTES do save (path na coluna); db DEPOIS (BLOB).
        $blobJobs = [];
        $grandchildCols = [];
        foreach ($fileColumns as $field => $meta) {
            // type='files' → tabela NETO: processado APÓS o save do item (precisa do
            // id do item) e SEMPRE (mesmo sem upload novo) p/ reconciliar remoções.
            if (($meta['kind'] ?? '') === 'grandchild') {
                $grandchildCols[$field] = $meta;
                continue;
            }
            $files = $this->_madFlFilesFor($detailName, $rowId, $field);
            if (empty($files)) {
                continue; // sem arquivo novo → mantém valor existente
            }
            $storage    = (($meta['storage'] ?? 'disk') === 'db') ? 'db' : 'disk';
            $nameColumn = $meta['nameColumn'] ?? '';
            $multi      = !empty($meta['multi']);

            if ($storage === 'db') {
                // BLOB = 1 arquivo por coluna → usa o primeiro
                $blobJobs[$field] = $files[0];
                if ($nameColumn) {
                    $instance->$nameColumn = $this->_sanitizeUploadName($files[0]['name']);
                }
                continue;
            }

            // disco
            $folder  = $this->_normalizeUploadFolder((string) ($meta['folder'] ?? 'uploads'));
            // Substituição de single-file: remove arquivo antigo do storage
            if (!$multi) {
                MadUploadStorage::delete((string) ($instance->$field ?? ''));
            }
            $paths = [];
            foreach ($files as $file) {
                $fileName = $this->_buildFileName($file['name'], $meta['fileName'] ?? 'prefix', $instance);
                $dest     = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                if (MadUploadStorage::put($file['tmp_name'], $dest)) {
                    $paths[] = $dest;
                }
                if (!$multi) break;
            }
            if ($paths) {
                $instance->$field = $multi ? implode(',', $paths) : $paths[0];
                if ($nameColumn) {
                    $instance->$nameColumn = $this->_sanitizeUploadName($files[0]['name']);
                }
            }
        }

        $instance->save();

        foreach ($blobJobs as $field => $file) {
            $content = base64_encode((string) file_get_contents($file['tmp_name']));
            $this->_saveBlobToDb($instance, $field, $content);
        }

        // type='files' → persiste os arquivos da linha na tabela NETO (após o item ter id).
        foreach ($grandchildCols as $field => $meta) {
            $this->_persistGrandchildFiles($instance, $detailName, $rowId, $field, $meta);
        }

        return $instance;
    }

    /**
     * Persiste os arquivos de UMA célula type="files" (field-list) numa tabela
     * NETO — 1 linha por arquivo, disk (path) ou db (BLOB base64).
     *
     * Novos arquivos vêm de $_FILES['mad_fl_files'][detail__rowId__field]; os
     * mantidos vêm de $_POST['__mad_existing_files'][detail__rowId__field]
     * (chave = path no disk, "id:<n>" no db). Reconcilia: deleta os netos que
     * não estão em "mantidos" (+ arquivo no disco), depois insere os novos.
     *
     * Se o model do neto tem coluna `storage`, ela discrimina o subconjunto desta
     * coluna (permite 2 colunas — disco e banco — compartilharem a mesma tabela).
     */
    private function _persistGrandchildFiles(
        object $itemInstance,
        string $detailName,
        string $rowId,
        string $field,
        array  $meta
    ): void {
        $model      = $meta['model'] ?? '';
        $fk         = $meta['foreignKey'] ?? '';
        $pathColumn = $meta['pathColumn'] ?? '';
        if (!$model || !$fk || !$pathColumn) {
            return;
        }
        $storage    = (($meta['storage'] ?? 'disk') === 'db') ? 'db' : 'disk';
        $nameColumn = $meta['nameColumn'] ?? '';
        $itemId     = $itemInstance->{$itemInstance->getKeyName()};

        $useStorageCol = in_array('storage', (new $model())->getFillable(), true);

        // ── 1) Reconcilia existentes: mantém os de keptKeys, deleta o resto ──
        $cellKey = "{$detailName}__{$rowId}__{$field}";
        $kept = $_POST['__mad_existing_files'][$cellKey] ?? [];
        if (is_string($kept)) {
            $kept = [$kept];
        }
        $kept = array_filter(array_map('strval', (array) $kept), fn ($v) => $v !== '');

        $existingQuery = $model::where($fk, $itemId);
        if ($useStorageCol) {
            $existingQuery->where('storage', $storage);
        }
        foreach ($existingQuery->get() as $gf) {
            $gkey = ($storage === 'db') ? ('id:' . $gf->id) : (string) ($gf->$pathColumn ?? '');
            if (!in_array($gkey, $kept, true)) {
                if ($storage === 'disk') {
                    MadUploadStorage::delete((string) ($gf->$pathColumn ?? ''));
                }
                $gf->delete();
            }
        }

        // ── 2) Insere os novos uploads ──────────────────────────────────────
        $files = $this->_madFlFilesFor($detailName, $rowId, $field);
        if (empty($files)) {
            return;
        }

        if ($storage === 'disk') {
            $folder = $this->_normalizeUploadFolder((string) ($meta['folder'] ?? 'uploads'));
            foreach ($files as $file) {
                $fileName = $this->_buildFileName($file['name'], $meta['fileName'] ?? 'prefix', $itemInstance);
                $dest     = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                if (MadUploadStorage::put($file['tmp_name'], $dest)) {
                    $gc = new $model();
                    $gc->$fk = $itemId;
                    if ($useStorageCol) {
                        $gc->storage = 'disk';
                    }
                    $gc->$pathColumn = $dest;
                    if ($nameColumn) {
                        $gc->$nameColumn = $this->_sanitizeUploadName($file['name']);
                    }
                    $gc->save();
                }
            }
        } else { // db (BLOB base64)
            foreach ($files as $file) {
                $gc = new $model();
                $gc->$fk = $itemId;
                if ($useStorageCol) {
                    $gc->storage = 'db';
                }
                if ($nameColumn) {
                    $gc->$nameColumn = $this->_sanitizeUploadName($file['name']);
                }
                $gc->save();
                $content = base64_encode((string) file_get_contents($file['tmp_name']));
                $this->_saveBlobToDb($gc, $pathColumn, $content);
            }
        }
    }

    // ── Serialização para estado do componente ────────────────────────────────

    /**
     * Serializa o formulário para array (usado em _publicProps / _encryptState).
     */
    /**
     * Retorna ops fl_combo pendentes (geradas por setItems('campo[]')).
     * Consumidas pelo MadComponentHandler após a action.
     */
    public function getPendingFlOps(): array
    {
        if ($this->_pendingFocus === null) {
            return $this->_pendingFlOps;
        }

        // Por último: o foco vem depois de valores/linhas que a ação mexeu.
        return [...$this->_pendingFlOps, ['op' => 'focus', 'name' => $this->_pendingFocus]];
    }

    // ── Escopo do editor de <mad-detail-form> (mad:change no sub-form) ────────

    /**
     * Abre o escopo do editor de um detail-form: os campos do sub-form entram
     * em `$this->fields` (sobrepondo homônimos do master) só enquanto a action
     * roda. Chamado pelo MadComponentHandler quando o POST traz `mad_df_scope`.
     *
     *   <mad-numeric-field name="quantidade" mad:change="onChangeQtd" />
     *
     *   public function onChangeQtd($value): void
     *   {
     *       $qtd   = (float) $this->form->get('quantidade');  // linha em edição
     *       $preco = (float) $this->form->get('valor');
     *       $this->form->set('valor_total', $qtd * $preco);   // volta pro sub-form
     *   }
     *
     * Os valores NÃO ficam no estado: {@see endDetailScope()} restaura os campos
     * do master e converte cada `set()` em op `val` mirada no sub-form.
     *
     * @param array<string,mixed> $values campos do editor (bucket `mad_df_edit`)
     */
    public function beginDetailScope(string $detail, array $values): void
    {
        $this->_detailScope       = $detail;
        $this->_detailScopeInput  = $values;
        $this->_detailScopeBackup = $this->fields;
        $this->fields             = array_merge($this->fields, $values);
        $this->_dataCache         = null;
    }

    /**
     * Fecha o escopo do detail: emite as ops dos campos do sub-form alterados na
     * action e devolve `$this->fields` ao conteúdo do master (campo do detail
     * NUNCA vaza pro estado — homônimo do master sobreviveria ao request e seria
     * gravado errado no save).
     */
    public function endDetailScope(): void
    {
        if ($this->_detailScope === null) {
            return;
        }

        $detail  = preg_replace('/[^A-Za-z0-9_\-]/', '', $this->_detailScope);
        $entrada = $this->_detailScopeInput;
        $master  = $this->_detailScopeBackup;

        foreach ($this->fields as $field => $value) {
            if (!array_key_exists($field, $entrada)) {
                // Campo do master (ou criado pela action): segue o fluxo normal —
                // o auto-bind do handler emite o `val` no formulário principal.
                $master[$field] = $value;
                continue;
            }
            if ((string) $value === (string) $entrada[$field]) {
                continue;
            }
            $this->_pendingFlOps[] = [
                'op'      => 'val',
                'target'  => '[data-df-fields][data-df-name="' . $detail . '"] '
                           . '[name="' . preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $field) . '"]',
                'content' => (string) $value,
            ];
        }

        $this->fields             = $master;
        $this->_dataCache         = null;
        $this->_detailScope       = null;
        $this->_detailScopeInput  = [];
        $this->_detailScopeBackup = [];
    }

    /** Nome do detail cujo editor está em escopo (null fora de mad:change do sub-form). */
    public function detailScope(): ?string
    {
        return $this->_detailScope;
    }

    public function toArray(): array
    {
        return [
            '__mad_form_name' => $this->name,
            'fields'          => $this->fields,
            'items'           => $this->items,
            'placeholders'    => $this->placeholders,
            'hidden'          => $this->hidden,
            'readonly'        => $this->readonly,
            'disabled'        => $this->disabled,
        ];
    }

    /**
     * Reconstitui um MadForm a partir de um array serializado (usado em _setState).
     */
    public static function fromArray(array $data): self
    {
        $instance               = new self($data['__mad_form_name'] ?? 'form');
        $instance->fields       = $data['fields']       ?? [];
        $instance->items        = $data['items']        ?? [];
        $instance->placeholders = $data['placeholders'] ?? [];
        $instance->hidden       = $data['hidden']       ?? [];
        $instance->readonly     = $data['readonly']     ?? [];
        $instance->disabled     = $data['disabled']     ?? [];
        $instance->_dataCache   = null;
        return $instance;
    }

    // ── Backward compat (métodos estáticos @deprecated) ───────────────────────

    /**
     * @deprecated Use $this->form->getData() com MadForm como propriedade pública.
     *
     * Retorna os dados do componente transformados de acordo com o schema do formulário.
     * Mantido para componentes que ainda usam $_fields.
     *
     * Migração: MadForm::getDataFromComponent($this) → $this->form->getData()
     */
    public static function getDataFromComponent(MadComponent $component, string $tokenField = '__mad_form'): array
    {
        $schema = MadFormRegistry::fromRequest($tokenField);
        $state  = self::_flattenState($component->_getState());

        $result = [];
        foreach ($schema as $name => $props) {
            if (!array_key_exists($name, $state)) {
                continue;
            }
            $result[$name] = MadFormRegistry::transformField($state[$name], $props);
        }

        return $result;
    }

    /**
     * @deprecated Use $this->form->fill($record) com MadForm como propriedade pública.
     *
     * Popula o componente com dados vindos do banco de dados.
     */
    public static function setData(MadComponent $component, array $data): void
    {
        $component->fill($data);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Achata props escalares + entradas de arrays públicos em um único array.
     * Props escalares têm prioridade sobre entradas de arrays em caso de colisão.
     */
    private static function _flattenState(array $state): array
    {
        $flat = [];
        foreach ($state as $value) {
            if (is_array($value)) {
                // Model Eloquent serializado (envelope __mad_model): as chaves
                // internas (attributes/exists/connection) não são campos de
                // formulário — achatá-las poluiria o mapa e podia sequestrar um
                // field homônimo.
                if (MadComponent::_isModelStateEnvelope($value)) continue;
                foreach ($value as $k => $v) {
                    if (!array_key_exists($k, $flat)) {
                        $flat[$k] = $v;
                    }
                }
            }
        }
        foreach ($state as $key => $value) {
            if (!is_array($value)) {
                $flat[$key] = $value;
            }
        }
        return $flat;
    }
}