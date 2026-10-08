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

    /**
     * O que ESTE formulário entregou ao navegador, por componente que grava em
     * outra tabela (Lista de itens, Detail Form, Upload Múltiplo em modo
     * tabela, seleção múltipla em mode=table e a coluna Arquivos de uma Lista
     * de itens):
     *
     *   [nome => ['p' => chave do pai, 'k' => [chave da linha => __id da linha]]]
     *
     * (no Upload a "chave" é o caminho do arquivo e o valor fica vazio; na
     * seleção múltipla é a chave do item marcado; na coluna Arquivos há uma
     * entrada por célula — ver _cellKnownName() —, o "pai" é a linha da lista
     * e a chave é a do arquivo. `u`, quando existe, marca as chaves que um
     * Salvar criou e cuja transação ainda não confirmou; `g`, as linhas que o
     * próprio usuário removeu NESTA tela por outro componente — ver
     * _remember().)
     *
     * Vale também para o que é gravado na PRÓPRIA coluna do registro, onde não
     * há linha por item: a seleção múltipla por vírgula (chave = item que o
     * campo desenhou marcado), o Upload Múltiplo por vírgula (chave = caminho)
     * e o campo de arquivo único (no máximo UMA chave: o caminho que a tela
     * tem, e como valor a impressão digital do arquivo quando foi esta tela
     * que o gravou; `v` é o valor que o formulário guardava para o campo
     * quando a tela foi desenhada — ver _fileValueUntouched()). O "pai" é o
     * próprio registro, e o Salvar compara com o que está na coluna NA HORA.
     *
     * É a base do reconcile do Salvar: ele só apaga o que está AQUI e não
     * voltou no POST — a linha que a tela mostrou e o usuário removeu. "Tudo o
     * que não veio" inclui o que a tela nunca carregou (leitura que falhou,
     * registro errado no estado, linha que outra pessoa ou outro componente
     * incluiu depois): isso não é pedido de exclusão de ninguém.
     *
     * Viaja no estado cifrado da tela (toArray/fromArray), separado de
     * `$fields`: as linhas em `$fields` são as que o navegador manda a cada
     * ação, e uma lista que o cliente reescreve não serve de prova do que o
     * servidor entregou. Só o próprio formulário escreve aqui — ao carregar as
     * linhas do banco e depois de gravá-las.
     *
     * Duas outras coisas moram aqui, pelo mesmo motivo (só o estado cifrado
     * atravessa de uma requisição para a outra, e o navegador não o reescreve):
     *
     *   - sob o nome reservado LOADED, o que veio do REGISTRO no `fill()`:
     *     `p` = tabela#chave do registro, `k` = [coluna => impressão digital do
     *     valor carregado]. É por ela que o Salvar distingue o valor que só
     *     está no formulário porque veio do registro (e ninguém mexeu) do
     *     valor que o código da tela atribuiu — ver _untouchedLoaded();
     *   - em `hc` e `h`, por detail, como estavam NO BANCO as colunas da linha
     *     que a grade não mostra: `hc` = essas colunas, `h` = chave da linha =>
     *     a impressão digital de cada uma, na ordem de `hc` — ver
     *     noteDetailColumns() e, na lista gravada à mão, _noteHandColumns().
     *     (Sem `hc`, `h` é a base de uma tela aberta antes: uma impressão só
     *     por linha — ver _withoutUntouchedRowValues());
     *   - sob o nome reservado DECLARED, o que a TELA declarou: `k` = os campos
     *     do formulário (nome => 1), `z` = os que são Editor HTML (nome => 1) e
     *     `d` = por Lista de itens / Detail Form, as colunas que ele tem (`c`),
     *     as que são Editor HTML (`z`), o Model e a chave estrangeira com que
     *     foi desenhado (`m`, `f`), a chave da linha (`pk`) e as impressões
     *     digitais do que o servidor ENTREGOU nas colunas fora da grade (`s`).
     *     É por ela que o formulário só aceita do navegador o que a tela tem —
     *     ver takesFromBrowser() e takeRowsFromBrowser() — e que o conteúdo de
     *     um Editor HTML é limpo sem depender do token `__mad_form`, que é o
     *     navegador quem devolve — ver cleanFromBrowser();
     *   - sob o prefixo reservado VALUES + nome da fonte, o que a tela carregou
     *     de uma tabela chave-valor (preferências, parâmetros): `p` = a fonte,
     *     `k` = [chave => impressão digital do valor gravado]. É por ela que a
     *     tela de configuração grava só o que mudou nela — ver valuesLoaded()
     *     e valuesChanged();
     *   - sob o prefixo reservado MARKS + nome da lista, as marcas que um
     *     checklist gravado pelo código da tela mostrou: `p` = o pai, `k` =
     *     [item => '']. É por ela que o `saveChecklist()` só inclui o que foi
     *     marcado nesta tela e só remove o que foi desmarcado nela — ver
     *     marksLoaded() e marksShown().
     *
     * @var array<string, array{p: string, k: array<int|string, string|int>, u?: array<int|string, int>, g?: array<int|string, int>, h?: array<int|string, string>, d?: array<string, array<string,mixed>>}>
     */
    private array $_known = [];

    /** Nome reservado, em `$_known`, do que veio do registro no `fill()` (não é nome de campo). */
    private const LOADED = '@record';

    /** Nome reservado, em `$_known`, do que a TELA declarou: campos e colunas dos detalhes (não é nome de campo). */
    private const DECLARED = '@fields';

    /** Tipo com que `<mad-html-editor-field>` se registra (MadFormRegistry::register). */
    private const HTML_EDITOR = 'html-editor';

    /**
     * O estado veio de uma tela aberta ANTES de o formulário anotar o que ela
     * declara (não tem DECLARED): segue aceitando do navegador como sempre, até
     * a tela ser recarregada. Sem isto o primeiro Salvar depois de atualizar o
     * framework recusaria todo campo escrito à mão. Não serializado.
     */
    private bool $_openedBefore = false;

    /**
     * `<mad-detail-fields>` que está sendo desenhado: os campos registrados
     * agora são colunas da LINHA daquele detalhe, não campos do formulário.
     */
    private ?string $_editorScope = null;

    /** @var array<string, array<string,int>> campos do editor de cada detalhe neste render (detalhe => [campo => 1]) */
    private array $_editorFields = [];

    /** @var array<string,string> chave da linha de uma lista carregada à mão (`loadDetailRows`), até a lista ser desenhada */
    private array $_detailKeyHints = [];

    /**
     * Ligações (`data-mad-model`, `$set`) e listas que o render em curso
     * desenha FORA deste formulário — dentro do editor de um detalhe ou de uma
     * tela embutida —, por nome e quantas vezes. Ver skipInScan(). Não
     * serializado.
     *
     * @var array{fields: array<string,int>, lists: array<string,int>}
     */
    private array $_scanSkip = ['fields' => [], 'lists' => []];

    /**
     * Linhas que o render EM CURSO está entregando, por detalhe: impressão
     * digital das colunas fora da grade de cada uma. Só vale se este HTML for
     * mesmo para o navegador (renderDelivered). Não serializado.
     *
     * @var array<string, array<string,int>>
     */
    private array $_renderingRows = [];

    /**
     * O que chegou do navegador nesta requisição e NÃO foi aceito: nome =>
     * motivo. Lido pelo MadComponent para o aviso no log. Não serializado.
     *
     * @var array<string,string>
     */
    private array $_refused = [];

    /** @var array<string,true> campos que o CÓDIGO atribuiu nesta requisição (`set()`, extras do `fill()`) */
    private array $_assignedNow = [];

    /** @var array<string,true> campos que o CÓDIGO leu com `get()` ou validou nesta requisição */
    private array $_usedNow = [];

    /**
     * O que o usuário digitou e este Salvar NÃO gravou: campos do formulário
     * (nome => rótulo e tabela) e colunas de lista (lista => [coluna => tabela]) que
     * não são coluna da tabela. Vira um aviso na resposta da ação
     * (getPendingFlOps). Não serializado.
     *
     * @var array{fields: array<string, array{label: string, table: string}>, rows: array<string, array<string,string>>}
     */
    private array $_notStored = ['fields' => [], 'rows' => []];

    /** @var array<string,true> campos que algum registro ACEITOU neste Salvar (`fillRecord()` em mais de um registro) */
    private array $_storedNow = [];

    /** @var array<string,true> seleções por vírgula cujas marcas novas o `fillRecord()` em curso já conferiu na fonte das opções */
    private array $_selectionChecked = [];

    /**
     * @var array<string,true> campos que um Salvar anterior pode ter recusado e que este deixou passar — o campo gerado sem coluna
     *      (sem valor digitado) e a seleção em outra tabela cujas marcas passaram na regra de referência: a mensagem de erro anterior é limpa
     */
    private array $_passedNow = [];

    /** @var array<string, array<string,true>|null> colunas da tabela (minúsculas), por "conexão|tabela"; null = não deu para ler */
    private array $_tableColumnsCache = [];

    /** @var array<string,string|null> fonte PHP da tela e das classes do app de que ela herda, por arquivo */
    private static array $_screenSourceCache = [];

    /**
     * Linhas de cada detail como o autoLoadDetailRows() as leu do banco NESTE
     * render (só os atributos do Model, antes do hook de carga e das colunas
     * de exibição): nome => ['p' => pai, 'rows' => [chave => atributos]].
     * Consumido por noteDetailColumns(). Não serializado.
     *
     * @var array<string, array{p: string, rows: array<int|string, array<string,mixed>>}>
     */
    private array $_loadedRows = [];

    /**
     * Linhas que o CÓDIGO da tela leu do banco com `loadDetailRows()` sem
     * dizer em qual lista vão (o retorno é entregue pela prop `:rows`): ficam
     * aqui até a lista que as desenha se apresentar — ver _adoptHandLoad().
     * Não serializado.
     *
     * @var list<array<string,mixed>> cada uma no formato de `$_loadedRows`, com `hand`, `ids`, `w` e `pk`
     */
    private array $_handLoads = [];

    /**
     * Base das linhas de cada lista gravada à mão que o render EM CURSO
     * desenhou com o que o `loadDetailRows()` leu agora — no formato de
     * `$_known` (`p`, `k`, `h`, `hc`, `w`). Só vira a base da tela quando as linhas
     * chegam de fato ao navegador: o HTML entregue (renderDelivered) ou um
     * `setRows()` na resposta (rowsSent). Não serializado.
     *
     * @var array<string, array{p: string, k: array<int|string,string>, h: array<int|string,string>, hc: list<string>, w: string}>
     */
    private array $_handBase = [];

    /** @var array<string,true> listas cujas linhas a resposta desta ação troca por inteiro (`setRows()`) */
    private array $_rowsReplaced = [];

    /**
     * O que este Salvar NÃO regravou porque outra tela já tinha removido:
     * posições das linhas (como estavam na lista), nomes dos anexos e, por
     * campo de seleção múltipla em outra tabela, quantas marcas. Vira um aviso
     * na resposta da ação (getPendingFlOps). Não serializado.
     *
     * @var array{rows: list<int>, files: list<string>, options: array<string,int>}
     */
    private array $_goneNotice = ['rows' => [], 'files' => [], 'options' => []];

    /**
     * O que outra aba ou outra pessoa marcou e desmarcou, numa lista de marcas
     * gravada pelo código da tela, enquanto esta tela estava aberta: nomes dos
     * itens (nome => true) e quantos outros sem nome conhecido. Vira um aviso
     * na resposta da ação (getPendingFlOps). Não serializado.
     *
     * @var array{marked: array{names: array<string,true>, more: int}, unmarked: array{names: array<string,true>, more: int}}
     */
    private array $_marksNotice = [
        'marked'   => ['names' => [], 'more' => 0],
        'unmarked' => ['names' => [], 'more' => 0],
    ];

    /**
     * Linhas filhas que o Upload Múltiplo em modo tabela criou ou apagou NESTE
     * Salvar, por classe do Model e pai. Uma Lista de itens / Detail Form sobre
     * a mesma tabela consulta: não apaga o anexo que acabou de nascer nem
     * recria a linha do anexo que o usuário acabou de remover.
     *
     * @var array<string, array{created: array<string,true>, gone: array<string,true>}>
     */
    private array $_rowsTouched = [];

    /**
     * Caminhos de arquivo que ESTE Salvar gravou no disco. A remoção adiada
     * (_discardFile) consulta antes de apagar: com `file-name="original"` ou
     * `"record"` o arquivo novo ocupa o caminho do que foi removido, e o que
     * está lá na hora de apagar é o novo. Não serializado.
     *
     * @var array<string,true>
     */
    private array $_filesStored = [];

    /**
     * Ligações (tabela pivô) que um campo de seleção múltipla em mode=table leu
     * do banco NESTE render (null = a leitura falhou) — entre o pivotLoaded() e
     * o pivotShown() do mesmo campo. Não serializado.
     *
     * @var array<string, array{p: string, links: list<string>|null}>
     */
    private array $_pivotRead = [];

    /**
     * O que o render EM CURSO está desenhando para os componentes que leem o
     * banco a cada render (seleção múltipla em mode=table, coluna Arquivos da
     * Lista de itens, Upload Múltiplo em modo tabela num redesenho), no
     * formato de `$_known`. Só vira o que o formulário
     * entregou se este HTML for de fato para o navegador — ver
     * renderDelivered(). Não serializado.
     *
     * Com `add`, as chaves são ACRESCENTADAS ao que já foi entregue (seleção que
     * não veio do banco neste render); sem ele, substituem.
     *
     * @var array<string, array{p: string, k: array<int|string, string>, add?: bool}>
     */
    private array $_rendering = [];

    /**
     * O que os campos gravados na própria coluna passam a "ter" depois deste
     * Salvar (seleção por vírgula, arquivo único): nome => [chaves, criadas
     * agora]. Só vira base DEPOIS de o registro ser gravado — ver
     * _baseUpdate() / _applyBaseUpdates(). Não serializado.
     *
     * @var array<string, array{k: array<int|string,string>, u: list<int|string>, file: bool, saved?: array<string,string>}>
     */
    private array $_baseUpdates = [];

    /** O `save()` está em curso (o registro ainda vai ser gravado por ele)? */
    private bool $_saving = false;

    /** Cache normalizado de $_FILES['mad_fl_files'] (uploads por-linha de details). */
    private ?array $_madFlFilesCache = null;

    /** @var array<string, array<string,true>> colunas que aceitam NULL, por "conexão|tabela" — ver _emptyToNull() */
    private array $_nullableColumnsCache = [];

    /** @var array<string,bool> a tabela do model filho tem a coluna da chave? — ver _detailHasKeyColumn() */
    private array $_detailKeyColumnCache = [];

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
        // Registro que já existe no banco: o formulário anota o que veio dele.
        // É o que permite ao Salvar não regravar a coluna que a tela não tem
        // e em que ninguém mexeu (ver _untouchedLoaded). Array, objeto que não
        // é Model e registro novo não deixam anotação: são valores que o
        // código está pondo no formulário, e continuam sendo gravados.
        $ref = null;
        if (is_object($data)) {
            $this->_sourceRecord = $data;
            $ref  = self::_recordRef($data);
            $data = method_exists($data, 'toArray') ? $data->toArray() : (array) $data;
        }

        $loaded = [];
        foreach ($data as $key => $value) {
            $this->fields[$key] = $value;
            if ($ref !== null) {
                $loaded[$key] = self::_fingerprint($value);
            } else {
                unset($this->_known[self::LOADED]['k'][$key]);
                $this->_assignedNow[(string) $key] = true;
            }
        }
        if ($ref !== null) {
            $this->_known[self::LOADED] = ['p' => $ref, 'k' => $loaded];
        }

        // Os extras são do código da tela (padrões, conversões de unidade).
        foreach ($extra as $key => $value) {
            $this->fields[$key] = $value;
            unset($this->_known[self::LOADED]['k'][$key]);
            $this->_assignedNow[(string) $key] = true;
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
     * A coluna que a tela não tem e que continua com o valor que veio no
     * `fill($registro)` não é atribuída ao mesmo registro: o que está no banco
     * agora pode ter sido gravado por outra tela — ver _untouchedLoaded() e,
     * para o comportamento anterior, rewriteLoaded().
     *
     * O campo da tela com valor digitado que NÃO é coluna do registro não some
     * calado: o campo gerado pela plataforma recusa o Salvar (mensagem no
     * campo) e o campo sem uso na tela é avisado na resposta. Campo só de tela
     * se declara com `screen-only` na tag ou screenOnly() — ver
     * _fieldsWithoutColumn().
     *
     * @return object O próprio record (para encadeamento)
     *
     * @throws MadValidationException campo gerado para uma coluna que não existe mais, com valor digitado
     */
    public function fillRecord(object $record): object
    {
        // Editor de um detail-form em escopo (`mad_df_scope`): os campos do
        // editor estão por cima dos do formulário só para a ação ler a linha em
        // edição. O registro principal é preenchido com os valores DELE — um
        // campo do editor com o nome de uma coluna do registro (o `status` do
        // item × o `status` do pedido) não é valor do registro.
        if ($this->_detailScope !== null) {
            return $this->_withMasterFields(fn () => $this->fillRecord($record));
        }

        $data = $this->getData();

        if (!$this->_saving) {
            $this->_baseUpdates = [];
        }
        $this->_selectionChecked = [];

        // Remove campos storage="db" do data antes do fromArray
        // (esses campos são tratados pelo _afterStore via prepared statement)
        $schema = MadFormRegistry::fromRequest();
        $dataArray = (array) $data;
        if (!empty($schema)) {
            foreach ($schema as $fieldName => $props) {
                $t = $props['type'] ?? '';
                // Upload Múltiplo por vírgula: quem grava a coluna é o
                // _processMultiFileUpload(), a partir do que está nela AGORA.
                // O valor que o formulário guarda para o campo é o de quando a
                // tela abriu (o <input type="file"> não posta valor): gravá-lo
                // aqui punha a coluna de volta naquele ponto — a aba que ficou
                // aberta tirava o arquivo que outra aba anexou e devolvia o que
                // outra aba removeu.
                if ($t === 'multi-file' && ($props['storage'] ?? '') === 'disk' && ($props['mode'] ?? 'comma') === 'comma') {
                    unset($dataArray[$fieldName]);
                }
                // Arquivo único no disco: idem. Enquanto o valor do formulário
                // é o que a tela recebeu ao abrir, a coluna fica como está (a
                // remoção e a troca são do _processFileUploads). O caminho que
                // o CÓDIGO da tela põe no campo continua sendo gravado.
                if ($t === 'file' && ($props['storage'] ?? '') === 'disk'
                    && array_key_exists($fieldName, $dataArray)
                    && $this->_fileValueUntouched($record, (string) $fieldName, $dataArray[$fieldName])) {
                    unset($dataArray[$fieldName]);
                    $nameColumn = (string) ($props['nameColumn'] ?? '');
                    if ($nameColumn !== '' && !isset($schema[$nameColumn])) {
                        unset($dataArray[$nameColumn]);
                    }
                }
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
                // HTML editor: sanitiza output do editor antes de persistir.
                // Defesa contra Stored XSS — render via {!! !!} so e seguro
                // se o conteudo passou por whitelist de tags/atributos.
                if ($t === self::HTML_EDITOR && isset($dataArray[$fieldName]) && is_string($dataArray[$fieldName])) {
                    $dataArray[$fieldName] = \Mad\Util\MadHtmlSanitizer::sanitize($dataArray[$fieldName]);
                }
                // Seleção múltipla gravada na própria coluna: só sai da coluna
                // o item que a tela mostrou marcado e o usuário desmarcou.
                if (array_key_exists($fieldName, $dataArray) && self::_isColumnSelection((string) $t, $props)) {
                    $this->_mergeColumnSelection($record, (string) $fieldName, $props, $dataArray);
                }
            }
        }

        // Editor HTML pelo que a TELA declarou (estado cifrado), e não só pelo
        // token acima: o token é o navegador quem devolve, e sem ele o laço
        // nem roda. O valor já foi limpo ao chegar (cleanFromBrowser); aqui é
        // a última barreira antes do banco — pega também o que o código pôs no
        // campo com `set()`.
        foreach ((array) ($this->_known[self::DECLARED]['z'] ?? []) as $htmlField => $_) {
            if (isset($dataArray[$htmlField]) && is_string($dataArray[$htmlField])
                && (($schema[$htmlField]['type'] ?? '') !== self::HTML_EDITOR)) {
                $dataArray[$htmlField] = \Mad\Util\MadHtmlSanitizer::sanitize($dataArray[$htmlField]);
            }
        }

        // Coluna que a tela não tem, com o valor que veio do registro ao abrir:
        // não é regravada (outra tela, outra pessoa ou outro processo pode tê-la
        // mudado nesse meio tempo).
        foreach ($this->_untouchedLoaded($record, is_array($schema) ? $schema : []) as $untouched) {
            unset($dataArray[$untouched]);
        }

        // Chave, colunas de controle e empresa: o formulário não as atribui a
        // partir da tela, nem quando alguém as declara como campo.
        if ($record instanceof \Illuminate\Database\Eloquent\Model) {
            $dataArray = $this->_withoutScreenGuarded($record, $dataArray);
        }

        // Seleção múltipla sobre tabela gravada na própria coluna: a marca que
        // vai ENTRAR na coluna tem de ser de um item que quem salva enxerga.
        $this->_guardColumnSelections($record, $dataArray);

        if ($record instanceof \Illuminate\Database\Eloquent\Model) {
            // Eloquent puro: fill() honra $fillable — campos do form que não são
            // coluna (ex: checklists) são descartados em vez de virar INSERT inválido.
            // O que o usuário digitou num campo que não é coluna não some calado:
            // ver _fieldsWithoutColumn().
            $scalars = array_filter($dataArray, fn ($v) => !is_array($v));
            $this->_fieldsWithoutColumn($record, $scalars, is_array($schema) ? $schema : []);
            $record->fill($this->_emptyToNull($record, $scalars, $schema));
        } else {
            foreach ($dataArray as $key => $value) {
                if (!is_array($value)) {
                    $record->$key = $value;
                }
            }
        }

        // Processa uploads automaticamente baseado no schema
        $this->_processFileUploads($record);

        // Chamado direto (o código da tela grava o registro depois): não há como
        // saber aqui se a gravação vai dar certo — ver _applyBaseUpdates().
        if (!$this->_saving) {
            $this->_applyBaseUpdates($record, false);
        }

        return $record;
    }

    // ── Colunas que a tela NÃO tem ────────────────────────────────────────────
    //
    // O `fill($registro)` põe no formulário TODAS as colunas do registro, e o
    // formulário as devolve no `getData()` — é assim que o código da tela lê,
    // num On Change ou no Salvar, uma coluna que não tem campo. Mas o Salvar
    // também as gravava de volta: se entre abrir e salvar outra aba, outra
    // pessoa ou outro processo (job, API, um botão da listagem) mudasse o
    // status, o saldo ou a data de aprovação, quem só editou outro campo
    // devolvia o valor antigo, em silêncio.
    //
    // A regra: o Salvar grava (a) as colunas que a tela TEM — campo do
    // formulário, visível, oculto, somente leitura ou desabilitado — e (b) o
    // que o CÓDIGO atribuiu: `set()`, os extras do `fill()`, um `fill([...])`,
    // um valor diferente escrito em `$form->fields`, o que foi atribuído no
    // próprio registro e os extras do `save()`. O que não é (a) nem (b) e
    // continua igual ao que veio do registro não é regravado.

    /**
     * Identidade do registro que JÁ existe no banco (tabela#chave) — null para
     * o registro novo e para o que não é Model.
     */
    private static function _recordRef(object $record): ?string
    {
        $key = self::_existingKey($record);

        return $key === null ? null : $record->getTable() . '#' . $key;
    }

    /**
     * Impressão digital de um valor do formulário. Calculada sobre o valor
     * como ele volta do estado da tela (JSON), para ser a mesma antes e depois
     * de uma requisição.
     */
    private static function _fingerprint(mixed $value): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
        if (!is_scalar($value) && $value !== null) {
            $value = json_decode((string) json_encode($value, $flags), true);
        }

        return substr(md5((string) json_encode($value, $flags)), 0, 10);
    }

    /**
     * Colunas que este Salvar NÃO grava em `$record`: vieram dele no `fill()`,
     * a tela não tem campo para elas e o valor continua o de quando a tela
     * abriu.
     *
     * Só vale para o MESMO registro que foi carregado. Registro novo (cadastro,
     * "duplicar"), outro registro e formulário sem a anotação (tela aberta
     * antes desta versão, `fill()` com array) gravam tudo, como sempre.
     *
     * "A tela tem" = o campo que a tela DECLAROU (ver declares()): as tags
     * `<mad-*>` do formulário, o campo escrito à mão que o servidor desenhou
     * ligado ao formulário e o que o código liberou com accept(). O campo do
     * editor de um detail-form é coluna da linha, não do registro. Numa tela
     * aberta antes de o formulário anotar o que declara vale a regra anterior:
     * o campo está no schema do formulário OU o navegador o mandou nesta
     * requisição.
     *
     * A coluna cujo valor mudou deixa de ser "o que veio do registro": passa a
     * ser valor do código, e é gravada deste Salvar em diante.
     *
     * @param  array<string,mixed> $schema
     * @return list<string>
     */
    private function _untouchedLoaded(object $record, array $schema): array
    {
        $ref    = self::_recordRef($record);
        $loaded = $ref !== null ? $this->_knownFor(self::LOADED, $ref) : null;
        if (!$loaded) {
            return [];
        }

        $declared = $this->declares() ? (array) ($this->_known[self::DECLARED]['k'] ?? []) : null;
        $posted   = $_POST['mad_model'] ?? null;
        $posted   = is_array($posted) ? $posted : [];
        $editor   = MadFormRegistry::detailEditorFields();

        $untouched = [];
        foreach ($loaded as $name => $print) {
            $name = (string) $name;
            // O schema (token) continua valendo para o campo `<mad-*>` desenhado
            // fora do render da tela; o que o navegador MANDOU só conta na tela
            // de antes — hoje o que ele manda sem a tela ter já foi recusado.
            $onScreen = (array_key_exists($name, $schema) && !isset($editor[$name]))
                || ($declared !== null ? isset($declared[$name]) : array_key_exists($name, $posted));
            if ($onScreen) {
                continue;
            }
            if (!array_key_exists($name, $this->fields)) {
                continue;
            }
            if (self::_fingerprint($this->fields[$name]) !== $print) {
                unset($this->_known[self::LOADED]['k'][$name]);
                continue;
            }
            $untouched[] = $name;
        }

        return $untouched;
    }

    /**
     * Volta a gravar, no Salvar, o que o formulário carregou do registro e a
     * tela não mostra — como era antes de o Salvar deixar de regravar a coluna
     * em que ninguém mexeu.
     *
     * Por padrão a coluna que a tela não tem só é gravada quando o código a
     * atribui. Use quando a tela PRECISA devolver ao registro os valores de
     * quando ela abriu (restaurar uma versão, desfazer o que mudou enquanto a
     * tela estava aberta):
     *
     *   $this->form->rewriteLoaded();                    // tudo o que foi carregado
     *   $this->form->rewriteLoaded('status', 'saldo');   // só estas colunas
     *   $this->form->save($pedido);
     *
     * Chame depois do `fill($registro)` (um `fill()` novo refaz a anotação).
     * Para UMA coluna com valor novo não precisa: `set('status', 'x')` já grava.
     */
    public function rewriteLoaded(string ...$fields): void
    {
        if ($fields === []) {
            unset($this->_known[self::LOADED]);

            return;
        }
        foreach ($fields as $field) {
            unset($this->_known[self::LOADED]['k'][$field]);
        }
    }

    // ── Valores que NÃO vêm de um registro (configuração chave-valor) ─────────
    //
    // A tela de configuração junta num formulário só VÁRIAS linhas de uma
    // tabela chave-valor (preferências, parâmetros). Não há `fill($registro)`
    // nem `save($registro)`: o Salvar da tela percorre o formulário e grava
    // linha a linha — todas, com o valor que o formulário tinha, que é o de
    // quando a tela abriu. Com a tela aberta em dois lugares, quem salvava por
    // último desfazia o que o outro tinha gravado, em qualquer campo.
    //
    // O princípio é o do registro (ver _untouchedLoaded): o formulário guarda,
    // no estado cifrado da tela, a impressão digital do que ela CARREGOU, e o
    // Salvar pergunta o que mudou desde então. A diferença é que aqui todo
    // valor tem campo na tela — então a pergunta é feita valor a valor, e só é
    // gravado o que a pessoa mudou NESTA tela:
    //
    //   // mount(): o que a tela carregou, na forma em que fica gravado
    //   $this->form->valuesLoaded('parametros', $gravados);
    //
    //   // Salvar: do que a tela gravaria agora, só o que mudou nela
    //   foreach ($this->form->valuesChanged('parametros', $aGravar) ?? array_keys($aGravar) as $chave) {
    //       Parametro::updateOrCreate(['id' => $chave], ['valor' => $aGravar[$chave]]);
    //       $this->form->valuesLoaded('parametros', [$chave => $aGravar[$chave]]);
    //   }
    //
    // Compare o que a tela GRAVARIA (depois das conversões dela: interruptor →
    // 'T'/'F', lista → texto), não o que o navegador manda: é a tela que sabe
    // quando dois valores são o mesmo. A mesma pergunta, feita com o que está
    // gravado AGORA, diz o que outra aba ou outra pessoa mudou nesse meio tempo:
    //
    //   $porFora = $this->form->valuesChanged('parametros', $gravadosAgora);

    /** Prefixo reservado, em `$_known`, do que a tela carregou de uma fonte chave-valor (não é nome de campo). */
    private const VALUES = '@values:';

    /**
     * Anota o que a tela carregou de `$source` (ou acabou de gravar nela):
     * chave => valor, na forma em que fica gravado. Chamadas seguidas somam —
     * a chave que não vem continua com a anotação que tinha.
     *
     * Só a impressão digital de cada valor vai para o estado da tela.
     *
     * @param array<int|string, mixed> $values
     */
    public function valuesLoaded(string $source, array $values): void
    {
        if ($source === '') {
            return;
        }

        $name  = self::VALUES . $source;
        $known = $this->_knownFor($name, $source) ?? [];
        foreach ($values as $key => $value) {
            $known[(string) $key] = self::_valuePrint($value);
        }

        $this->_known[$name] = ['p' => $source, 'k' => $known];
    }

    /**
     * Chaves de `$values` cujo valor é OUTRO que não o anotado para `$source`
     * (a chave sem anotação conta como mudada).
     *
     * Devolve null quando o formulário não tem anotação nenhuma para a fonte:
     * tela aberta antes desta versão, ou que não chamou valuesLoaded(). Quem
     * chama decide — o comportamento de sempre é gravar tudo.
     *
     * @param  array<int|string, mixed> $values
     * @return list<string>|null
     */
    public function valuesChanged(string $source, array $values): ?array
    {
        $known = $source !== '' ? $this->_knownFor(self::VALUES . $source, $source) : null;
        if ($known === null) {
            return null;
        }

        $changed = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (($known[$key] ?? null) !== self::_valuePrint($value)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Impressão digital de um valor gravado. Numa tabela chave-valor "sem
     * valor" é uma coisa só (null e '' dão a mesma), e o número e o texto do
     * número são o mesmo valor (7 e '7').
     */
    private static function _valuePrint(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            $value = (string) $value;
        }

        return self::_fingerprint($value);
    }

    // ── Lista de marcas gravada pelo código da tela (checklist) ──────────────
    //
    // O código da tela lê as ligações do registro (`loadChecklist()`), mostra-as
    // marcadas num checklist e, no Salvar, grava o que volta marcado
    // (`saveChecklist()`). "O que volta marcado" é o que a tela MOSTRAVA mais o
    // que a pessoa mexeu — e a tela mostra as marcas de quando abriu. Com ela
    // aberta em dois lugares, gravar esse conjunto desfazia a marca que o outro
    // tinha feito e devolvia a que ele tinha desmarcado.
    //
    // O formulário guarda, no estado cifrado da tela, as marcas que a lista
    // mostrou; o Salvar compara o que veio com elas e só inclui o que a pessoa
    // marcou NESTA tela e só remove o que ela desmarcou. O que outra aba ou
    // outra pessoa mudou nesse meio tempo fica como está no banco — e quem
    // salva é avisado (warnMarksChanged).

    /** Prefixo reservado, em `$_known`, das marcas que uma lista gravada pelo código da tela mostrou (não é nome de campo). */
    private const MARKS = '@marks:';

    /** Quantos itens o aviso das marcas cita pelo nome antes de resumir ("e mais 3"). */
    private const MARKS_NAMED = 5;

    /**
     * O código da tela leu do banco as marcas de `$list` para este pai, para
     * mostrá-las. Passam a valer como "o que a tela mostra" quando o HTML for
     * entregue (renderDelivered): a ação que relê as marcas sem redesenhar a
     * tela não troca a base — a tela continua mostrando o que mostrava.
     *
     * @internal chamado por MadChecklistTrait::loadChecklist()
     *
     * @param list<int|string> $items itens marcados
     */
    public function marksLoaded(string $list, mixed $parentId, array $items): void
    {
        if ($list === '' || $parentId === null || $parentId === '') {
            return;
        }

        $this->_rendering[self::MARKS . $list] = [
            'p' => (string) $parentId,
            'k' => array_fill_keys(self::selectionKeys($items), ''),
        ];
    }

    /**
     * A resposta da ação troca as marcas de um checklist da tela
     * (`MadResponse::reloadChecklist()`): as que o código leu nesta requisição
     * chegam ao navegador sem redesenho, e passam a ser o que a tela mostra.
     *
     * @internal chamado por MadComponent::_noteResponseOps()
     */
    public function marksDelivered(): void
    {
        foreach ($this->_rendering as $name => $entry) {
            if (str_starts_with((string) $name, self::MARKS)) {
                $this->_remember((string) $name, $entry['p'], $entry['k']);
                unset($this->_rendering[$name]);
            }
        }
    }

    /**
     * As marcas que esta tela mostrava em `$list` para este pai — ou null
     * quando ela não tem base: registro novo, tela aberta antes desta versão,
     * tela que não leu as marcas com o `loadChecklist()`.
     *
     * @internal usado por MadChecklistTrait::saveChecklist()
     *
     * @return list<string>|null
     */
    public function marksShown(string $list, mixed $parentId): ?array
    {
        $known = $list !== '' ? $this->_knownFor(self::MARKS . $list, $parentId) : null;

        return $known === null ? null : array_map('strval', array_keys($known));
    }

    /**
     * O Salvar gravou `$list`: a tela continua mostrando marcado o que mandou
     * marcado (`$items`), e é disso que o Salvar seguinte parte.
     *
     * Só vale quando a transação em curso confirmar (`$owner` é um registro da
     * tabela gravada): se ela for desfeita, nada foi gravado e a base continua
     * a de antes — a tentativa seguinte ainda tem de incluir o que a pessoa
     * marcou.
     *
     * @internal usado por MadChecklistTrait::saveChecklist()
     *
     * @param list<int|string> $items itens que a tela mandou marcados
     */
    public function marksSaved(string $list, mixed $parentId, array $items, ?object $owner = null): void
    {
        if ($list === '' || $parentId === null || $parentId === '') {
            return;
        }

        $entry = ['p' => (string) $parentId, 'k' => array_fill_keys(self::selectionKeys($items), '')];
        $this->_afterCommit($owner, function () use ($list, $entry): void {
            $this->_known[self::MARKS . $list] = $entry;
        });
    }

    /**
     * Registra o que outra aba ou outra pessoa marcou e desmarcou, numa lista
     * gravada pelo código da tela, enquanto esta tela estava aberta: o Salvar
     * não desfez nada disso. A resposta da ação leva UM aviso com tudo (ver
     * getPendingFlOps).
     *
     * `$marked` / `$unmarked` são os nomes dos itens, como o usuário os vê;
     * `$markedMore` / `$unmarkedMore`, quantos outros não têm nome conhecido.
     *
     * Com `$owner` (um registro da tabela gravada), o aviso só sai se a
     * transação em curso confirmar: o Salvar que falhou não manteve nada, e a
     * resposta dele é a do erro.
     *
     * @param list<string> $marked
     * @param list<string> $unmarked
     */
    public function warnMarksChanged(array $marked, array $unmarked, int $markedMore = 0, int $unmarkedMore = 0, ?object $owner = null): void
    {
        $this->_afterCommit($owner, function () use ($marked, $unmarked, $markedMore, $unmarkedMore): void {
            foreach (['marked' => [$marked, $markedMore], 'unmarked' => [$unmarked, $unmarkedMore]] as $kind => [$names, $more]) {
                foreach ($names as $name) {
                    $name = trim(strip_tags((string) $name));
                    if ($name !== '') {
                        $this->_marksNotice[$kind]['names'][$name] = true;
                    } else {
                        $more++;
                    }
                }
                $this->_marksNotice[$kind]['more'] += max(0, $more);
            }
        });
    }

    /** O aviso (diálogo) do que foi marcado/desmarcado por fora, ou null se não há o que avisar. */
    private function _marksNoticeOp(): ?array
    {
        $texts = [
            'marked'   => ['mad.checklist.marked_elsewhere', 'Outra aba ou outra pessoa marcou :items enquanto esta tela estava aberta. Isso foi mantido.'],
            'unmarked' => ['mad.checklist.unmarked_elsewhere', 'Outra aba ou outra pessoa desmarcou :items enquanto esta tela estava aberta. Isso foi mantido.'],
        ];

        $parts = [];
        foreach ($texts as $kind => [$key, $fallback]) {
            $names = array_map('strval', array_keys($this->_marksNotice[$kind]['names']));
            $more  = (int) $this->_marksNotice[$kind]['more'];
            if ($names || $more > 0) {
                $parts[] = self::_text($key, ['items' => self::_marksItems($names, $more)], $fallback);
            }
        }
        if (!$parts) {
            return null;
        }
        $parts[] = self::_text('mad.checklist.changed_tail', [], 'Atualize a tela para ver a situação atual.');

        return [
            'op'      => 'alert',
            // O diálogo aceita HTML: os nomes vêm do cadastro e vão escapados.
            'message' => htmlspecialchars(implode(' ', $parts), ENT_QUOTES, 'UTF-8'),
            'type'    => 'warning',
            'title'   => self::_text('mad.checklist.changed_title', [], 'Alterado em outra aba ou por outra pessoa'),
        ];
    }

    /**
     * Os itens do aviso das marcas, como a pessoa os vê: “Financeiro” e
     * “Vendas”; “Financeiro” e mais 2; 3 itens.
     *
     * @param list<string> $names
     */
    private static function _marksItems(array $names, int $more): string
    {
        $more += max(0, count($names) - self::MARKS_NAMED);
        $names = array_map(static fn (string $name): string => '“' . $name . '”', array_slice($names, 0, self::MARKS_NAMED));

        if (!$names) {
            return $more === 1
                ? self::_text('mad.checklist.items_one', [], '1 item')
                : self::_text('mad.checklist.items_many', ['count' => $more], ':count itens');
        }

        $and = ' ' . self::_text('mad.detail.and', [], 'e') . ' ';
        if ($more > 0) {
            return implode(', ', $names) . $and . self::_text('mad.checklist.more', ['count' => $more], 'mais :count');
        }
        $last = array_pop($names);

        return $names ? implode(', ', $names) . $and . $last : (string) $last;
    }

    // ── O que a TELA declarou × o que o navegador manda ──────────────────────
    //
    // O navegador manda, junto de toda ação, os campos da tela (`mad_model`),
    // as linhas de cada Lista de itens / Detail Form e o editor do detalhe em
    // edição. Tudo isso caía em `$fields` sem conferência — e de `$fields` sai
    // o que o Salvar grava. Quem tinha a tela gravava, mexendo na requisição,
    // qualquer coluna do registro que o Model aceitasse (o status, o saldo, o
    // dono) e qualquer coluna da linha, tivesse a tela campo para ela ou não.
    //
    // A regra: do navegador o formulário só aceita o que a tela DECLAROU —
    //   - os campos `<mad-*>` do formulário (visíveis, ocultos, somente
    //     leitura, desabilitados, escondidos por condição);
    //   - o campo escrito à mão que o SERVIDOR desenhou ligado ao formulário
    //     (`mad:model`, `mad:click="$set(...)"`), na tela ou num trecho que uma
    //     ação devolveu;
    //   - os campos de um diálogo que o código abriu (MadConfirm::field);
    //   - as colunas da grade e do editor de cada Lista de itens / Detail Form;
    //   - o que o código liberou com accept().
    // O resto que chega é recusado, vai para o log e o Salvar segue com o que
    // é da tela. O que o CÓDIGO atribui (`set()`, extras, o gancho de gravação
    // da linha, o que o Model e o framework carimbam) não passa por aqui.
    //
    // O que a tela declarou viaja no estado cifrado (`$_known[DECLARED]`), não
    // no token `__mad_form`: o token é o navegador quem devolve, e dá para
    // omiti-lo ou trocá-lo pelo de outra tela.

    /** Este formulário sabe o que a tela declarou? (false = tela aberta antes desta versão, que aceita como sempre) */
    public function declares(): bool
    {
        return isset($this->_known[self::DECLARED]);
    }

    /**
     * Libera campos que o navegador manda sem que o servidor os tenha
     * desenhado ligados ao formulário: campo criado por JavaScript, valor
     * enviado por `MadWire.call(el, 'acao', [], {campo: valor})` /
     * `MadWire.set()`, lista montada só no navegador.
     *
     * Chame no `mount()` (ou no `onEdit()`): fica guardado no estado da tela.
     *
     *   public function mount(array $params = []): void
     *   {
     *       $this->form = new MadForm('form');
     *       $this->form->accept('latitude', 'longitude');
     *   }
     *
     * Não é preciso para os campos `<mad-*>`, para o campo HTML escrito à mão
     * no Blade com `mad:model` nem para os campos de um MadConfirm: esses a
     * tela já declara ao ser desenhada.
     */
    public function accept(string ...$fields): static
    {
        // Tela de antes desta versão: já aceita tudo, e continua assim até ser
        // recarregada (criar a anotação agora recusaria o resto da tela).
        if ($this->_openedBefore) {
            return $this;
        }
        $this->_openDeclared();
        foreach ($fields as $field) {
            if ($field !== '') {
                $this->_known[self::DECLARED]['k'][$field] = 1;
            }
        }

        return $this;
    }

    /** @internal o formulário acabou de ser reconstituído do estado da tela (MadComponent::_setState) */
    public function hydratedFromState(): void
    {
        $this->_openedBefore = !isset($this->_known[self::DECLARED]);
    }

    private function _openDeclared(): void
    {
        if (!isset($this->_known[self::DECLARED]) || !is_array($this->_known[self::DECLARED])) {
            // `n`: a tela foi desenhada por uma versão que anota o que o Salvar
            // não grava (ver _fieldsWithoutColumn). `w`: e por uma versão que
            // anota ONDE cada campo grava fora da coluna do registro (ver
            // declareWrites) — sem ela a tela é de antes, e grava como sempre.
            $this->_known[self::DECLARED] = ['p' => '', 'k' => [], 'd' => [], 'n' => 1, 'w' => []];
        }
        foreach (['k', 'd'] as $part) {
            if (!is_array($this->_known[self::DECLARED][$part] ?? null)) {
                $this->_known[self::DECLARED][$part] = [];
            }
        }
    }

    /**
     * Um campo do formulário foi desenhado (tag `<mad-*>`) ou aberto num
     * diálogo pelo código (MadConfirm::field).
     *
     * `$type` é o tipo com que a tag se registrou. O Editor HTML fica anotado
     * à parte: o conteúdo dele é exibido como HTML, e por isso é limpo sempre
     * que chega do navegador — ver cleanFromBrowser().
     *
     * @internal chamado por MadFormRegistry::register() e MadConfirm
     */
    public function declareField(string $name, string $type = ''): void
    {
        if ($name === '' || $this->_openedBefore) {
            return;
        }
        $html = $type === self::HTML_EDITOR;
        // Dentro de <mad-detail-fields>: coluna da linha, não campo da tela.
        if ($this->_editorScope !== null) {
            $this->_editorFields[$this->_editorScope][$name] = $html ? 2 : 1;

            return;
        }
        $this->_openDeclared();
        $this->_known[self::DECLARED]['k'][$name] = 1;
        if ($html) {
            $this->_known[self::DECLARED]['z'][$name] = 1;
        } elseif ($type !== '' && isset($this->_known[self::DECLARED]['z'][$name])) {
            // A tela passou a desenhar o campo com outra tag.
            unset($this->_known[self::DECLARED]['z'][$name]);
            if ($this->_known[self::DECLARED]['z'] === []) {
                unset($this->_known[self::DECLARED]['z']);
            }
        }
    }

    /** @internal o `<mad-detail-fields>` de `$detail` começa a ser desenhado (detail-form.blade) */
    public function beginDetailEditor(string $detail): void
    {
        $this->_editorScope = $detail;
        $this->_editorFields[$detail] = [];
    }

    /** @internal fim do `<mad-detail-fields>` — `$html` é o que ele desenhou */
    public function endDetailEditor(string $html = ''): void
    {
        $this->_editorScope = null;
        $this->skipInScan($html);
    }

    /**
     * Uma Lista de itens / Detail Form foi desenhada: as colunas que ela tem
     * (grade + editor), o Model e a chave estrangeira com que grava sozinha.
     *
     * @internal chamado por MadFormRegistry (registerFieldList / registerDetailForm / registerDetailEditorFields)
     *
     * @param list<string> $columns
     */
    public function declareDetail(string $name, array $columns, string $model = '', string $foreignKey = ''): void
    {
        if ($name === '' || $this->_openedBefore) {
            return;
        }
        $this->_openDeclared();

        $shown = [];
        foreach ($columns as $column) {
            if (is_string($column) && $column !== '') {
                $shown[$column] = 1;
            }
        }
        $html = [];   // colunas da linha que o editor desenha com Editor HTML
        foreach ($this->_editorFields[$name] ?? [] as $field => $kind) {
            $shown[(string) $field] = 1;
            if ($kind === 2) {
                $html[(string) $field] = 1;
            }
        }

        $before = $this->_known[self::DECLARED]['d'][$name] ?? [];
        $entry  = ['c' => $shown];
        if ($html) {
            $entry['z'] = $html;
        }
        if ($model !== '') {
            $entry['m'] = $model;
        }
        if ($foreignKey !== '') {
            $entry['f'] = $foreignKey;
        }

        // Chave da linha: a do Model do detalhe; na lista gravada à mão, a que
        // o loadDetailRows() informou — ou a que já estava anotada.
        $pk = $this->_detailKeyHints[$name] ?? (is_string($before['pk'] ?? null) ? $before['pk'] : '');
        if ($model !== '') {
            try {
                $cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                $pk  = (string) (new $cls())->getKeyName();
            } catch (\Throwable $e) {
                // Model que não resolve: fica a chave anotada (ou `id`).
            }
        }
        if ($pk !== '' && $pk !== 'id') {
            $entry['pk'] = $pk;
        }
        // O que já foi entregue ao navegador continua valendo até a próxima entrega.
        if (is_array($before['s'] ?? null)) {
            $entry['s'] = $before['s'];
        }
        // Como o detalhe trata os arquivos das linhas (declareDetailFiles).
        if (is_array($before['fp'] ?? null)) {
            $entry['fp'] = $before['fp'];
        }
        // Colunas em que o usuário DIGITA: no Detail Form, os campos do editor
        // (a grade só exibe); na Lista de itens quem diz é a view — ver
        // detailTypedColumns().
        if (!empty($this->_editorFields[$name])) {
            $entry['t'] = array_fill_keys(array_map('strval', array_keys($this->_editorFields[$name])), 1);
        } elseif (is_array($before['t'] ?? null)) {
            $entry['t'] = $before['t'];
            if (is_string($before['tl'] ?? null)) {
                $entry['tl'] = $before['tl'];
            }
        }

        $this->_known[self::DECLARED]['d'][$name] = $entry;
    }

    /**
     * As colunas de ARQUIVO das linhas de `$name` (Lista de itens / Detail
     * Form) como este render as desenhou: armazenamento, pasta, coluna do nome
     * e, na coluna Arquivos, a tabela dos arquivos. `$columns` vazio = o
     * detalhe não tem coluna de arquivo.
     *
     * Essas definições viajam no formulário `__mad_form`, que o navegador
     * devolve; aqui fica a impressão digital delas (`fp`), e o Salvar só grava
     * as linhas quando o formulário recebido as descreve igual — ver
     * _autoSaveDetails(). Guarda as últimas formas (pasta calculada a cada
     * render, por exemplo), como declareWrites().
     *
     * @internal chamado por MadFormRegistry::registerDetailFileColumns()
     *
     * @param array<string, array<string,mixed>> $columns
     */
    public function declareDetailFiles(string $name, array $columns): void
    {
        if ($name === '' || $this->_openedBefore || !isset($this->_known[self::DECLARED]['d'][$name])) {
            return;
        }
        $print  = MadUploadRules::detailFilesPrint($columns);
        $prints = (array) ($this->_known[self::DECLARED]['d'][$name]['fp'] ?? []);
        unset($prints[$print]);
        $prints[$print] = 1;
        $this->_known[self::DECLARED]['d'][$name]['fp'] = array_slice($prints, -self::WRITE_PRINTS, null, true);
    }

    /** @internal a chave da linha de uma lista carregada à mão (MadFieldListTrait::loadDetailRows) */
    public function noteDetailKey(string $name, string $keyName): void
    {
        if ($name === '' || $keyName === '') {
            return;
        }
        $this->_detailKeyHints[$name] = $keyName;
        if (isset($this->_known[self::DECLARED]['d'][$name])) {
            if ($keyName === 'id') {
                unset($this->_known[self::DECLARED]['d'][$name]['pk']);
            } else {
                $this->_known[self::DECLARED]['d'][$name]['pk'] = $keyName;
            }
        }
    }

    /**
     * O render em curso desenhou `$html` DENTRO da tela, mas fora deste
     * formulário: o editor de um detail-form (os campos são colunas da linha e
     * não vão em `mad_model`) ou uma tela embutida (o formulário é o dela). As
     * ligações que aparecem ali não declaram campo deste formulário.
     *
     * @internal chamado pelo Blade do detail-form e por MadComponent::_wrapRenderedHtml()
     */
    public function skipInScan(string $html): void
    {
        foreach (self::_boundIn($html) as $kind => $names) {
            foreach ($names as $name => $times) {
                $this->_scanSkip[$kind][$name] = ($this->_scanSkip[$kind][$name] ?? 0) + $times;
            }
        }
    }

    /**
     * O servidor desenhou `$html` para esta tela (o render, ou um trecho que a
     * resposta de uma ação leva): o campo HTML escrito à mão que está ligado ao
     * formulário (`mad:model`, `mad:click="$set(...)"`) e a lista escrita à mão
     * passam a ser da tela. Só acrescenta — o campo que um render desenhou
     * continua declarado.
     *
     * @internal chamado por MadComponent (render e resposta das ações)
     *
     * @param bool $render é o HTML do render da tela (consome o que o render marcou com skipInScan)
     */
    public function declareFromHtml(string $html, bool $render = true): void
    {
        $skip = ['fields' => [], 'lists' => []];
        if ($render) {
            $skip = $this->_scanSkip;
            $this->_scanSkip     = ['fields' => [], 'lists' => []];
            $this->_editorFields = [];
            $this->_editorScope  = null;
        }
        if ($this->_openedBefore) {
            return;
        }
        // Mesmo sem nada ligado: a tela foi desenhada por esta versão, e é a
        // partir daqui que o formulário confere o que chega do navegador.
        $this->_openDeclared();

        $bound   = self::_boundIn($html);
        $foreign = [];   // ligado na tela, mas não a este formulário
        foreach ($bound['fields'] as $name => $times) {
            if ($times > ($skip['fields'][$name] ?? 0)) {
                $this->_known[self::DECLARED]['k'][$name] = 1;
            } elseif (!isset($this->_known[self::DECLARED]['k'][$name])) {
                $foreign[$name] = 1;
            }
        }
        // O que a tela desenha dentro de OUTRA tela embutida nela: o navegador
        // antigo (ou o onChange de uma Lista de itens) manda junto, e não é
        // tentativa de ninguém — ver takesFromBrowser().
        if ($render) {
            if ($foreign) {
                $this->_known[self::DECLARED]['x'] = $foreign;
            } else {
                unset($this->_known[self::DECLARED]['x']);
            }
        }
        // Lista escrita à mão (sem a tag): as linhas chegam como sempre. A que
        // tem a tag já está em `d`, com as colunas.
        foreach ($bound['lists'] as $name => $times) {
            if ($times > ($skip['lists'][$name] ?? 0) && !isset($this->_known[self::DECLARED]['d'][$name])) {
                $this->_known[self::DECLARED]['l'][$name] = 1;
            }
        }
    }

    /**
     * O que um HTML liga ao formulário — o que o navegador vai mandar por
     * causa dele: `data-mad-model` / `data-mad-model-live` (a forma em que
     * `mad:model` chega ao HTML), as chaves de `mad:click="$set(...)"` e os
     * contêineres de lista (`data-mad-fl-name` / `data-mad-df-name`).
     *
     * @return array{fields: array<string,int>, lists: array<string,int>} nome => quantas vezes
     */
    private static function _boundIn(string $html): array
    {
        $found = ['fields' => [], 'lists' => []];
        if ($html === '' || !str_contains($html, 'data-mad-')) {
            return $found;
        }
        // Só a MARCAÇÃO conta. Dentro de um <script> o texto vem de dados (um
        // JSON com o que o usuário digitou): uma Observação com
        // `data-mad-model='saldo'` escrito nela não pode declarar campo.
        $html = self::_withoutScripts($html);

        $add = static function (string $kind, string $name) use (&$found): void {
            $name = trim(html_entity_decode($name, ENT_QUOTES));
            if ($name === '' || strlen($name) > 190) {
                return;
            }
            $found[$kind][$name] = ($found[$kind][$name] ?? 0) + 1;
            // "form.campo": no formulário o campo é o que vem depois do ponto.
            if ($kind === 'fields' && str_contains($name, '.')) {
                $field = substr($name, strpos($name, '.') + 1);
                if ($field !== '') {
                    $found[$kind][$field] = ($found[$kind][$field] ?? 0) + 1;
                }
            }
        };

        if (str_contains($html, 'data-mad-model')
            && preg_match_all('/(?<![:\w-])data-mad-model(?:-live)?\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $html, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $add('fields', ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            }
        }

        // mad:click="$set('campo', valor)" / "$set({'a': 1, 'b': 2})"
        if (str_contains($html, '$set')
            && preg_match_all('/(?<![:\w-])data-mad-click\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $html, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $raw = html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES);
                if (!preg_match('/^\s*\$set\s*\(\s*(.+)\)\s*$/s', $raw, $call)) {
                    continue;
                }
                $args = ltrim($call[1]);
                if ($args !== '' && $args[0] === '{') {
                    if (preg_match_all('/[{,]\s*["\']([^"\']+)["\']\s*:/', $args, $keys)) {
                        foreach ($keys[1] as $key) {
                            $add('fields', $key);
                        }
                    }
                } elseif (preg_match('/^["\']([^"\']+)["\']/', $args, $key)) {
                    $add('fields', $key[1]);
                }
            }
        }

        if ((str_contains($html, 'data-mad-fl-name') || str_contains($html, 'data-mad-df-name'))
            && preg_match_all('/(?<![:\w-])data-mad-(?:fl|df)-name\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $html, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $add('lists', ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            }
        }

        return $found;
    }

    /** `$html` sem os blocos `<script>` (busca linear: um bloco grande não esbarra em limite de expressão regular). */
    private static function _withoutScripts(string $html): string
    {
        if (stripos($html, '<script') === false) {
            return $html;
        }

        $out = '';
        $pos = 0;
        while (($start = stripos($html, '<script', $pos)) !== false) {
            $out .= substr($html, $pos, $start - $pos);
            $end = stripos($html, '</script', $start);
            if ($end === false) {
                return $out;   // script sem fim: o resto não é marcação
            }
            $close = strpos($html, '>', $end);
            $pos   = $close === false ? strlen($html) : $close + 1;
        }

        return $out . substr($html, $pos);
    }

    /**
     * O navegador mandou `$field` em `mad_model`: o formulário aceita?
     *
     * Aceita o que a tela declarou. A tela aberta antes desta versão (sem a
     * anotação) aceita como sempre aceitou.
     *
     * @internal chamado por MadComponent::_applyModelValues()
     */
    public function takesFromBrowser(string $field, mixed $value = null): bool
    {
        if (!$this->declares() || isset($this->_known[self::DECLARED]['k'][$field])) {
            return true;
        }

        // Recusado. O aviso no log é para o que mudaria alguma coisa: valor
        // igual ao que o formulário já tem passa calado, e também o campo
        // VAZIO que a tela desenha em outro lugar — o editor de um detalhe,
        // uma tela embutida —, que o onChange de uma Lista de itens (e o
        // JavaScript de versões anteriores) manda junto dos campos da tela.
        $elsewhere = $this->drawnElsewhere($field);
        $empty = $value === null || $value === '' || $value === [];
        $same  = array_key_exists($field, $this->fields)
            && (is_scalar($value) || $value === null)
            && (is_scalar($this->fields[$field]) || $this->fields[$field] === null)
            && (string) $value === (string) $this->fields[$field];
        if ($same || ($elsewhere !== null && $empty)) {
            return false;
        }

        $why = isset($this->_known[self::LOADED]['k'][$field])
            ? 'é coluna do registro aberto e a tela não tem campo para ela'
            : 'a tela não declara este campo';
        if ($elsewhere !== null) {
            $why .= '; em ' . $elsewhere . ' existe um campo com este nome, que não é do registro desta tela';
        }
        $this->_refused[$field] = $why;

        return false;
    }

    // ── Editor HTML: o que chega do navegador é limpo ────────────────────────
    //
    // O conteúdo de um Editor HTML é exibido como HTML (na listagem, no próprio
    // editor, num documento). O que entra por ele passa pela limpeza do
    // MadHtmlSanitizer — fica a formatação, saem `<script>`, atributos de
    // evento e URL `javascript:`.
    //
    // A limpeza rodava só no fillRecord() e só quando a requisição trazia o
    // token `__mad_form` (é ele que diz o tipo de cada campo) — e o token é o
    // navegador quem devolve: sem ele o conteúdo era gravado como veio. E não
    // rodava nunca no que não passa pelo fillRecord(): o Avançar de um wizard
    // que grava a cada etapa, a tela que lê `getData()` e atribui à mão, a
    // coluna de uma linha filha.
    //
    // Agora a tela anota no ESTADO CIFRADO quais campos (e quais colunas de
    // cada detalhe) são Editor HTML, e o valor é limpo na ENTRADA: no que o
    // navegador manda para o campo, nas linhas das listas e no editor de um
    // detalhe. Tudo o que o código da tela lê depois — `get()`, `getData()`,
    // `$form->fields`, as linhas — já vem limpo, e o que é gravado também.

    /**
     * O valor que o navegador mandou para `$field`, limpo quando o campo é um
     * Editor HTML desta tela. Os outros campos passam como vieram.
     *
     * @internal chamado por MadComponent::_applyModelValues()
     */
    public function cleanFromBrowser(string $field, mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || !isset($this->_known[self::DECLARED]['z'][$field])) {
            return $value;
        }

        return \Mad\Util\MadHtmlSanitizer::sanitize($value);
    }

    /**
     * Linha de `$detail` com as colunas de Editor HTML limpas.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function _cleanRowHtml(string $detail, array $row): array
    {
        foreach ((array) ($this->_known[self::DECLARED]['d'][$detail]['z'] ?? []) as $column => $_) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $row[$column] = \Mad\Util\MadHtmlSanitizer::sanitize($row[$column]);
            }
        }

        return $row;
    }

    /**
     * Onde a tela desenha um campo `$field` que NÃO é deste formulário — o
     * editor ou a grade de um detalhe (devolve o nome dele entre aspas), uma
     * tela embutida — ou null.
     *
     * @internal usado nos avisos do log
     */
    public function drawnElsewhere(string $field): ?string
    {
        foreach ((array) ($this->_known[self::DECLARED]['d'] ?? []) as $detail => $entry) {
            if (isset($entry['c'][$field])) {
                return '"' . $detail . '"';
            }
        }

        return isset($this->_known[self::DECLARED]['x'][$field]) ? 'uma tela embutida nesta' : null;
    }

    /**
     * O navegador mandou as linhas de `$name` (`mad_field_lists` /
     * `mad_detail_forms`). Elas entram no formulário — mas de cada linha só as
     * colunas que a lista TEM. O que vem nas colunas fora da grade só é aceito
     * quando é exatamente o que o servidor entregou para aquela linha (a carga
     * do banco, o gancho de carga, `setRows()`, o `df_add` de um before-add):
     * a linha vai inteira para o navegador e volta inteira, e é assim que o
     * valor que o CÓDIGO pôs numa coluna sem campo chega ao Salvar. Coluna fora
     * da grade que volta diferente foi mexida no navegador, e é descartada.
     *
     * @internal chamado por MadComponent::_setFieldListData()
     */
    public function takeRowsFromBrowser(string $name, mixed $rows): void
    {
        // Este canal é de LINHAS: um escalar aqui sempre foi requisição
        // adulterada (caía em `$fields` como se fosse valor de campo).
        if ($name === '' || !is_array($rows)) {
            $this->_refused['[' . $name . ']'] = 'o canal das linhas recebeu um valor que não é lista';

            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $this->_refused['[' . $name . ']'] = 'o canal das linhas recebeu um valor que não é linha';

                return;
            }
        }

        if (!$this->declares()) {
            $this->fields[$name] = $rows;

            return;
        }

        $entry = $this->_known[self::DECLARED]['d'][$name] ?? null;
        if (!is_array($entry)) {
            // Lista escrita à mão que o servidor desenhou, ou liberada com accept().
            if (isset($this->_known[self::DECLARED]['l'][$name]) || isset($this->_known[self::DECLARED]['k'][$name])) {
                $this->fields[$name] = $rows;

                return;
            }
            $this->_refused['[' . $name . ']'] = 'a tela não tem uma lista com este nome';

            return;
        }

        $shown = (array) ($entry['c'] ?? []);
        $pk    = is_string($entry['pk'] ?? null) ? $entry['pk'] : 'id';
        $sent  = (array) ($entry['s'] ?? []);

        $dropped = [];
        foreach ($rows as $i => $row) {
            $print = self::_offGridPrint($row, $shown, $pk);
            if ($print === null || isset($sent[$print])) {
                continue;
            }
            foreach ($row as $column => $value) {
                $column = (string) $column;
                if (self::_isRowColumn($column, $shown, $pk)) {
                    continue;
                }
                unset($rows[$i][$column]);
                if ($value !== null && $value !== '' && $value !== []) {
                    $dropped[$column] = true;
                }
            }
        }
        if ($dropped) {
            ksort($dropped);
            $this->_refused['[' . $name . ']'] = 'alguma linha chegou com valor que o servidor não entregou nas colunas que a lista não tem ('
                . implode(', ', array_keys($dropped)) . '); nessas linhas só as colunas da lista foram aceitas';
        }

        // Coluna que o editor do detalhe desenha com Editor HTML: limpa.
        if (!empty($entry['z'])) {
            foreach ($rows as $i => $row) {
                $rows[$i] = $this->_cleanRowHtml($name, $row);
            }
        }

        $this->fields[$name] = $rows;
    }

    /**
     * `$column` de uma linha é da lista? — coluna declarada (a de exibição com
     * caminho de relacionamento também é declarada), a chave da linha (diz
     * QUAL é a linha; o Salvar a procura sempre sob o pai) ou metadado `__*`
     * (nunca é gravado).
     */
    private static function _isRowColumn(string $column, array $shown, string $pk): bool
    {
        return $column === $pk
            || isset($shown[$column])
            || str_starts_with($column, '__');
    }

    /**
     * Impressão digital das colunas de uma linha que a lista NÃO tem, presa à
     * chave da linha — null quando a linha não traz nenhuma. É calculada sobre
     * a linha como ela vai para o navegador e como volta dele (vazio e nulo são
     * a mesma coisa; 10.0 e 10 também, pelo JSON).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $shown colunas que a lista tem
     */
    private static function _offGridPrint(array $row, array $shown, string $pk): ?string
    {
        $plain = static function (mixed $value) use (&$plain): mixed {
            if (is_array($value)) {
                return array_map($plain, $value);
            }

            return $value === '' ? null : $value;
        };

        $rest = [];
        foreach ($row as $column => $value) {
            $column = (string) $column;
            if (!self::_isRowColumn($column, $shown, $pk)) {
                $rest[$column] = $plain($value);
            }
        }
        if ($rest === []) {
            return null;
        }
        ksort($rest);

        $key = $row[$pk] ?? null;

        return self::_fingerprint(['r' => $rest, 'k' => (is_scalar($key) && $key !== '') ? (string) $key : null]);
    }

    /**
     * Impressões das colunas fora da grade de `$rows`, como o servidor as
     * entrega para `$name`.
     *
     * @return array<string,int>
     */
    private function _rowPrints(string $name, array $rows): array
    {
        $entry = $this->_known[self::DECLARED]['d'][$name] ?? null;
        if (!is_array($entry)) {
            return [];
        }
        $shown = (array) ($entry['c'] ?? []);
        $pk    = is_string($entry['pk'] ?? null) ? $entry['pk'] : 'id';

        $prints = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $print = self::_offGridPrint($row, $shown, $pk);
            if ($print !== null) {
                $prints[$print] = 1;
            }
        }

        return $prints;
    }

    /**
     * O render em curso desenha `$name` com estas linhas (as que vão no HTML).
     * Só vira "o que o servidor entregou" se o HTML for mesmo para o navegador
     * (renderDelivered).
     *
     * @internal chamado pelas views do framework (field-list / detail-form), depois de declararem as colunas
     */
    public function rowsRendered(string $name, array $rows): void
    {
        if ($name === '' || !$this->declares() || !isset($this->_known[self::DECLARED]['d'][$name])) {
            return;
        }
        $this->_renderingRows[$name] = $this->_rowPrints($name, $rows);
    }

    /**
     * A resposta de uma ação leva linhas para `$name` (`setRows()` troca todas;
     * o `df_add` de um before-add acrescenta ou troca uma): passam a ser o que
     * o servidor entregou.
     *
     * @internal chamado por MadComponent::_noteResponseOps()
     */
    public function rowsSent(string $name, array $rows, bool $replace): void
    {
        // Lista gravada à mão: as linhas que o `loadDetailRows()` leu nesta
        // ação estão indo para o navegador — a base delas passa a valer.
        if ($replace && $name !== '') {
            $this->_rowsReplaced[$name] = true;
            $this->_applyHandBase($name);
        }

        if ($name === '' || !$this->declares() || !isset($this->_known[self::DECLARED]['d'][$name])) {
            return;
        }
        $prints = $this->_rowPrints($name, $rows);
        if (!$replace) {
            $prints += (array) ($this->_known[self::DECLARED]['d'][$name]['s'] ?? []);
        }
        if ($prints) {
            $this->_known[self::DECLARED]['d'][$name]['s'] = $prints;
        } else {
            unset($this->_known[self::DECLARED]['d'][$name]['s']);
        }
    }

    /**
     * O que chegou do navegador nesta requisição e não foi aceito (nome =>
     * motivo; as listas vêm como `[nome]` e a coluna que o formulário não
     * grava a partir da tela como `!nome`). Esvazia ao ser lido.
     *
     * @internal lido por MadComponent para o aviso no log
     *
     * @return array<string,string>
     */
    public function pullRefused(): array
    {
        $refused = $this->_refused;
        $this->_refused = [];

        return $refused;
    }

    /**
     * Roda `$fn` com `$fields` como o formulário PRINCIPAL os tem — sem os
     * campos do editor de detalhe que o escopo aberto pôs por cima.
     */
    private function _withMasterFields(callable $fn): mixed
    {
        $scope  = $this->_detailScope;
        $scoped = $this->fields;

        foreach ($this->_detailScopeInput as $field => $_) {
            if (array_key_exists($field, $this->_detailScopeBackup)) {
                $this->fields[$field] = $this->_detailScopeBackup[$field];
            } else {
                unset($this->fields[$field]);
            }
        }
        $this->_detailScope = null;
        $this->_dataCache   = null;

        try {
            return $fn();
        } finally {
            $this->fields       = $scoped;
            $this->_detailScope = $scope;
            $this->_dataCache   = null;
        }
    }

    /**
     * Colunas que o formulário nunca atribui a um Model a partir da TELA, nem
     * quando alguém as declara como campo (um oculto posto por engano): a
     * chave gerada pelo banco, as colunas de controle (quando/quem criou,
     * alterou, excluiu — pelos nomes que a plataforma administra e pelos que o
     * Model configurou) e a empresa, quando a tabela é isolada por empresa.
     *
     * Continuam sendo gravadas pelo que o CÓDIGO atribui: no próprio registro,
     * nos extras de `save($registro, [...])` ou com `set()` na mesma
     * requisição — e pelo que o Model e o framework carimbam.
     *
     * A unidade (`unit_id`) fica de fora de propósito: com Multi-unidade ela
     * pode ser campo da tela, e quem confere é o Model (só aceita unidade do
     * usuário — BelongsToUnit).
     *
     * @return array<string,string> coluna => o que ela é
     */
    private static function _screenGuarded(\Illuminate\Database\Eloquent\Model $record): array
    {
        $guarded = array_fill_keys(self::CONTROL_COLUMNS, 'coluna de controle');

        foreach (self::CONTROL_GETTERS as $getter) {
            if (!method_exists($record, $getter)) {
                continue;
            }
            try {
                $column = $record->{$getter}();
            } catch (\Throwable $e) {
                $column = null;
            }
            if (is_string($column) && $column !== '') {
                $guarded[$column] = 'coluna de controle';
            }
        }

        if ($record->getIncrementing() && is_string($record->getKeyName()) && $record->getKeyName() !== '') {
            $guarded[$record->getKeyName()] = 'chave do registro';
        }

        try {
            if (method_exists($record, 'madTenantScoped') && $record::madTenantScoped()) {
                $guarded['tenant_id'] = 'empresa do registro';
            }
        } catch (\Throwable $e) {
            // sem config: não há isolamento por empresa a proteger
        }

        return $guarded;
    }

    /** Colunas de controle pelos nomes que a plataforma administra (nunca são campo de formulário gerado). */
    private const CONTROL_COLUMNS = [
        'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by', 'deleted_by',
        'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id',
        'created_by_unit_id',
    ];

    /** Métodos com que o Model informa as SUAS colunas de controle (Eloquent, HasMadAudit, HasMadSoftDeletes). */
    private const CONTROL_GETTERS = [
        'getCreatedAtColumn', 'getUpdatedAtColumn', 'getDeletedAtColumn',
        'getCreatedByColumn', 'getUpdatedByColumn', 'getDeletedByColumn',
        'getCreatedByUserIdColumn', 'getUpdatedByUserIdColumn', 'getDeletedByUserIdColumn',
        'getCreatedByUnitIdColumn',
    ];

    /**
     * Tira de `$values` (o que vai ser atribuído a `$record`) as colunas que o
     * formulário não grava a partir da tela — menos as que o código atribuiu
     * nesta requisição.
     *
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function _withoutScreenGuarded(\Illuminate\Database\Eloquent\Model $record, array $values, string $detail = ''): array
    {
        $posted = $detail === '' ? ($_POST['mad_model'] ?? null) : null;
        $posted = is_array($posted) ? $posted : [];

        foreach (self::_screenGuarded($record) as $column => $what) {
            if (!array_key_exists($column, $values) || ($detail === '' && isset($this->_assignedNow[$column]))) {
                continue;
            }
            $value = $values[$column];
            unset($values[$column]);
            // Só avisa do valor que o navegador mandou e que mudaria o
            // registro: o campo que só exibe a data de criação manda de volta
            // o que recebeu, e isso não é tentativa de ninguém.
            if (array_key_exists($column, $posted) && !self::_sameAsStored($record, $column, $value)) {
                $this->_refused['!' . $column] = $what;
            }
        }

        return $values;
    }

    /** `$value` é o que `$record` já tem gravado em `$column`? (datas comparadas pelo instante, não pelo formato) */
    private static function _sameAsStored(\Illuminate\Database\Eloquent\Model $record, string $column, mixed $value): bool
    {
        $empty  = static fn (mixed $v): bool => $v === null || $v === '';
        $stored = $record->exists ? $record->getRawOriginal($column) : null;
        if ($empty($stored) || $empty($value)) {
            return $empty($stored) && $empty($value);
        }
        if (!is_scalar($stored) || !is_scalar($value)) {
            return false;
        }
        if ((string) $stored === (string) $value) {
            return true;
        }
        $a = strtotime((string) $stored);
        $b = strtotime((string) $value);

        return $a !== false && $b !== false && abs($a - $b) < 60;
    }

    // ── Campo da tela que NÃO é coluna do registro ────────────────────────────
    //
    // O `fill()` do Eloquent honra o `$fillable` e descarta, calado, a chave
    // que o Model não aceita. Para o campo que não é coluna da tabela — a
    // coluna foi renomeada e a tela ficou com o nome antigo, o nome foi
    // digitado errado no Blade — isso era: o usuário digita, salva, vê
    // "Registro salvo com sucesso!" e o valor não está em lugar nenhum.
    //
    // Mas nem todo campo sem coluna é engano: a confirmação da senha, o
    // interruptor que só mostra uma seção, o campo que o código lê e grava em
    // outra tabela são campos SÓ DE TELA, e existem em toda aplicação. O
    // formulário separa os dois pelo que a TELA diz:
    //
    //  - ERRO (o Salvar é recusado, com a mensagem no campo): o campo que a
    //    plataforma GEROU para uma coluna desta tabela (a tag traz a âncora do
    //    gerador, `data-mad-entity-id`) e cujo nome não é coluna dela. Não há
    //    para onde o valor ir;
    //  - AVISO (o Salvar segue, e a resposta diz o que ficou de fora): o campo
    //    sem a âncora em que o usuário digitou AGORA, que não é coluna e que a
    //    tela não usa — ver _screenUses(). Vale também para a coluna de uma
    //    Lista de itens / Detail Form que não é coluna da tabela da linha;
    //  - NADA: o campo só de tela. A tela o declara com `screen-only` na tag
    //    ou `$this->form->screenOnly('campo')` — ou simplesmente o USA: lê com
    //    `get()`, valida, cita o nome no código PHP dela, usa numa condição
    //    `*-when`. Também o campo `secao__coluna` do assistente (quem grava a
    //    seção é a tela), o somente leitura, o desabilitado, o que o código
    //    atribuiu e o que o Model conhece (mutator, relação, cast).
    //
    // O que a conferência precisa viaja no estado cifrado, em DECLARED: `a` =
    // campos com a âncora (nome => tabela no modelo de dados), `o` = os só de
    // tela, `v` = a impressão digital do valor padrão que a tag desenhou (o
    // navegador o devolve sem ninguém ter digitado), `t` (por detalhe) = as
    // colunas em que o usuário digita, e `n`, que marca a tela desenhada por
    // esta versão — a que foi aberta antes salva como sempre salvou.

    /** A tela foi desenhada por uma versão que anota o que o Salvar não grava? */
    private function _notesNotStored(): bool
    {
        return !$this->_openedBefore && !empty($this->_known[self::DECLARED]['n']);
    }

    /**
     * Declara campos SÓ DE TELA: o valor deles não é do registro — a tela o usa
     * (uma confirmação, um interruptor que só mostra uma seção, um campo que o
     * JavaScript lê) e o Salvar não o grava nem avisa.
     *
     * Chame no `mount()` (ou no `onEdit()`): fica guardado no estado da tela.
     *
     *   public function mount(array $params = []): void
     *   {
     *       $this->form = new MadForm('form');
     *       $this->form->screenOnly('confirmar_email', 'mostrar_entrega');
     *   }
     *
     * Na tag é o atributo `screen-only`:
     *
     *   <mad-switch-field name="mostrar_entrega" label="Entrega em outro endereço" screen-only />
     *
     * Vale também para a coluna de uma Lista de itens / Detail Form, pelo nome
     * da coluna. Não é preciso para o campo que o código da tela já lê
     * (`$this->form->get('campo')`, `$data->campo`), valida ou usa numa
     * condição `visible-when`: esses o formulário reconhece sozinho.
     */
    public function screenOnly(string ...$fields): static
    {
        if ($this->_openedBefore) {
            return $this;
        }
        $this->_openDeclared();
        foreach ($fields as $field) {
            if ($field !== '') {
                $this->_known[self::DECLARED]['o'][$field] = 1;
            }
        }

        return $this;
    }

    /**
     * A tag do campo traz a âncora do gerador: a plataforma o criou para uma
     * coluna da tabela `$entity` (o número dela no modelo de dados).
     *
     * @internal chamado pelo Blade compilado (MadFormRegistry::anchored)
     */
    public function declareColumnField(string $name, int $entity): void
    {
        // Dentro de <mad-detail-fields> o campo é coluna da LINHA.
        if ($name === '' || $entity <= 0 || $this->_openedBefore || $this->_editorScope !== null) {
            return;
        }
        $this->_openDeclared();
        $this->_known[self::DECLARED]['a'][$name] = $entity;
    }

    /**
     * O campo foi desenhado com o valor padrão da tag (`value="..."`), não com
     * um valor do registro: o navegador vai devolvê-lo sem ninguém ter digitado.
     *
     * @internal chamado por MadFieldValue::resolve()
     */
    public function noteFieldDefault(string $name, string $value): void
    {
        if ($name === '' || $value === '' || $this->_openedBefore || $this->_editorScope !== null) {
            return;
        }
        $this->_openDeclared();
        $this->_known[self::DECLARED]['v'][$name] = self::_fingerprint($value);
    }

    /**
     * Colunas de uma Lista de itens em que o usuário DIGITA (fora as só de
     * leitura, desabilitadas, calculadas e ocultas).
     *
     * @internal chamado pela view do framework (field-list), depois de registrar a lista
     *
     * @param array<string,string> $columns coluna => rótulo (é o rótulo que vai no aviso)
     * @param string               $label   título da lista, quando ela tem
     */
    public function detailTypedColumns(string $name, array $columns, string $label = ''): void
    {
        if ($name === '' || !isset($this->_known[self::DECLARED]['d'][$name])) {
            return;
        }
        $typed = [];
        foreach ($columns as $column => $text) {
            $text = trim(strip_tags((string) $text));
            $typed[(string) $column] = $text !== '' ? $text : 1;
        }
        $this->_known[self::DECLARED]['d'][$name]['t'] = $typed;

        $label = trim(strip_tags($label));
        if ($label !== '') {
            $this->_known[self::DECLARED]['d'][$name]['tl'] = $label;
        } else {
            unset($this->_known[self::DECLARED]['d'][$name]['tl']);
        }
    }

    /**
     * Colunas da tabela de `$record`, em minúsculas (nome => true) — null
     * quando não dá para ler (driver, permissão): aí nada é conferido.
     *
     * @return array<string,true>|null
     */
    private function _tableColumns(\Illuminate\Database\Eloquent\Model $record): ?array
    {
        try {
            $connection = $record->getConnection();
            $key = $connection->getName() . '|' . $record->getTable();
        } catch (\Throwable) {
            return null;
        }

        if (!array_key_exists($key, $this->_tableColumnsCache)) {
            $columns = null;
            try {
                $names = $connection->getSchemaBuilder()->getColumnListing($record->getTable());
                if ($names) {
                    $columns = array_fill_keys(array_map(static fn ($n) => strtolower((string) $n), $names), true);
                }
            } catch (\Throwable) {
                // sem schema: não há como dizer o que é coluna
            }
            $this->_tableColumnsCache[$key] = $columns;
        }

        return $this->_tableColumnsCache[$key];
    }

    /** O Model trata `$name` por conta própria (mutator, atributo calculado, relação, cast)? */
    private static function _modelKnows(\Illuminate\Database\Eloquent\Model $record, string $name): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            return false;
        }
        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));
        $camel  = lcfirst($studly);

        try {
            return method_exists($record, 'set' . $studly . 'Attribute')
                || method_exists($record, 'get' . $studly . 'Attribute')
                || method_exists($record, $camel)
                || method_exists($record, $name)
                || property_exists($record, $name)
                || array_key_exists($name, $record->getCasts());
        } catch (\Throwable) {
            return true;   // na dúvida, o campo é do Model
        }
    }

    /**
     * `$value` é algo que o usuário pôs no campo? — não o vazio, nem o
     * "desligado" de um interruptor, nem o valor padrão que a tag desenhou.
     *
     * @param array<string,mixed> $props props do campo no schema do formulário
     */
    private function _typedValue(string $name, mixed $value, array $props): bool
    {
        if (!is_scalar($value) || is_bool($value)) {
            return false;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return false;
        }
        if (in_array((string) ($props['type'] ?? ''), ['switch', 'checkbox'], true)) {
            $off = array_map('strval', array_filter(
                [$props['value_off'] ?? null, '0', 'false', 'off', 'N'],
                static fn ($v) => $v !== null && $v !== '',
            ));
            if (in_array($text, $off, true)) {
                return false;
            }
        }

        return ($this->_known[self::DECLARED]['v'][$name] ?? null) !== self::_fingerprint((string) $value);
    }

    /**
     * A tela USA o campo `$name`? — o código dela o leu (`get()`), validou, ou
     * cita o nome: `->nome`, `'nome'`, `"nome"` no PHP da tela e das classes
     * do app de que ela herda (o nome escrito só num `name="..."` de um Blade
     * embutido no PHP não conta: é a própria tag).
     *
     * Devolve null quando não dá para saber (fora de uma requisição de tela,
     * fonte ilegível): quem chama trata como "usa" — na dúvida, nada de aviso.
     */
    private function _screenUses(string $name): ?bool
    {
        if (isset($this->_usedNow[$name]) || isset($this->_assignedNow[$name])) {
            return true;
        }

        $screen = MadFormRegistry::actingScreen();
        if (!is_object($screen) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            return null;
        }

        $sources = self::_screenSources($screen);
        if ($sources === null) {
            return null;
        }

        $quoted  = preg_quote($name, '/');
        $pattern = '/(?:->|[\'"])' . $quoted . '(?![A-Za-z0-9_])/';
        $ownTag  = '/(?<![\w:.-])(?:name|field|mad:model(?:\.live)?|data-mad-model(?:-live)?)\s*=\s*([\'"])' . $quoted . '\1/';
        foreach ($sources as $source) {
            if (!str_contains($source, $name)) {
                continue;
            }
            if (preg_match($pattern, (string) preg_replace($ownTag, '', $source))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fonte PHP da tela: a classe, as classes do APP de que ela herda e as
     * traits delas (o que é do framework ou de `vendor/` fica de fora). Null
     * quando alguma não pôde ser lida.
     *
     * @return list<string>|null
     */
    private static function _screenSources(object $screen): ?array
    {
        $files = [];
        try {
            $classes = [];
            for ($class = new \ReflectionClass($screen); $class; $class = $class->getParentClass()) {
                $classes[] = $class;
                foreach ($class->getTraits() as $trait) {
                    $classes[] = $trait;
                }
            }
            foreach ($classes as $class) {
                $file = $class->getFileName();
                if ($file === false) {
                    if ($class->isInternal()) {
                        continue;
                    }

                    return null;   // classe sem arquivo (eval): não dá para ler
                }
                $normalized = str_replace('\\', '/', $file);
                if (str_contains($normalized, '/vendor/') || str_contains($normalized, '/mad-framework/')) {
                    continue;
                }
                $files[$file] = true;
            }
        } catch (\Throwable) {
            return null;
        }
        if ($files === []) {
            return null;
        }

        $sources = [];
        foreach (array_keys($files) as $file) {
            if (!array_key_exists($file, self::$_screenSourceCache)) {
                $source = @file_get_contents($file);
                self::$_screenSourceCache[$file] = is_string($source) ? $source : null;
            }
            if (self::$_screenSourceCache[$file] === null) {
                return null;
            }
            $sources[] = self::$_screenSourceCache[$file];
        }

        return $sources;
    }

    /**
     * Antes do `fill()` do registro: os campos da tela com valor que o Model
     * vai descartar porque NÃO são coluna da tabela.
     *
     * Lança MadValidationException para o campo gerado pela plataforma (o
     * `asInline()` de todo `onSave` pinta a mensagem no campo; nada é gravado)
     * e anota para o aviso o campo sem a âncora que a tela não usa.
     *
     * @param array<string,mixed>                $values o que vai para o `fill()` (só escalares)
     * @param array<string,array<string,mixed>>  $schema
     *
     * @throws MadValidationException
     */
    private function _fieldsWithoutColumn(\Illuminate\Database\Eloquent\Model $record, array $values, array $schema): void
    {
        if (!$this->_notesNotStored() || $values === []) {
            return;
        }
        // Model que não aceita atribuição em massa nenhuma: o próprio Eloquent
        // recusa, com exceção — não é descarte calado.
        if ($record->totallyGuarded()) {
            return;
        }

        $declared = (array) ($this->_known[self::DECLARED]['k'] ?? []);
        $marked   = (array) ($this->_known[self::DECLARED]['o'] ?? []);
        $columns  = null;

        $orphans = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            // O Model aceita: o valor tem para onde ir. (A tela que grava o
            // mesmo formulário em DOIS registros não é avisada do campo que só
            // o outro tem.)
            if ($record->isFillable($name)) {
                $this->_storedNow[$name] = true;
                unset($this->_notStored['fields'][$name]);
                continue;
            }
            if (!isset($declared[$name]) || isset($marked[$name]) || isset($this->_assignedNow[$name])
                || str_contains($name, '__')) {
                continue;
            }
            if (!$this->_typedValue($name, $value, (array) ($schema[$name] ?? []))) {
                // Sem valor digitado o campo passa — e a mensagem que um Salvar
                // recusado deixou nele sai da tela (só no campo sem coluna: na
                // coluna de verdade a mensagem pode ser de outra regra).
                if (isset($this->_known[self::DECLARED]['a'][$name])) {
                    $columns ??= $this->_tableColumns($record) ?? false;
                    if ($columns !== false && !isset($columns[strtolower($name)])) {
                        $this->_passedNow[$name] = true;
                    }
                }
                continue;
            }
            $columns ??= $this->_tableColumns($record) ?? false;
            if ($columns === false) {
                return;   // sem as colunas da tabela não há o que afirmar
            }
            // Coluna que o Model protege (fora do `$fillable`) é outra história:
            // ela existe, e quem decide o que grava nela é o código.
            if (isset($columns[strtolower($name)]) || self::_modelKnows($record, $name)) {
                continue;
            }
            $orphans[] = $name;
        }
        if ($orphans === []) {
            return;
        }

        // De que tabela do modelo de dados é ESTE registro: a dos campos com
        // âncora que são coluna dele. O campo gerado para outra tabela (a
        // seção de outra entidade, gravada pelo código) não é órfão desta.
        $votes = [];
        foreach ((array) ($this->_known[self::DECLARED]['a'] ?? []) as $field => $entity) {
            if (isset($columns[strtolower((string) $field)])) {
                $votes[(int) $entity] = ($votes[(int) $entity] ?? 0) + 1;
            }
        }
        arsort($votes);
        $entities = array_keys($votes);
        $entity   = ($entities && (count($entities) === 1 || $votes[$entities[0]] > $votes[$entities[1]])) ? $entities[0] : null;

        $labels = $this->_fieldLabels();
        $posted = $_POST['mad_model'] ?? null;
        $posted = is_array($posted) ? $posted : [];
        $table  = (string) $record->getTable();

        $errors = [];
        foreach ($orphans as $name) {
            $label = trim((string) ($labels[$name] ?? '')) ?: $name;

            if ($entity !== null && (int) ($this->_known[self::DECLARED]['a'][$name] ?? 0) === $entity) {
                $errors[$name] = self::_text(
                    'mad.form.field_without_column',
                    ['field' => $label, 'table' => $table, 'name' => $name],
                    'O campo :field não está ligado a nenhuma coluna da tabela :table: o valor digitado não seria gravado. Avise o administrador do sistema (campo `:name`).',
                );
                continue;
            }

            // Sem a âncora: só o que o usuário digitou nesta requisição, num
            // campo em que ele pode digitar e que a tela não usa.
            if (isset($this->_storedNow[$name]) || isset($this->_notStored['fields'][$name])
                || !array_key_exists($name, $posted) || !empty($this->readonly[$name]) || !empty($this->disabled[$name])
                || $this->_screenUses($name) !== false) {
                continue;
            }
            $this->_notStored['fields'][$name] = ['label' => $label, 'table' => $table];
        }

        if ($errors) {
            throw new MadValidationException($errors, array_keys($errors), '', $this->_knownFieldNames($this->fields));
        }
    }

    /**
     * Antes do `fill()` de uma linha de detalhe: as colunas da lista em que o
     * usuário digita, com valor, que o Model da linha vai descartar porque não
     * são coluna da tabela dele. Só aviso — a lista não tem âncora do gerador,
     * e uma coluna calculada na tela é coisa comum.
     *
     * Com gancho de gravação (`onSaveDetail`) nada é dito: o gancho recebe a
     * linha inteira, e é ele quem sabe o que fazer com a coluna.
     *
     * @param array<string,mixed> $values o que vai para o `fill()` da linha
     */
    private function _rowColumnsWithoutColumn(string $detailName, \Illuminate\Database\Eloquent\Model $instance, array $values, bool $hasHook): void
    {
        $typed = $this->_known[self::DECLARED]['d'][$detailName]['t'] ?? null;
        if ($hasHook || $detailName === '' || !is_array($typed) || !$this->_notesNotStored() || $instance->totallyGuarded()) {
            return;
        }

        $marked  = (array) ($this->_known[self::DECLARED]['o'] ?? []);
        $columns = null;
        foreach ($values as $column => $value) {
            $column = (string) $column;
            if (!isset($typed[$column]) || isset($marked[$column]) || isset($this->_notStored['rows'][$detailName][$column])
                || $instance->isFillable($column)
                || !is_scalar($value) || is_bool($value) || trim((string) $value) === '') {
                continue;
            }
            $columns ??= $this->_tableColumns($instance) ?? false;
            if ($columns === false) {
                return;
            }
            if (isset($columns[strtolower($column)]) || self::_modelKnows($instance, $column)
                || $this->_screenUses($column) !== false) {
                continue;
            }
            $this->_notStored['rows'][$detailName][$column] = (string) $instance->getTable();
        }
    }

    /** Pista no log para quem cuida do sistema: o que não foi gravado, em qual tela (nunca o valor). */
    private static function _logNotStored(string $what, string $table): void
    {
        $screen = MadFormRegistry::actingScreen();
        $tela   = is_object($screen) ? (string) preg_replace('/@anonymous.*$/s', '@anonymous', $screen::class) : '-';
        $msg    = sprintf(
            '[MadForm] %s de %s recebeu valor do usuário e NÃO foi gravado: não é coluna de "%s" e a tela não usa o valor.'
            . ' Ligue o campo a uma coluna, ou, se ele é só de tela, declare: screen-only na tag ou $this->form->screenOnly(\'...\') no mount().',
            $what,
            $tela,
            $table,
        );
        try {
            function_exists('logger') ? logger()->warning($msg) : error_log($msg);
        } catch (\Throwable) {
            error_log($msg);
        }
    }

    /** O aviso (diálogo) do que o usuário digitou e este Salvar não gravou, ou null se não há o que avisar. */
    private function _notStoredNoticeOp(): ?array
    {
        $fields = $this->_notStored['fields'];
        $rows   = $this->_notStored['rows'];
        $this->_notStored = ['fields' => [], 'rows' => []];
        if (!$fields && !$rows) {
            return null;
        }

        $and  = ' ' . self::_text('mad.detail.and', [], 'e') . ' ';
        $list = static function (array $items) use ($and): string {
            $last = array_pop($items);

            return $items ? implode(', ', $items) . $and . $last : (string) $last;
        };

        foreach ($fields as $name => $field) {
            self::_logNotStored(sprintf('campo "%s"', $name), $field['table']);
        }
        foreach ($rows as $detail => $columns) {
            foreach ($columns as $column => $table) {
                self::_logNotStored(sprintf('coluna "%s" da lista "%s"', $column, $detail), $table);
            }
        }

        $parts = [];
        if ($fields) {
            $names = array_values(array_unique(array_column($fields, 'label')));
            $table = (string) (reset($fields)['table'] ?? '');
            $parts[] = count($names) === 1
                ? self::_text('mad.form.not_stored_field_one', ['fields' => $list($names), 'table' => $table], 'O que foi digitado em :fields não foi gravado: o campo não está ligado a nenhuma coluna da tabela :table.')
                : self::_text('mad.form.not_stored_field_many', ['fields' => $list($names), 'table' => $table], 'O que foi digitado em :fields não foi gravado: os campos não estão ligados a nenhuma coluna da tabela :table.');
        }
        foreach ($rows as $detail => $columns) {
            // No aviso vão os rótulos da tela (a coluna e a lista), quando ela os tem.
            $entry  = (array) ($this->_known[self::DECLARED]['d'][$detail] ?? []);
            $names  = array_map(
                static fn ($column) => is_string($entry['t'][$column] ?? null) ? $entry['t'][$column] : (string) $column,
                array_keys($columns),
            );
            $table  = (string) reset($columns);
            $detail = is_string($entry['tl'] ?? null) ? $entry['tl'] : (string) $detail;
            $parts[] = count($names) === 1
                ? self::_text('mad.form.not_stored_column_one', ['columns' => $list($names), 'list' => $detail, 'table' => $table], 'Na lista :list, o que foi digitado em :columns não foi gravado: a coluna não existe na tabela :table.')
                : self::_text('mad.form.not_stored_column_many', ['columns' => $list($names), 'list' => $detail, 'table' => $table], 'Na lista :list, o que foi digitado em :columns não foi gravado: as colunas não existem na tabela :table.');
        }
        $parts[] = self::_text('mad.form.not_stored_tail', [], 'O restante foi salvo. Avise o administrador do sistema.');

        return [
            'op'      => 'alert',
            'message' => implode(' ', $parts),
            'type'    => 'warning',
            'title'   => self::_text('mad.form.not_stored_title', [], 'Valor não gravado'),
        ];
    }

    // ── Campos gravados na PRÓPRIA coluna do registro ─────────────────────────
    //
    // Na coluna não há linha por item: "o que a tela mostrou" (a base em
    // `$_known`) é comparado com o que está na coluna na hora do Salvar. O que
    // outra aba gravou nesse meio tempo não é desta tela — e fica.

    /** Tipos de campo de seleção múltipla que gravam a lista na coluna (por vírgula). */
    private const COLUMN_SELECTION_TYPES = ['checkbox-group', 'db-checkbox-group', 'checklist', 'multi-search', 'db-multi-search'];

    /** O campo é uma seleção múltipla gravada na própria coluna? (`mode="table"` vai para outra tabela; `manual`, o código da tela grava.) */
    private static function _isColumnSelection(string $type, array $props): bool
    {
        return in_array($type, self::COLUMN_SELECTION_TYPES, true)
            && !in_array(($props['mode'] ?? ''), ['table', 'manual'], true);
    }

    /** Chave do registro que JÁ existe no banco — null para o registro novo (ou o que não é Model). */
    private static function _existingKey(object $record): int|string|null
    {
        if (!$record instanceof \Illuminate\Database\Eloquent\Model || !$record->exists) {
            return null;
        }
        $key = $record->getKey();

        return (is_int($key) || (is_string($key) && $key !== '')) ? $key : null;
    }

    /**
     * Seleção múltipla gravada na própria coluna — por DIFERENÇA, item a item,
     * sobre o que está na coluna agora:
     *
     *   - sai o item que este formulário entregou marcado (`$_known`) e que não
     *     voltou no POST: o que a tela mostrou e o usuário desmarcou;
     *   - entra, no fim, a marca que ainda não está na coluna;
     *   - o resto fica onde está, na ordem em que está.
     *
     * Antes a coluna recebia o que veio no POST. O item marcado que a lista de
     * opções não mostra (opção inativa, lista filtrada, opção que não carregou)
     * não é desenhado, não volta no POST — e saía da coluna em qualquer Salvar.
     * E a aba que ficou aberta tirava o que outra aba tinha marcado depois.
     *
     * Se nada muda, a coluna não é regravada (fica com o texto que tinha).
     * Marca que este formulário entregou e que não está mais na coluna foi
     * desmarcada por outra aba (ou outra pessoa) depois: não volta, e o
     * usuário é avisado.
     *
     * Sem base (registro novo, tela aberta antes desta versão, tela que não diz
     * qual registro tem aberto): vale o que veio no POST, como sempre valeu.
     *
     * Só entra aqui a seleção que o NAVEGADOR mandou neste request, e só quando
     * o campo é uma coluna do registro. O valor que o código da tela pôs no
     * formulário, o campo que não veio no POST e o campo que não é coluna
     * (a tela grava a seleção por conta própria) seguem como sempre.
     *
     * @param array<string,mixed> $dataArray campos que vão para o registro (por referência)
     */
    private function _mergeColumnSelection(object $record, string $fieldName, array $props, array &$dataArray): void
    {
        $posted = self::selectionKeys($this->fields[$fieldName] ?? []);

        $sent = $_POST['mad_model'] ?? null;
        if (!is_array($sent) || !array_key_exists($fieldName, $sent) || self::selectionKeys($sent[$fieldName]) !== $posted) {
            return;
        }
        if ($record instanceof \Illuminate\Database\Eloquent\Model
            && $record->exists
            && (!$record->isFillable($fieldName) || !array_key_exists($fieldName, $record->getAttributes()))) {
            return;
        }

        $parentId = self::_existingKey($record);
        $known    = $parentId !== null ? $this->_knownFor($fieldName, $parentId) : null;

        if ($known === null) {
            // A coluna passa a ter exatamente o que veio: é o que a tela tem.
            $this->_baseUpdate($fieldName, array_fill_keys($posted, ''), $parentId === null ? $posted : []);

            return;
        }

        $raw         = $record->$fieldName ?? null;
        $current     = self::selectionKeys($raw, (string) ($props['separator'] ?? ','));
        $unconfirmed = (array) ($this->_known[$fieldName]['u'] ?? []);
        $postedSet   = array_fill_keys($posted, true);
        $currentSet  = array_fill_keys($current, true);

        // 1) Sai o que a tela entregou marcado e não voltou.
        $final = [];
        foreach ($current as $key) {
            if (array_key_exists($key, $known) && !isset($postedSet[$key])) {
                continue;
            }
            $final[] = $key;
        }

        // 2) Entra a marca que ainda não está na coluna.
        $pending = [];   // marcas incluídas agora (ou ainda não confirmadas)
        $gone    = 0;    // marcas que outra tela já tinha desmarcado
        foreach ($posted as $key) {
            if (isset($currentSet[$key])) {
                if (isset($unconfirmed[$key])) {
                    $pending[] = $key;
                }
                continue;
            }
            // A tela (desatualizada) ainda mostra marcado o que outra aba, ou
            // outra pessoa, já desmarcou: quem desmarcou por último decidiu. A
            // marca que nasceu num Salvar desfeito nunca foi gravada — essa entra.
            if (array_key_exists($key, $known) && !isset($unconfirmed[$key])) {
                $gone++;
                continue;
            }
            $final[]   = $key;
            $pending[] = $key;
        }

        // A marca que entra agora tem de ser de um item que quem salva enxerga
        // (campo sobre tabela) — antes do aviso abaixo e de qualquer escrita.
        $this->_guardSelectionSource($fieldName, array_values(array_diff($final, $current)));
        $this->_selectionChecked[$fieldName] = true;

        if ($gone > 0) {
            $label = trim((string) ($props['label'] ?? ''));
            $this->warnChildRowsGone([], [], [$label !== '' ? $label : $fieldName => $gone]);
        }

        if ($final === $current) {
            // Nada mudou: a coluna não é regravada.
            unset($dataArray[$fieldName]);
        } elseif (is_array($raw)) {
            // Coluna que o Model lê como lista (`casts`): recebe a lista.
            if ($record instanceof \Illuminate\Database\Eloquent\Model && $record->isFillable($fieldName)) {
                $record->$fieldName = $final;
            }
            unset($dataArray[$fieldName]);
        } elseif (is_string($raw) && str_starts_with(ltrim($raw), '[')) {
            // Coluna gravada como lista JSON: continua no formato em que estava.
            $dataArray[$fieldName] = json_encode(array_map('strval', $final), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $dataArray[$fieldName] = implode(',', $final);
        }

        // A tela não é redesenhada depois do Salvar: ela continua mostrando o
        // que mandou — inclusive o que outra tela desmarcou, que seguirá vindo
        // a cada Salvar até ela ser recarregada.
        $this->_baseUpdate($fieldName, array_fill_keys($posted, ''), $pending);
    }

    // ── Seleção múltipla sobre tabela: a marca nova é de quem salva? ─────────
    //
    // O campo de seleção múltipla sobre uma tabela (grupo de caixas, select
    // com caixas, multi busca, checklist) só lista o que a pessoa logada
    // enxerga: a consulta do Model das opções carrega o escopo de unidade, de
    // empresa e de visibilidade de usuários. Mas a coluna que guarda a lista
    // por vírgula não é chave estrangeira — o `rules()` do Model não tem regra
    // `exists` para ela —, e o Salvar acrescentava à coluna qualquer número que
    // viesse na requisição.
    //
    // A tela anota, no estado cifrado (`$_known[DECLARED]['q']`), de que Model
    // e de que coluna-chave saem as opções de cada um desses campos, e o Salvar
    // confere na MESMA consulta as marcas que vão ENTRAR. A anotação não vai no
    // formulário `__mad_form`: ele é o navegador quem devolve, e dá para
    // omiti-lo ou trocá-lo pelo de outra tela.
    //
    //   - o que a coluna já tem fica: o registro pode apontar, de antes, para um
    //     item que quem edita não enxerga, e salvar sem mexer nele não é recusado;
    //   - o filtro da tag (`:filters`, `depends-on`) não é reaplicado: confere-se
    //     o que o Model deixa a pessoa ver, como a regra `exists` de uma chave;
    //   - campo com `:query` própria ou com opções fixas não tem Model a
    //     conferir (a consulta é do código da tela) e segue como sempre;
    //   - o valor que o CÓDIGO da tela atribui neste request (`set()`,
    //     `fill([...])`, os extras do `save()`) não passa por aqui.

    /**
     * Um campo de seleção múltipla foi desenhado: de onde saem as opções dele.
     * `$source` vazio = opções fixas ou consulta própria (nada a conferir).
     *
     * @internal chamado por MadFormRegistry::register()
     *
     * @param array{model?: string, key?: string} $source
     */
    public function declareOptionsSource(string $name, array $source, string $separator = ''): void
    {
        if ($name === '' || $this->_openedBefore || $this->_editorScope !== null) {
            return;
        }
        $model = trim((string) ($source['model'] ?? ''));
        if ($model === '') {
            // A tela passou a desenhar o campo com outra fonte.
            unset($this->_known[self::DECLARED]['q'][$name]);
            if (($this->_known[self::DECLARED]['q'] ?? null) === []) {
                unset($this->_known[self::DECLARED]['q']);
            }

            return;
        }

        $this->_openDeclared();
        $entry = ['m' => $model];
        $key   = trim((string) ($source['key'] ?? ''));
        if ($key !== '' && $key !== 'id') {
            $entry['k'] = $key;
        }
        if ($separator !== '' && $separator !== ',') {
            $entry['s'] = $separator;
        }
        $this->_known[self::DECLARED]['q'][$name] = $entry;
    }

    /**
     * Confere, de cada seleção múltipla sobre tabela que a TELA declarou, as
     * marcas que este Salvar vai acrescentar à coluna: o que está em
     * `$dataArray` menos o que o registro já tem.
     *
     * Roda pelo estado da tela, depois do laço do formulário recebido: sem o
     * `__mad_form` (ou com o de outra tela) aquele laço não reconhece o campo,
     * e o valor ia para a coluna como veio. Vale também para o valor que ficou
     * no formulário de uma requisição anterior.
     *
     * @param array<string,mixed> $dataArray campos que vão para o registro
     *
     * @throws ReferenceViolation marca de um item que quem salva não enxerga
     */
    private function _guardColumnSelections(object $record, array $dataArray): void
    {
        foreach ((array) ($this->_known[self::DECLARED]['q'] ?? []) as $name => $source) {
            $name = (string) $name;
            // Já conferido pelo _mergeColumnSelection (ele sabe exatamente o
            // que entra), ou campo que não é coluna deste registro (a seleção
            // em outra tabela, a que o código da tela grava por conta própria).
            if (!is_array($source) || isset($this->_selectionChecked[$name])
                || ($record instanceof \Illuminate\Database\Eloquent\Model && !$record->isFillable($name))) {
                continue;
            }
            // Sem valor a gravar ou valor em lista (o `fill()` não grava lista
            // em coluna): nenhuma marca entra.
            $entering = [];
            if (array_key_exists($name, $dataArray) && !is_array($dataArray[$name])) {
                // O que vai para a coluna sai por vírgula (ou em JSON); o que
                // está nela pode estar com o separador da tag.
                $raw      = $record->$name ?? null;
                $current  = self::selectionKeys($raw);
                if (is_string($source['s'] ?? null) && $source['s'] !== '') {
                    $current = array_merge($current, self::selectionKeys($raw, $source['s']));
                }
                $entering = array_values(array_diff(self::selectionKeys($dataArray[$name]), $current));
            }
            $this->_guardSelectionSource($name, $entering);
        }
    }

    /**
     * As marcas que vão entrar em `$fieldName` (na coluna, ou como ligação nova
     * de um `mode="table"`) são de itens que a consulta do Model das opções
     * devolve para quem salva? Uma marca recusada recusa o Salvar, com a
     * mensagem no campo.
     *
     * @param list<string> $entering as marcas NOVAS (o que já está gravado não vem aqui)
     *
     * @throws ReferenceViolation
     */
    private function _guardSelectionSource(string $fieldName, array $entering): void
    {
        $source = $this->_known[self::DECLARED]['q'][$fieldName] ?? null;
        if (!is_array($source) || isset($this->_assignedNow[$fieldName])) {
            return;
        }
        if ($entering !== []
            && ReferenceGuard::outsideSource((string) ($source['m'] ?? ''), (string) ($source['k'] ?? 'id'), $entering) !== []) {
            ReferenceGuard::logRefused(sprintf('uma marca do campo "%s"', $fieldName));

            throw new ReferenceViolation(
                [$fieldName => self::_text('mad.form.selection_invalid', [], 'Um dos itens marcados não pode ser gravado: ele não está na sua lista ou não existe mais.')],
                [$fieldName],
            );
        }
        // Passou: a mensagem que um Salvar recusado deixou no campo sai da tela.
        $this->_passedNow[$fieldName] = true;
    }

    /**
     * Anota o que um campo gravado na própria coluna passa a "ter" depois deste
     * Salvar. Só vira base quando o registro for gravado (_applyBaseUpdates).
     *
     * @param array<int|string,string> $keys    chave => valor da base (ver $_known)
     * @param list<int|string>         $pending chaves criadas agora (não confirmadas até o commit)
     */
    private function _baseUpdate(string $name, array $keys, array $pending = [], bool $file = false): void
    {
        $this->_baseUpdates[$name] = ['k' => $keys, 'u' => array_values($pending), 'file' => $file];
    }

    /**
     * Passa para `$_known` o que os campos gravados na própria coluna têm
     * depois de o registro ser gravado.
     *
     * `$saved` (o `save()` acabou de gravar o registro): a base acompanha a
     * transação — volta ao que era se ela for desfeita, e as chaves criadas
     * agora só são confirmadas quando a transação mais externa confirmar
     * (_remember).
     *
     * Sem `$saved` (o `fillRecord()` foi chamado direto e o código da tela
     * grava o registro depois): não há como saber daqui se a gravação deu
     * certo. Só o registro que já existe ganha base, e o que foi criado agora
     * fica como NÃO confirmado para sempre — nunca vira "outra pessoa removeu"
     * num Salvar seguinte.
     */
    private function _applyBaseUpdates(object $record, bool $saved): void
    {
        $updates            = $this->_baseUpdates;
        $this->_baseUpdates = [];
        if (!$updates) {
            return;
        }

        $parentId = $saved
            ? (($record instanceof \Illuminate\Database\Eloquent\Model) ? $record->getKey() : null)
            : self::_existingKey($record);
        if ($parentId === null || $parentId === '') {
            return;
        }

        foreach ($updates as $name => $update) {
            $name = (string) $name;
            // Campo de arquivo: `v` é o valor que o formulário guarda para o
            // campo — o mesmo de quando a tela foi desenhada, porque o Salvar
            // não mexe nele (ver _fileValueUntouched).
            $seen = null;
            if ($update['file']) {
                $seen = $this->_knownFor($name, $parentId) !== null && is_string($this->_known[$name]['v'] ?? null)
                    ? $this->_known[$name]['v']
                    : (is_scalar($this->fields[$name] ?? null) ? (string) $this->fields[$name] : '');
            }

            if ($saved) {
                $this->_remember($name, $parentId, $update['k'], $record, $update['u']);
                if (!empty($update['saved'])) {
                    $this->_announceSavedFiles($name, [$update['saved']], $record);
                }
            } else {
                if ($update['file'] && !$update['k']) {
                    continue;   // remoção sem confirmação: a base fica como estava
                }
                $this->_known[$name] = ['p' => (string) $parentId, 'k' => $update['k']];
                if ($update['k']) {
                    $this->_known[$name]['u'] = array_fill_keys(array_keys($update['k']), 1);
                }
            }
            if ($seen !== null && isset($this->_known[$name])) {
                $this->_known[$name]['v'] = $seen;
            }
        }
    }

    /**
     * O valor que o formulário guarda para um campo de arquivo único é o que a
     * tela recebeu quando foi desenhada (ninguém — nem o código da tela — mexeu
     * nele)? Esse valor não diz nada sobre o que está na coluna agora: não é
     * gravado.
     */
    private function _fileValueUntouched(object $record, string $fieldName, mixed $value): bool
    {
        $parentId = self::_existingKey($record);
        if ($parentId === null || $this->_knownFor($fieldName, $parentId) === null) {
            return false;
        }
        $seen = $this->_known[$fieldName]['v'] ?? null;

        return is_string($seen) && ($value === null || is_scalar($value)) && (string) $value === $seen;
    }

    /**
     * O arquivo que esta tela tem num campo de arquivo único (`$name`) do
     * registro `$parentId`: ['path' => caminho ('' = a tela não mostra arquivo),
     * 'hash' => impressão digital quando foi ESTA tela que o gravou].
     *
     * Null quando não há base — ou quando ela veio de um Salvar cuja transação
     * não confirmou: aí vale o comportamento de quem não tem base.
     *
     * @return array{path: string, hash: string}|null
     */
    private function _fileBase(string $name, mixed $parentId): ?array
    {
        $known = $parentId !== null ? $this->_knownFor($name, $parentId) : null;
        if ($known === null || !empty($this->_known[$name]['u'])) {
            return null;
        }
        $path = (string) (array_key_first($known) ?? '');

        return ['path' => $path, 'hash' => $path !== '' ? (string) $known[$path] : ''];
    }

    /** Impressão digital do conteúdo de um arquivo enviado ('' se não der para ler). */
    private static function _fileHash(string $tmpPath): string
    {
        return ($tmpPath !== '' && is_file($tmpPath)) ? (string) @sha1_file($tmpPath) : '';
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
    private const FORBIDDEN_UPLOAD_EXTENSIONS = MadUploadRules::BLOCKED_EXTENSIONS;

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

    // ── O disco acompanha a transação do Salvar ──────────────────────────────
    //
    // O disco não tem rollback. Apagar o arquivo NA HORA em que a linha dele é
    // removida deixava, num Salvar que falha depois, a linha de volta no banco
    // (a transação desfaz) apontando para um arquivo que não existe mais.

    /**
     * A conexão do registro `$owner`, quando ELA tem uma transação aberta — ou
     * null (sem transação nessa conexão, o que foi gravado nela está gravado).
     */
    private function _transactingConnection(?object $owner): ?object
    {
        try {
            $connection = ($owner !== null && method_exists($owner, 'getConnection')) ? $owner->getConnection() : null;
            if ($connection !== null && method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
                return $connection;
            }
        } catch (\Throwable $e) {
            // sem conexão resolvível: tratado como "sem transação"
        }

        return null;
    }

    /**
     * Roda `$callback` quando a transação em curso CONFIRMAR — na hora, se não
     * há transação aberta (o que foi gravado está gravado). Transação desfeita:
     * não roda.
     *
     * `$owner` é o registro cuja gravação está em jogo (a conexão dele).
     */
    private function _afterCommit(?object $owner, callable $callback): void
    {
        $connection = $this->_transactingConnection($owner);
        if ($connection === null || !method_exists($connection, 'afterCommit')) {
            $callback();

            return;
        }

        $ran  = false;
        $once = static function () use (&$ran, $callback): void {
            if (!$ran) {
                $ran = true;
                $callback();
            }
        };
        try {
            $connection->afterCommit($once);
        } catch (\RuntimeException $e) {
            if ($ran) {
                throw $e;   // a exceção é do próprio callback, não da conexão
            }
            // Sem gerenciador de transações: o que foi gravado está gravado.
            $once();
        }
    }

    /**
     * Tira do disco o arquivo de uma linha que este Salvar removeu — só quando
     * a transação confirmar. Se ela for desfeita, a linha volta e o arquivo
     * continua lá.
     *
     * Não apaga o caminho que este mesmo Salvar gravou de novo (ver
     * $_filesStored). Uma falha do disco aqui não derruba o Salvar: o banco já
     * confirmou, e o que sobra é um arquivo sem dono — vai para o log.
     */
    private function _discardFile(string $path, ?object $owner = null): void
    {
        if ($path === '') {
            return;
        }

        $this->_afterCommit($owner, function () use ($path): void {
            if (isset($this->_filesStored[$path])) {
                return;
            }
            try {
                MadUploadStorage::delete($path);
            } catch (\Throwable $e) {
                error_log('[MadForm] o arquivo removido "' . $path . '" não saiu do disco: ' . $e->getMessage());
            }
        });
    }

    /**
     * Grava no disco um arquivo enviado, acompanhando a transação do Salvar:
     *
     *  - caminho novo (nome sorteado — `$unique`: os modos `prefix` e `unique`
     *    de `file-name` —, ou nome fixo que ainda não existia): o arquivo de um
     *    Salvar desfeito sai do disco junto com a transação — a linha dele
     *    nunca existiu;
     *  - nome fixo (`original`, `record`) sobre um arquivo que JÁ existe: o
     *    conteúdo novo espera num caminho provisório e só ocupa o lugar do
     *    antigo quando a transação confirma. Gravar por cima na hora trocava o
     *    conteúdo do arquivo de um registro que ainda aponta para ele — e, se o
     *    Salvar falhasse depois, o arquivo antigo não voltava.
     *
     * Sem transação aberta o arquivo é gravado na hora, como sempre.
     */
    private function _storeFile(string $tmpPath, string $path, ?object $owner = null, bool $unique = false): bool
    {
        $connection = $this->_transactingConnection($owner);

        $existed = false;
        if (!$unique && $connection !== null) {
            try {
                $existed = MadUploadStorage::exists($path);
            } catch (\Throwable $e) {
                $existed = false;
            }
        }
        $target = $existed ? $path . '.' . bin2hex(random_bytes(6)) . '.tmp' : $path;

        if (!MadUploadStorage::put($tmpPath, $target)) {
            return false;
        }
        $this->_filesStored[$path] = true;

        if ($connection === null) {
            return true;   // sem transação não há o que desfazer nem o que esperar
        }

        if ($existed) {
            $this->_afterCommit($owner, static function () use ($target, $path): void {
                try {
                    MadUploadStorage::replace($target, $path);
                } catch (\Throwable $e) {
                    error_log('[MadForm] o arquivo novo de "' . $path . '" ficou em "' . $target . '": ' . $e->getMessage());
                }
            });
        }

        try {
            if (method_exists($connection, 'afterRollBack')) {
                $connection->afterRollBack(function () use ($path, $target): void {
                    unset($this->_filesStored[$path]);
                    try {
                        MadUploadStorage::delete($target);
                    } catch (\Throwable $e) {
                        error_log('[MadForm] o arquivo "' . $target . '" de um Salvar desfeito não saiu do disco: ' . $e->getMessage());
                    }
                });
            }
        } catch (\Throwable $e) {
            // Sem gerenciador de transações: o arquivo fica, como sempre ficou.
        }

        return true;
    }

    /** O `file-name` do campo sorteia o nome do arquivo (nunca repete um caminho)? */
    private static function _uniqueFileNames(mixed $mode): bool
    {
        return !in_array((string) $mode, ['original', 'record'], true);
    }

    // ── Arquivo novo que acabou de ser gravado: o campo fica sabendo ─────────
    //
    // A tela não é redesenhada depois do Salvar. O campo de upload continuava
    // com o arquivo como "novo" e o mandava de novo a cada Salvar: o servidor
    // apagava a linha (e o arquivo) do envio anterior e gravava outra, com
    // outra chave — quem apontava para a chave antiga ficava órfão.
    //
    // Cada arquivo novo viaja com um identificador que o navegador sorteou
    // (`__mad_new_files[campo][]`, na ordem dos arquivos). A resposta devolve,
    // por identificador, onde o arquivo foi gravado (op `files_saved`), e o
    // campo passa a tratá-lo como um arquivo que já existe.

    /**
     * Identificador de cada arquivo novo de um campo, na ordem do envio. Vazio
     * ('') quando o navegador não mandou (tela aberta antes desta versão) ou
     * quando a lista não casa com os arquivos — sem identificador o campo não
     * é avisado e segue como antes.
     *
     * @return list<string>
     */
    private function _newFileIds(string $field, int $count): array
    {
        $ids = $_POST['__mad_new_files'][$field] ?? [];
        if (is_string($ids)) {
            $ids = [$ids];
        }
        $ids = is_array($ids) ? array_values($ids) : [];
        if ($count <= 0 || count($ids) !== $count) {
            return $count > 0 ? array_fill(0, $count, '') : [];
        }

        return array_map(
            static fn ($id): string => (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) ? $id : '',
            $ids
        );
    }

    /**
     * Enfileira o aviso `files_saved` para o campo `$field` (nome do Upload
     * Múltiplo, ou `lista__linha__coluna` de uma célula Arquivos) — só quando a
     * transação confirmar: de um Salvar desfeito o arquivo não ficou gravado, e
     * o campo tem de mandá-lo de novo na próxima tentativa.
     *
     * @param list<array{uid:string,key:string,name:string,url:string,id?:int|string}> $files
     */
    private function _announceSavedFiles(string $field, array $files, ?object $owner): void
    {
        $files = array_values(array_filter($files, static fn (array $f): bool => ($f['uid'] ?? '') !== ''));
        if ($field === '' || !$files) {
            return;
        }

        $this->_afterCommit($owner, function () use ($field, $files): void {
            $this->_pendingFlOps[] = ['op' => 'files_saved', 'field' => $field, 'files' => $files];
        });
    }

    /** Nome de exibição de um arquivo gravado: sem o prefixo sorteado do `file-name="prefix"`. */
    private static function _storedFileLabel(string $path): string
    {
        $bn = basename($path);

        return preg_match('/^[a-f0-9]{13,16}_(.+)$/i', $bn, $m) ? $m[1] : $bn;
    }

    /** Endereço de download de um arquivo gravado no disco (o mesmo que o Blade do campo emite). */
    private static function _storedFileUrl(string $path, string $label): string
    {
        return function_exists('mad_download_url') ? (string) mad_download_url($path, $label) : '';
    }

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
                // Identificador que o navegador deu a cada arquivo novo da
                // célula, na mesma ordem (ver _newFileIds).
                $ids = $this->_newFileIds((string) $key, count($names));
                $n   = 0;
                foreach (array_keys($names) as $i) {
                    $out[$key][] = [
                        'name'     => $f['name'][$key][$i]     ?? '',
                        'tmp_name' => $f['tmp_name'][$key][$i] ?? '',
                        'error'    => $f['error'][$key][$i]    ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $f['size'][$key][$i]     ?? 0,
                        'uid'      => $ids[$n++] ?? '',
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
     * @return array<int, array{name:string,tmp_name:string,error:int,size:int,uid:string}>
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
            // Sem o formulário recebido não há campo de upload a gravar — mas a
            // Imagem guardada na própria coluna entra pelo `fill()`, e os
            // limites dela estão no estado da tela.
            if (!empty($this->_known[self::DECLARED]['i'])) {
                $this->_checkUploads([], $record);
            }

            return;
        }

        // Tamanho, tipo e quantidade que cada campo de upload declara, e o
        // arquivo que o PHP recusou: erro NO CAMPO, antes de gravar qualquer coisa.
        $this->_checkUploads($schema, $record);

        foreach ($schema as $fieldName => $props) {
            $type    = $props['type'] ?? '';
            $storage = $props['storage'] ?? '';

            if (($type !== 'file' && $type !== 'image') || !$storage) {
                continue;
            }
            if (!$this->_writesAsDrawn((string) $fieldName, (array) $props)) {
                continue;
            }

            if ($storage === 'disk') {
                $this->_processDiskFile($record, (string) $fieldName, $props, !empty($removed[$fieldName]));
                continue;
            }

            // storage="db" → o arquivo (e a remoção) são tratados pelo
            // _processBlobUpload no _afterStore (requer prepared statement
            // para gravar/limpar a coluna BLOB). Aqui só o nome.
            if (!empty($removed[$fieldName])) {
                continue;
            }
            if (empty($_FILES[$fieldName]['tmp_name'])
                || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            // Sanitiza upfront — tambem aborta upload se extensao proibida
            // (php, htaccess, etc). Armazena o nome sanitizado (NAO o cru do
            // POST — evita XSS quando o nome e renderizado como link de download).
            $cleanName  = $this->_sanitizeUploadName($_FILES[$fieldName]['name']);
            $nameColumn = $props['nameColumn'] ?? '';
            if ($nameColumn) {
                $record->$nameColumn = $cleanName;
            }
        }
    }

    /**
     * Campo de arquivo ÚNICO gravado no disco (`<mad-file-field>`,
     * `<mad-image-field>`, avatar, assinatura): remoção e troca.
     *
     * O que está em jogo é a coluna como ela está AGORA (o registro lido neste
     * Salvar), não o que a tela mostrava quando abriu:
     *
     *  - o arquivo antigo só sai do disco quando a transação confirma
     *    (_discardFile). Apagá-lo na hora deixava, num Salvar que falha depois,
     *    o registro de volta apontando para um arquivo que não existe mais;
     *  - REMOVER só remove o arquivo que esta tela mostrou. O campo não é
     *    redesenhado depois do Salvar, e a marca de removido segue indo em todo
     *    Salvar: sem isto ela apagava o arquivo que outra aba gravasse depois;
     *  - o arquivo que esta tela JÁ gravou (mesmo conteúdo) não é troca: o
     *    `<input type="file">` também não é esvaziado, e o manda de novo a cada
     *    Salvar — antes ele era regravado com outro nome toda vez, por cima do
     *    que outra aba tivesse posto no lugar.
     *
     * Sem base (tela aberta antes desta versão, tela que não diz qual registro
     * tem aberto): remove e troca o que estiver na coluna, como sempre.
     */
    private function _processDiskFile(object $record, string $fieldName, array $props, bool $removed): void
    {
        $nameColumn = (string) ($props['nameColumn'] ?? '');

        // Sem remoção e sem arquivo novo → mantém valor existente. (Antes de
        // ler a coluna: o campo de arquivo de um editor de detalhe também está
        // no schema, e não é coluna deste registro.)
        $file = $_FILES[$fieldName] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === ''
            || ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            $file = null;
        }
        if (!$removed && $file === null) {
            return;
        }

        $current = (string) ($record->$fieldName ?? '');
        $base    = $this->_fileBase($fieldName, self::_existingKey($record));

        // ── Arquivo removido pelo usuário (clicou X no existente) ───────────
        if ($removed) {
            if ($base !== null && $current !== $base['path']) {
                // O que está na coluna não é o que esta tela mostrava: outra
                // aba (ou outra pessoa) já trocou ou removeu o arquivo — ou esta
                // tela nem mostrava arquivo (marca que sobrou de uma remoção
                // anterior). Nada a remover; se havia o que avisar, avisa.
                if ($base['path'] !== '' && $current !== '') {
                    $this->warnChildRowsGone([], [self::_storedFileLabel($base['path'])]);
                }
            } else {
                $this->_discardFile($current, $record);
                $record->$fieldName = null;
                if ($nameColumn !== '') {
                    $record->$nameColumn = null;
                }
            }
            // Daqui em diante esta tela não mostra arquivo. ('' marca a remoção
            // como não confirmada até a transação confirmar.)
            $this->_baseUpdate($fieldName, [], [''], true);

            return;
        }

        // ── Arquivo novo enviado ────────────────────────────────────────────
        // Sanitiza upfront para usar tanto no fs quanto no nameColumn —
        // tambem aborta upload se extensao proibida (php, htaccess, etc).
        $originalName = (string) ($file['name'] ?? '');
        $cleanName    = $this->_sanitizeUploadName($originalName);
        $hash         = self::_fileHash($file['tmp_name']);

        // O mesmo arquivo que esta tela já gravou: não é troca.
        if ($base !== null && $base['hash'] !== '' && $hash !== '' && hash_equals($base['hash'], $hash)) {
            if ($current !== $base['path']) {
                // …e ele não está mais na coluna: outra aba já o trocou ou removeu.
                $this->warnChildRowsGone([], [self::_storedFileLabel($base['path'])]);
            }

            return;
        }

        $folder   = $this->_normalizeUploadFolder((string) ($props['folder'] ?? 'uploads'));
        $fileName = $this->_buildFileName($originalName, $props['fileName'] ?? 'prefix', $record);
        $relPath  = "{$folder}/{$fileName}";          // path relativo gravado no banco

        if (!$this->_storeFile($file['tmp_name'], $relPath, $record, self::_uniqueFileNames($props['fileName'] ?? 'prefix'))) {
            return;
        }
        // O antigo sai quando a transação confirmar (e não sai se o novo
        // ocupou o mesmo caminho — `file-name="original"` / `"record"`).
        $this->_discardFile($current, $record);

        $record->$fieldName = $relPath;
        if ($nameColumn !== '') {
            // Armazena nome sanitizado (NAO o cru do POST — evita XSS quando
            // o nome e renderizado como link de download).
            $record->$nameColumn = $cleanName;
        }
        $this->_baseUpdate($fieldName, [$relPath => $hash], [$relPath], true);

        // O campo de arquivo fica sabendo que o arquivo foi gravado (op
        // `files_saved`, depois de o registro ser gravado e a transação
        // confirmar): deixa de mandá-lo a cada Salvar, e o Remover passa a
        // valer para ele. Só o `<mad-file-field>` manda identificador.
        $uid = ($props['type'] ?? '') === 'file' ? ($this->_newFileIds($fieldName, 1)[0] ?? '') : '';
        if ($uid !== '') {
            $label = $nameColumn !== '' ? $cleanName : self::_storedFileLabel($relPath);
            $this->_baseUpdates[$fieldName]['saved'] = [
                'uid' => $uid, 'key' => $relPath, 'name' => $label, 'url' => self::_storedFileUrl($relPath, $label),
            ];
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
        // O que os campos gravados na própria coluna passam a "ter" só vale
        // depois de o registro ser gravado (_applyBaseUpdates, mais abaixo).
        $this->_saving      = true;
        $this->_baseUpdates = [];
        try {
            $this->fillRecord($record);
        } finally {
            $this->_saving = false;
        }

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
        $this->_applyBaseUpdates($record, true);
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

    // ── Onde cada campo grava fora da coluna do registro ─────────────────────
    //
    // O Upload Múltiplo em outra tabela, a seleção múltipla em `mode="table"` e
    // os campos de arquivo com `storage` gravam com o que o formulário recebido
    // (`__mad_form`) descreve: tabela, chave estrangeira, tabela de ligação,
    // pasta, coluna do nome. Esse formulário é o navegador quem devolve — e dá
    // para devolver o de OUTRA tela do mesmo app. A tela anota, no estado
    // cifrado (`$_known[DECLARED]['w']`), como desenhou cada um desses campos, e
    // o Salvar só grava quando o formulário recebido o descreve igual.

    /** Quantas formas de desenhar o mesmo campo a tela guarda (pasta calculada a cada render, por exemplo). */
    private const WRITE_PRINTS = 8;

    /**
     * Um campo foi desenhado com estas props: se ele grava fora da coluna do
     * registro, fica anotado como.
     *
     * @internal chamado por MadFormRegistry::register()
     *
     * @param array<string,mixed> $props as props registradas (as mesmas do formulário `__mad_form`)
     */
    public function declareWrites(string $name, array $props): void
    {
        if ($name === '' || $this->_openedBefore) {
            return;
        }
        $this->_declareInlineImage($name, $props);

        $print = MadUploadRules::writePrint($props);
        if ($print === null) {
            return;
        }
        $this->_openDeclared();
        // Anotação criada por uma versão anterior (tela aberta antes desta):
        // segue gravando como sempre, até ser recarregada.
        if (!is_array($this->_known[self::DECLARED]['w'] ?? null)) {
            return;
        }
        $prints = (array) ($this->_known[self::DECLARED]['w'][$name] ?? []);
        unset($prints[$print]);
        $prints[$print] = 1;
        $this->_known[self::DECLARED]['w'][$name] = array_slice($prints, -self::WRITE_PRINTS, null, true);
    }

    /**
     * Imagem guardada na PRÓPRIA coluna (sem `storage`): os limites que a tela
     * declarou — Tamanho máximo e Tipos aceitos — ficam anotados no estado
     * (`$_known[DECLARED]['i']`), com os valores.
     *
     * Esse campo não grava fora da coluna: o conteúdo chega em base64 entre os
     * valores do formulário e entra no registro pelo `fill()`. Os limites eram
     * conferidos pelo que o formulário recebido diz do campo — e sem ele (ou
     * com o de uma tela sem limite) a conferência não rodava. Com a anotação,
     * o Salvar confere pelo que ESTA tela desenhou — ver _checkUploads().
     *
     * @param array<string,mixed> $props as props registradas do campo
     */
    private function _declareInlineImage(string $name, array $props): void
    {
        // Campo do editor de um detalhe: é coluna da linha, não deste formulário.
        if ($this->_editorScope !== null) {
            return;
        }
        $limits = MadUploadRules::inlineImageLimits($props);
        if ($limits === null) {
            // A tela passou a desenhar o campo de outro jeito (ou sem limite).
            unset($this->_known[self::DECLARED]['i'][$name]);
            if (($this->_known[self::DECLARED]['i'] ?? null) === []) {
                unset($this->_known[self::DECLARED]['i']);
            }

            return;
        }
        $this->_openDeclared();
        $this->_known[self::DECLARED]['i'][$name] = $limits;
    }

    /**
     * O formulário recebido descreve o campo `$name` como ESTA tela o desenhou?
     * Campo que não grava fora da coluna do registro passa sempre.
     *
     * @param array<string,mixed> $props props do campo no formulário recebido
     */
    private function _writesAsDrawn(string $name, array $props, bool $log = true): bool
    {
        if (!$this->declares() || !is_array($this->_known[self::DECLARED]['w'] ?? null)) {
            return true;   // tela aberta antes desta versão: como sempre
        }
        $print = MadUploadRules::writePrint($props);
        if ($print === null || isset($this->_known[self::DECLARED]['w'][$name][$print])) {
            return true;
        }

        if ($log) {
            try {
                \Illuminate\Support\Facades\Log::warning(sprintf(
                    '[MadForm] campo "%s": o formulário recebido descreve um campo que esta tela não desenhou assim'
                    . ' (onde grava: tabela, chave estrangeira, pasta ou limites); arquivos e ligações dele não foram gravados.',
                    $name,
                ));
            } catch (\Throwable) {
                // Sem log configurado: a recusa vale do mesmo jeito.
            }
        }

        return false;
    }

    /**
     * Confere os uploads desta requisição contra o que os campos declaram
     * (MadUploadRules::check) e recusa o Salvar, com o erro no campo.
     *
     * @param array<string,mixed> $schema campos do formulário recebido
     * @param object|null         $record o registro que está sendo gravado
     */
    private function _checkUploads(array $schema, ?object $record = null): void
    {
        $drawn = [];
        foreach ($schema as $fieldName => $props) {
            if (is_array($props) && $this->_writesAsDrawn((string) $fieldName, $props, false)) {
                $drawn[$fieldName] = $props;
            }
        }
        // Imagem guardada na própria coluna: valem os limites que a TELA
        // declarou (estado cifrado), e não o que o formulário recebido diz do
        // campo — nem a falta dele.
        foreach ((array) ($this->_known[self::DECLARED]['i'] ?? []) as $fieldName => $limits) {
            $drawn[(string) $fieldName] = [
                'type'     => 'image',
                'accept'   => (string) ($limits['a'] ?? ''),
                'maxBytes' => (int) ($limits['b'] ?? 0),
            ];
        }

        // A imagem que a coluna JÁ tem não é conferida de novo: o campo a manda
        // de volta, inteira, em todo Salvar, e ela pode ser de antes de o limite
        // existir — salvar sem mexer na foto não pode ser recusado por ela.
        $values = $this->fields;
        if ($record instanceof \Illuminate\Database\Eloquent\Model && $record->exists) {
            foreach ($drawn as $fieldName => $props) {
                $posted = $values[$fieldName] ?? null;
                if (is_string($posted) && $posted !== '' && (string) ($props['type'] ?? '') === 'image'
                    && (string) ($props['storage'] ?? '') === '' && $posted === $record->getRawOriginal((string) $fieldName)) {
                    unset($values[$fieldName]);
                }
            }
        }

        $errors = MadUploadRules::check($drawn, $values);
        if ($errors !== []) {
            throw new MadValidationException($errors, array_keys($errors), '', $this->_knownFieldNames($this->fields));
        }
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

                // O formulário recebido diz ONDE o campo grava (tabela, chave
                // estrangeira, pasta) — e é o navegador quem o devolve. Só
                // vale o campo que ESTA tela desenhou, do mesmo jeito.
                if (!$this->_writesAsDrawn((string) $fieldName, (array) $props)) {
                    continue;
                }

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
     * Chaves de uma seleção múltipla como o campo as entrega: array, JSON do
     * MadWire (`'["5","4"]'`) ou lista separada (`'5,4'`). Sem vazios nem
     * repetidos, sempre como texto.
     *
     * @return list<string>
     */
    public static function selectionKeys(mixed $value, string $separator = ','): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if (str_starts_with($value, '[')) {
                $value = json_decode($value, true) ?: [];
            } else {
                $value = $value !== '' ? explode($separator !== '' ? $separator : ',', $value) : [];
            }
        }

        $keys = [];
        foreach ((array) $value as $key) {
            if (!is_scalar($key)) {
                continue;
            }
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * Sincroniza a tabela de ligação (pivô) de um campo de seleção múltipla em
     * mode=table — por DIFERENÇA, ligação a ligação:
     *
     *   - sai a ligação que este formulário entregou marcada (`$_known`) e que
     *     não voltou no POST: a que a tela mostrou e o usuário desmarcou;
     *   - entra a marca que ainda não é ligação;
     *   - o resto fica como está — a linha, a chave dela e as colunas que a
     *     tela não conhece (nível, data, ordem, unidade, auditoria).
     *
     * Antes era "apaga todas as ligações do pai e insere o que veio": toda
     * gravação trocava a chave das ligações e zerava as colunas extras, e
     * bastava o campo abrir sem as marcas (leitura que falhou, opções que não
     * carregaram, fonte filtrada) para o Salvar apagar o que ninguém desmarcou.
     *
     * A remoção passa pelo Model, uma a uma: exclusão lógica, `deleting`/
     * `deleted` e a recusa do Model são respeitados. A marca cuja ligação está
     * excluída logicamente é devolvida (`restore()`), não inserida de novo — a
     * linha continua ocupando o índice único (pai + item).
     *
     * Marca que este formulário entregou e que não é mais ligação foi
     * desmarcada por outra aba (ou outra pessoa) depois: não é recriada, e o
     * usuário é avisado.
     *
     * A marca que vai virar ligação NOVA passa antes pelas regras de
     * referência do Model da ligação (ReferenceGuard): o campo só lista os
     * itens que quem salva enxerga, e a requisição alterada ligava o registro
     * a um item de outra unidade. A ligação que já existe não é conferida de
     * novo. Uma marca recusada recusa o Salvar, antes de qualquer escrita.
     * A mesma marca passa também pela consulta do Model das opções do campo
     * (_guardSelectionSource), que vale mesmo quando o Model da ligação não
     * tem `rules()`.
     *
     * @throws ReferenceViolation marca de um item que quem salva não enxerga
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

        // O navegador não mandou o campo e o código da tela não o preencheu:
        // não há escolha de ninguém para gravar. (O MadWire manda o grupo
        // sempre, inclusive vazio — `'[]'` é "nada marcado".)
        if (!array_key_exists($fieldName, $this->fields) && !array_key_exists($fieldName, $_POST)) {
            return;
        }

        // IDs selecionados (do MadWire via $this->fields, ou fallback $_POST)
        $posted = self::selectionKeys($this->fields[$fieldName] ?? $_POST[$fieldName] ?? []);

        // Nome REAL da classe: `pivot-model="TecnicoEspecialidade"` chega como
        // nome curto, e a leitura do render resolve do mesmo jeito.
        $cls = $pivotModel;
        try {
            $resolved = \Mad\Form\ModelOptionsLoader::resolveModelClass((string) $pivotModel);
            if ($resolved && class_exists($resolved)) {
                $cls = $resolved;
            }
        } catch (\Throwable $e) {}

        // O que este formulário entregou marcado para ESTE pai (null = nada:
        // registro novo, tela aberta antes desta versão, estado de outro registro).
        $known       = $this->_knownFor($fieldName, $parentId);
        $unconfirmed = $known !== null ? (array) ($this->_known[$fieldName]['u'] ?? []) : [];

        // Ligações deste pai, por item. A consulta passa pelo Model: os escopos
        // dele (exclusão lógica, unidade) valem aqui como valem na leitura.
        $alive = [];
        $links = $cls::query()->where($foreignKey, '=', $parentId)->get();
        foreach ($links as $link) {
            $alive[(string) $link->$itemKey][] = $link;
        }

        // A ligação tem chave PRÓPRIA? A tabela de ligação clássica (pai + item,
        // sem `id`) não tem: o `delete()` da instância procuraria uma coluna que
        // não existe — e, com a chave do Model apontada para o pai ou para o
        // item, apagaria ligações de OUTROS registros. Sem chave própria a
        // ligação sai pela dupla (pai, item), por consulta, como sempre saiu.
        $probe   = new $cls();
        $keyName = (string) ($probe->getKeyName() ?? '');
        $keyed   = $keyName !== '' && $keyName !== $foreignKey && $keyName !== $itemKey
            && $this->_detailHasKeyColumn($probe);
        if ($keyed) {
            $seen = [];
            foreach ($links as $link) {
                $key = $link->getKey();
                if (!is_scalar($key) || (string) $key === '' || isset($seen[(string) $key])) {
                    $keyed = false;   // chave vazia ou repetida: não identifica a ligação
                    break;
                }
                $seen[(string) $key] = true;
            }
        }

        // ── 0) As marcas que vão virar ligação: o item é de quem salva? ──────
        $entering = [];
        foreach ($posted as $itemId) {
            // (a que outra tela desmarcou não volta a ser ligação — ver o passo 2)
            $unmarked = $known !== null && array_key_exists($itemId, $known) && !isset($unconfirmed[$itemId]);
            if (!isset($alive[$itemId]) && !$unmarked) {
                $entering[] = $itemId;
            }
        }
        $refused = self::linkReferenceError($cls, (string) $foreignKey, $parentId, (string) $itemKey, $entering, trim((string) ($props['label'] ?? '')));
        if ($refused !== null) {
            ReferenceGuard::logRefused(sprintf('uma marca do campo "%s"', $fieldName));

            throw new ReferenceViolation([$fieldName => $refused], [$fieldName]);
        }
        // E pela consulta do Model das OPÇÕES do campo, quando a tela a declarou:
        // é o que barra a marca quando o Model da ligação não tem `rules()`.
        $this->_guardSelectionSource($fieldName, $entering);
        // Passou: a mensagem que um Salvar recusado deixou no campo sai da tela.
        if (isset(ReferenceGuard::columns($cls)[(string) $itemKey])) {
            $this->_passedNow[$fieldName] = true;
        }

        // ── 1) Desmarcadas: o que a tela entregou marcado e não voltou ───────
        // Sem base (`$known` null) não há o que desmarcar: "nada marcado" numa
        // tela que não mostrou as marcas não é pedido de ninguém.
        $postedSet = array_fill_keys($posted, true);
        foreach (array_keys($known ?? []) as $itemId) {
            $itemId = (string) $itemId;
            if (isset($postedSet[$itemId]) || empty($alive[$itemId])) {
                continue;
            }
            if (!$keyed) {
                $cls::query()->where($foreignKey, '=', $parentId)->where($itemKey, '=', $itemId)->delete();
                continue;
            }
            // Pelo Model, uma a uma: exclusão lógica, eventos e recusa.
            foreach ($alive[$itemId] as $link) {
                $link->delete();
            }
        }

        // ── 2) Marcadas: fica a que já é ligação, entra a que ainda não é ────
        $now     = [];   // o que a tela "tem" depois deste Salvar
        $pending = [];   // ligações criadas agora (ou ainda não confirmadas)
        $gone    = 0;    // marcas que outra tela já tinha desmarcado
        foreach ($posted as $itemId) {
            $now[$itemId] = '';

            if (isset($alive[$itemId])) {
                if (isset($unconfirmed[$itemId])) {
                    $pending[] = $itemId;
                }
                continue;
            }

            // A tela (desatualizada) ainda mostra marcado o que outra aba, ou
            // outra pessoa, já desmarcou: quem desmarcou por último decidiu. A
            // ligação que nasceu num Salvar desfeito nunca existiu — essa entra.
            if ($known !== null && array_key_exists($itemId, $known) && !isset($unconfirmed[$itemId])) {
                $gone++;
                continue;
            }

            $this->_linkPivotItem($cls, $foreignKey, $parentId, $itemKey, $itemId, $keyed);
            $pending[] = $itemId;
        }

        if ($gone > 0) {
            $label = trim((string) ($props['label'] ?? ''));
            $this->warnChildRowsGone([], [], [$label !== '' ? $label : $fieldName => $gone]);
        }

        // A tela não é redesenhada depois do Salvar: ela continua mostrando o
        // que mandou — inclusive o que outra tela desmarcou, que seguirá vindo
        // a cada Salvar até ela ser recarregada.
        $this->_remember($fieldName, $parentId, $now, $record, $pending);
    }

    /**
     * As regras de referência do Model de uma tabela de ligação aceitam ligar
     * o pai a cada um destes itens? Devolve a mensagem do primeiro recusado
     * (com o rótulo do campo, quando a tela o tem), ou null.
     *
     * Só os itens que vão virar ligação NOVA devem vir aqui: a ligação que já
     * existe fica como está, mesmo apontando para um item que quem salva não
     * enxerga.
     *
     * @internal usado também por MadChecklistTrait::saveChecklist()
     *
     * @param list<int|string> $items itens (valor de `$itemKey`) a ligar
     */
    public static function linkReferenceError(string $cls, string $foreignKey, mixed $parentId, string $itemKey, array $items, string $label = ''): ?string
    {
        $guarded = $items !== [] ? ReferenceGuard::columns($cls) : [];
        if (!isset($guarded[$itemKey])) {
            return null;
        }
        // Sem rótulo da tela vale o da regra (`'coluna|Rótulo'`); sem nenhum, um
        // texto que não cita a coluna.
        $label = $label !== '' ? $label : $guarded[$itemKey];
        foreach ($items as $itemId) {
            $errors = ReferenceGuard::check($cls, null, [$itemKey => $itemId], [$itemKey => $label], [$foreignKey => $parentId]);
            if ($errors !== []) {
                return $label !== ''
                    ? (string) reset($errors)
                    : self::_text('mad.form.selection_invalid', [], 'Um dos itens marcados não pode ser gravado: ele não está na sua lista ou não existe mais.');
            }
        }

        return null;
    }

    /**
     * Liga um item ao pai na tabela de ligação. Com exclusão lógica, a ligação
     * desmarcada antes continua na tabela (e no índice único pai + item):
     * marcar de novo é devolvê-la, não inserir outra.
     */
    private function _linkPivotItem(string $cls, string $foreignKey, mixed $parentId, string $itemKey, string $itemId, bool $keyed = true): void
    {
        $probe = new $cls();

        // Só com a exclusão lógica LIGADA no Model (HasMadSoftDeletes sem a
        // coluna configurada apaga de vez) e com chave própria — o `restore()`
        // é da instância (ver _processPivotTable).
        $softDeletes = $keyed
            && method_exists($probe, 'restore')
            && method_exists($probe, 'trashed')
            && method_exists($probe, 'getDeletedAtColumn')
            && $probe->getDeletedAtColumn();
        if ($softDeletes) {
            $trashed = $cls::onlyTrashed()
                ->where($foreignKey, '=', $parentId)
                ->where($itemKey, '=', $itemId)
                ->first();
            if ($trashed && $trashed->trashed()) {
                $trashed->restore();

                return;
            }
        }

        $probe->$foreignKey = $parentId;
        $probe->$itemKey    = $itemId;
        $probe->save();
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

        // ── mode=comma: a lista de caminhos mora na coluna do próprio registro ──
        //
        // Parte do que está na coluna AGORA (o registro lido neste Salvar; o
        // fillRecord() não a regrava): sai o arquivo que o campo MOSTROU
        // (`$_known`, gravado pelo Blade) e que não voltou entre os mantidos,
        // entram os novos, e o resto fica onde está. Antes a coluna recebia
        // "mantidos + novos" desta tela: a aba que ficou aberta tirava da
        // coluna o arquivo que outra aba tinha anexado (órfão no disco) e
        // devolvia o caminho do arquivo que outra aba tinha removido.
        if ($mode === 'comma' && $storage === 'disk') {
            $pk       = method_exists($record, 'getKeyName') ? $record->getKeyName() : 'id';
            $parentId = $record->$pk ?? null;

            // Paths existentes que o usuário MANTEVE (enviados via hidden inputs)
            $kept = $_POST['__mad_existing_files'][$fieldName] ?? [];
            if (is_string($kept)) {
                $kept = [$kept];
            }
            $kept    = array_values(array_filter(array_map('strval', array_filter((array) $kept, 'is_scalar')), static fn ($v) => $v !== ''));
            $keptSet = array_fill_keys($kept, true);

            // O que está na coluna agora, na ordem em que está.
            $rawValue = (string) ($record->$fieldName ?? '');
            $current  = [];
            foreach (explode(',', $rawValue) as $path) {
                $path = trim($path);
                if ($path !== '' && !in_array($path, $current, true)) {
                    $current[] = $path;
                }
            }
            $currentSet = array_fill_keys($current, true);

            // O que o campo mostrou. Sem esse registro (tela aberta antes desta
            // versão): só reconcilia se o POST prova que o campo tinha a lista
            // — algum caminho mantido está na coluna.
            $shown = $this->_knownFor($fieldName, $parentId);
            if ($shown === null && array_intersect($current, $kept)) {
                $shown = $currentSet;
            }
            $shown ??= [];

            $final     = [];
            $keptShown = [];   // mantidos que o campo de fato mostrou e que estão na coluna
            foreach ($current as $path) {
                if (array_key_exists($path, $shown) && !isset($keptSet[$path])) {
                    // Mostrado e removido pelo usuário. O arquivo sai do disco
                    // quando a transação confirmar: se o Salvar falhar depois, a
                    // coluna volta a listá-lo — e ele tem de estar lá.
                    $this->_discardFile($path, $record);
                    continue;
                }
                $final[] = $path;
                if (array_key_exists($path, $shown)) {
                    $keptShown[] = $path;
                }
            }

            // Arquivo que o campo (desatualizado) ainda mostra e que outra tela
            // já removeu: não volta para a coluna — o arquivo não existe mais.
            // O usuário é avisado; daqui em diante o campo não o "tem". (O
            // caminho que o campo nunca mostrou não entra na coluna: o
            // navegador só mantém o que o servidor entregou.)
            $goneFiles = [];
            foreach ($kept as $path) {
                if (!isset($currentSet[$path]) && array_key_exists($path, $shown)) {
                    $goneFiles[] = self::_storedFileLabel($path);
                }
            }
            if ($goneFiles) {
                $this->warnChildRowsGone([], $goneFiles);
            }

            // Novos uploads
            $newPaths = [];
            $saved    = [];   // o que o campo recebe de volta (files_saved)
            $unique   = self::_uniqueFileNames($props['fileName'] ?? 'prefix');
            $files = $_FILES[$fieldName] ?? [];
            if (!empty($files['tmp_name']) && is_array($files['tmp_name'])) {
                $count = count($files['tmp_name']);
                $ids   = $this->_newFileIds($fieldName, $count);
                for ($i = 0; $i < $count; $i++) {
                    if (empty($files['tmp_name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    // _buildFileName ja sanitiza e bloqueia extensoes proibidas.
                    $fileName  = $this->_buildFileName($files['name'][$i], $props['fileName'] ?? 'prefix', $record);
                    $filePath  = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                    $this->_storeFile($files['tmp_name'][$i], $filePath, $record, $unique);
                    $newPaths[] = $filePath;
                    if (!in_array($filePath, $final, true)) {
                        $final[] = $filePath;
                    }

                    $label   = self::_storedFileLabel($filePath);
                    $saved[] = ['uid' => $ids[$i] ?? '', 'key' => $filePath, 'name' => $label, 'url' => self::_storedFileUrl($filePath, $label)];
                }
            }

            // Nada mudou: a coluna não é regravada (fica com o texto que tinha).
            if ($final !== $current) {
                $record->$fieldName = !empty($final) ? implode(',', $final) : null;
                $record->save();
            }

            // O campo passa a "ter" os mantidos e os recém-gravados: a tela não
            // é redesenhada depois do Salvar, e é a resposta que conta ao campo
            // que eles já estão gravados (files_saved). Sem isto ele os mandava
            // de novo no Salvar seguinte.
            $this->_remember($fieldName, $parentId, array_fill_keys(array_merge($keptShown, $newPaths), ''), $record);
            $this->_announceSavedFiles($fieldName, $saved, $record);

            // O valor que o formulário guarda para o campo passa a ser o que a
            // tela tem (só quando a transação confirmar): é dele que o campo se
            // desenha quando a tela é redesenhada inteira — sem isto o arquivo
            // recém-gravado sumia do campo redesenhado.
            $has    = array_fill_keys(array_merge($keptShown, $newPaths), true);
            $screen = implode(',', array_values(array_filter($final, static fn (string $path): bool => isset($has[$path]))));
            $before = $this->fields[$fieldName] ?? '';
            if (!is_scalar($before) || (string) $before !== $screen) {
                $this->_afterCommit($record, function () use ($fieldName, $screen): void {
                    $this->fields[$fieldName] = $screen !== '' ? $screen : null;
                });
            }

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

            $pk       = $record->getKeyName();
            $parentId = $record->$pk;

            // Reconciliação na edição (disk): mantém os arquivos existentes
            // enviados em __mad_existing_files e deleta (linha + arquivo no disco)
            // os que o usuário removeu. Sem isto, a edição nunca apagava anexos
            // antigos e re-uploads duplicavam.
            //
            // "Removeu" = o campo MOSTROU o anexo ($_known, gravado pelo Blade ao
            // carregar a tabela filha) e ele não voltou entre os mantidos. Antes
            // era "toda linha do pai cujo caminho não veio": com o campo aberto
            // sem os anexos (leitura que falhou, registro errado no estado) o
            // Salvar apagava todos — linha E arquivo —, e uma Lista de itens /
            // Detail Form na mesma tabela perdia as linhas que só ela conhecia.
            $newPaths  = [];
            $keptShown = [];   // mantidos que o campo de fato mostrou
            if ($storage === 'disk') {
                $kept = $_POST['__mad_existing_files'][$fieldName] ?? [];
                if (is_string($kept)) {
                    $kept = [$kept];
                }
                $kept = array_filter(array_map('strval', (array) $kept), fn ($v) => $v !== '');

                $shown    = $this->_knownFor($fieldName, $parentId);
                $children = $model::where($foreignKey, $parentId)->get();

                // Sem registro do que o campo mostrou (tela aberta antes desta
                // versão): só reconcilia se o POST prova que o campo tinha a
                // lista — algum caminho mantido é de uma linha deste pai.
                $proven = false;
                if ($shown === null) {
                    foreach ($children as $existingChild) {
                        if (in_array((string) ($existingChild->$pathColumn ?? ''), $kept, true)) {
                            $proven = true;
                            break;
                        }
                    }
                }

                $alive = [];
                foreach ($children as $existingChild) {
                    $cp = (string) ($existingChild->$pathColumn ?? '');
                    if ($cp === '') {
                        continue;   // sem arquivo: não é linha deste campo
                    }
                    $alive[$cp] = true;
                    if ($shown !== null ? !array_key_exists($cp, $shown) : !$proven) {
                        continue;   // o campo nunca mostrou esta linha
                    }
                    if (in_array($cp, $kept, true)) {
                        $keptShown[] = $cp;
                        continue;
                    }
                    // Linha primeiro: o Model que recusa a exclusão fica com o arquivo.
                    if ($existingChild->delete() === false) {
                        continue;
                    }
                    // O arquivo sai do disco quando a transação confirmar: se o
                    // Salvar falhar depois, a linha volta — com o arquivo dela.
                    $this->_discardFile($cp, $existingChild);
                    $this->_rowTouched('gone', $existingChild, $foreignKey, $parentId);
                }

                // Anexo que o campo (desatualizado) ainda mostra e que outra tela
                // já removeu: não há o que regravar — o arquivo não existe mais.
                // O usuário é avisado; daqui em diante o campo não o "tem".
                $goneFiles = [];
                foreach ($kept as $cp) {
                    if ($shown !== null && array_key_exists($cp, $shown) && !isset($alive[$cp])) {
                        $bn = basename($cp);
                        $goneFiles[] = preg_match('/^[a-f0-9]{13,16}_(.+)$/i', $bn, $m) ? $m[1] : $bn;
                    }
                }
                if ($goneFiles) {
                    $this->warnChildRowsGone([], $goneFiles);
                }
            }

            $files  = $_FILES[$fieldName] ?? [];
            $count  = (!empty($files['tmp_name']) && is_array($files['tmp_name'])) ? count($files['tmp_name']) : 0;
            $ids    = $this->_newFileIds($fieldName, $count);
            $saved  = [];   // o que o campo recebe de volta (files_saved)
            $unique = self::_uniqueFileNames($props['fileName'] ?? 'prefix');
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['tmp_name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $originalName = $files['name'][$i];
                // Sanitiza upfront — usado tanto no fs quanto no nameColumn.
                $cleanName    = $this->_sanitizeUploadName($originalName);

                $child = new $model();
                $child->$foreignKey = $parentId;

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
                    $this->_storeFile($files['tmp_name'][$i], $filePath, $child, $unique);
                    $child->$pathColumn = $filePath;
                    $child->save();
                    $newPaths[] = $filePath;

                    $label   = $nameColumn ? $cleanName : self::_storedFileLabel($filePath);
                    $saved[] = ['uid' => $ids[$i] ?? '', 'key' => $filePath, 'name' => $label, 'url' => self::_storedFileUrl($filePath, $label)];
                } elseif ($storage === 'db') {
                    $child->save();
                    $content = base64_encode(file_get_contents($files['tmp_name'][$i]));
                    $this->_saveBlobToDb($child, $pathColumn, $content);

                    // No banco não há caminho: o campo recebe a chave da linha.
                    $saved[] = ['uid' => $ids[$i] ?? '', 'key' => 'id:' . $child->getKey(), 'name' => $cleanName, 'url' => ''];
                }

                // Uma Lista de itens / Detail Form sobre a mesma tabela é gravada
                // logo depois (_autoSaveDetails): esta linha não "faltou" no
                // formulário dela — acabou de nascer.
                $this->_rowTouched('created', $child, $foreignKey, $parentId);
            }

            // O campo passa a "ter" os anexos mantidos e os recém-gravados: a tela
            // não é redesenhada depois do Salvar, e é a resposta que conta ao
            // campo que eles já estão gravados (files_saved). Daí em diante ele
            // os manda entre os mantidos — não de novo como arquivo novo, o que
            // fazia cada Salvar apagar a linha do envio anterior e criar outra.
            if ($storage === 'disk') {
                $this->_remember($fieldName, $parentId, array_fill_keys(array_merge($keptShown, $newPaths), ''), $record);
            }
            $this->_announceSavedFiles($fieldName, $saved, $record);
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

        // Campo com regra é campo que o código da tela conhece (a confirmação
        // da senha, o aceite): não é valor perdido quando não é coluna.
        foreach ($parsedRules as $field => $_) {
            $this->_usedNow[(string) $field] = true;
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
        $this->_usedNow[$field] = true;
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

        // Valor atribuído pelo código: é gravado, mesmo igual ao que veio do
        // registro e mesmo sem campo na tela (ver _untouchedLoaded). Menos o
        // campo do editor de um detail-form em escopo: ele é da linha, e um
        // homônimo do registro principal não foi tocado.
        if ($this->_detailScope === null || !array_key_exists($field, $this->_detailScopeInput)) {
            unset($this->_known[self::LOADED]['k'][$field]);
            $this->_assignedNow[$field] = true;
        }
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
            $keys     = [];
            $read     = [];   // chave => atributos como estão no banco (ver noteDetailColumns)

            foreach ($objects as $obj) {
                $row = method_exists($obj, 'toArray') ? $obj->toArray() : (array) $obj;
                // A chave vem do REGISTRO, não da linha: um Model com a chave em
                // `$hidden` não a tem no toArray(), e a linha continua sendo dele.
                $rowKey = method_exists($obj, 'getKey') ? $obj->getKey() : ($row[$order] ?? null);
                $keys[] = $rowKey;
                if ($rowKey !== null && $rowKey !== '' && $obj instanceof \Illuminate\Database\Eloquent\Model) {
                    $read[$rowKey] = $obj->attributesToArray();
                }
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

            // O que esta tela está entregando ao navegador (chave => __id da
            // linha): é contra isto que o Salvar decide o que foi removido.
            $loaded = [];
            foreach ($normalized as $i => $row) {
                $key = $keys[$i] ?? null;
                if ($key !== null && $key !== '') {
                    $loaded[$key] = (string) ($row['__id'] ?? '');
                }
            }
            $this->_remember($name, $parentId, $loaded);
            $this->_loadedRows[$name] = ['p' => (string) $parentId, 'rows' => $read];
            unset(self::$detailLoadFailed[$name]);

            return $normalized;

        } catch (\Throwable $e) {
            // ⚠️ Este [] vazio NÃO é inofensivo: ele era o precursor de perda de
            // dados. O detail renderiza sem linhas, o usuário salva achando que
            // está tudo lá, e o reconcile do submit seguinte apagava "o que não
            // veio" — TODOS os filhos reais do registro. Model irresolúvel,
            // conexão fora do ar ou FK renomeada bastam. Devolver vazio segue
            // sendo o comportamento (lançar aqui quebraria o render inteiro do
            // form), mas silenciar era o que tornava o apagamento impossível de
            // rastrear depois.
            error_log('[MadForm::autoLoadDetailRows] falha ao carregar detail "'
                . $name . '" (model=' . $model . ', fk=' . $foreignKey
                . ', parent=' . $parentId . ') — detail renderiza VAZIO; o save '
                . 'subsequente NAO vai apagar linhas deste detail: ' . $e->getMessage());

            // Sem a lista real dos filhos, "nenhuma linha no form" é
            // indistinguível de "não consegui ler", e apagar no escuro destrói
            // dado do usuário. O que protege é o formulário NÃO guardar linha
            // nenhuma como carregada: o Salvar chega em OUTRA requisição, e só
            // o estado cifrado da tela atravessa de uma para a outra — o
            // reconcile apaga apenas o que consta lá (ver $_known).
            unset($this->_known[$name]);

            // Marca de diagnóstico (API pública, consultada pelos testes). Não
            // decide nada no Salvar: é estática do processo e, num worker de
            // longa duração, sobreviveria à requisição que falhou.
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
            // O token `__mad_form` diz quais detalhes gravam sozinhos — e é o
            // navegador quem o devolve. Só vale o detalhe que ESTA tela
            // desenhou, com o mesmo Model e a mesma chave estrangeira: o token
            // de outra tela gravaria linhas de outra tabela sob este registro.
            if ($this->declares()) {
                $declared = $this->_known[self::DECLARED]['d'][$name] ?? null;
                if (!is_array($declared)
                    || (string) ($declared['m'] ?? '') !== (string) ($meta['model'] ?? '')
                    || (string) ($declared['f'] ?? '') !== (string) ($meta['foreignKey'] ?? '')) {
                    $this->_refused['[' . $name . ']'] = 'o formulário recebido descreve um detalhe que esta tela não desenhou assim; as linhas não foram gravadas';
                    continue;
                }
            }

            // getFieldList() funciona para ambos (field-list e detail-form):
            // ambos armazenam rows em $this->fields[$name]
            $rows     = $this->getFieldList($name);
            $hook     = $this->_detailSaveHooks[$name] ?? null;
            $fileCols = MadFormRegistry::getDetailFileColumns($name);

            // As colunas de arquivo das linhas (pasta, coluna do nome, tabela
            // dos arquivos) também vêm do `__mad_form`. Com as de outra tela —
            // ou sem as desta — o arquivo ia para outra pasta ou outra tabela,
            // o nome dele para outra coluna, e o caminho que a linha traz era
            // gravado como texto. Só vale o que ESTA tela desenhou. (Detalhe sem
            // a anotação: tela aberta antes desta versão, grava como sempre.)
            $declared = $this->_known[self::DECLARED]['d'][$name] ?? null;
            if (is_array($declared['fp'] ?? null) && !isset($declared['fp'][MadUploadRules::detailFilesPrint($fileCols)])) {
                $this->_refused['[' . $name . ']'] = 'o formulário recebido descreve as colunas de arquivo deste detalhe de um jeito que esta tela não desenhou'
                    . ' (armazenamento, pasta, coluna do nome ou tabela dos arquivos); as linhas não foram gravadas';
                continue;
            }

            $this->_saveDetailRows($meta['model'], $meta['foreignKey'], $record, $rows, $hook, $name, $fileCols);
        }
    }

    /**
     * Details cujo carregamento FALHOU neste processo (nome => true).
     *
     * Só diagnóstico. Já foi a trava do reconcile, mas não protegia: a marca
     * nasce no render e o Salvar chega em OUTRA requisição (em que a ação roda
     * antes do render), e num worker de longa duração ela sobrevivia à
     * requisição que falhou. Quem decide o que o Salvar pode apagar é `$_known`.
     *
     * @var array<string,bool>
     */
    private static array $detailLoadFailed = [];

    /** A última carga deste detail falhou neste processo? */
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

    // ── O que este formulário entregou ao navegador ($_known) ────────────────

    /**
     * Chaves que este formulário entregou para `$name` SOB este pai — ou null
     * quando ele não carregou nada para esse pai (leitura que falhou, tela
     * aberta sem as linhas, estado que aponta para outro registro).
     *
     * @return array<int|string,string>|null chave => __id da linha
     */
    private function _knownFor(string $name, mixed $parentId): ?array
    {
        $entry = $name !== '' ? ($this->_known[$name] ?? null) : null;
        if (!is_array($entry) || !is_array($entry['k'] ?? null)) {
            return null;
        }
        if ($parentId === null || (string) ($entry['p'] ?? '') !== (string) $parentId) {
            return null;
        }

        return $entry['k'];
    }

    /**
     * Guarda o que foi entregue (ou acabou de ser gravado) para `$name` sob
     * este pai.
     *
     * Com `$record`, o registro vale enquanto a transação em curso valer: se
     * ela for desfeita, volta ao que era. As linhas que este Salvar "apagou"
     * continuam no banco, e a tentativa seguinte precisa lembrar que o usuário
     * as removeu.
     *
     * `$pending` são as chaves que este Salvar CRIOU. Ficam marcadas (`u`) até a
     * transação mais externa confirmar: só de uma linha confirmada dá para
     * dizer, quando ela some, que alguém a removeu. A que nasceu num Salvar
     * desfeito por uma transação de fora (onde o desfazer acima não alcança)
     * nunca existiu — e é criada na tentativa seguinte, não descartada.
     *
     * `$quiet` são linhas que o próprio usuário removeu nesta tela por outro
     * componente (o anexo tirado no Upload, numa tabela que o detalhe também
     * mostra): a lista continua mandando a linha até a tela ser recarregada, e
     * ela é ignorada sem aviso — ele sabe que a removeu.
     *
     * @param array<int|string,string> $keys    chave => __id da linha
     * @param list<int|string>         $pending chaves criadas agora
     * @param list<int|string>         $quiet   chaves removidas aqui, por outro componente
     */
    private function _remember(string $name, mixed $parentId, array $keys, ?object $record = null, array $pending = [], array $quiet = []): void
    {
        if ($name === '' || $parentId === null || $parentId === '') {
            return;
        }

        $had    = array_key_exists($name, $this->_known);
        $before = $this->_known[$name] ?? null;

        $this->_known[$name] = ['p' => (string) $parentId, 'k' => $keys];
        // Colunas fora da grade (noteDetailColumns): a linha que continua na
        // tela continua com as mesmas — o navegador não as recebe de novo.
        if (is_array($before['h'] ?? null) && (string) ($before['p'] ?? '') === (string) $parentId) {
            $prints = array_intersect_key($before['h'], $keys);
            if ($prints) {
                $this->_known[$name]['h'] = $prints;
                // As colunas a que as impressões se referem vão junto.
                if (is_array($before['hc'] ?? null)) {
                    $this->_known[$name]['hc'] = $before['hc'];
                }
            }
        }
        if ($pending) {
            $this->_known[$name]['u'] = array_fill_keys($pending, 1);
        }
        if ($quiet) {
            $this->_known[$name]['g'] = array_fill_keys($quiet, 1);
        }

        $confirm = function () use ($name, $pending): void {
            foreach ($pending as $key) {
                unset($this->_known[$name]['u'][$key]);
            }
            if (isset($this->_known[$name]) && empty($this->_known[$name]['u'])) {
                unset($this->_known[$name]['u']);
            }
        };

        if ($record === null || !method_exists($record, 'getConnection')) {
            $confirm();

            return;
        }
        try {
            $connection = $record->getConnection();
            if (method_exists($connection, 'afterRollBack')) {
                $connection->afterRollBack(function () use ($name, $had, $before): void {
                    if ($had) {
                        $this->_known[$name] = $before;
                    } else {
                        unset($this->_known[$name]);
                    }
                });
            }
            // Sem transação aberta o callback roda na hora.
            $connection->afterCommit($confirm);
        } catch (\Throwable $e) {
            // Sem gerenciador de transações: o que foi gravado está gravado.
            $confirm();
        }
    }

    /**
     * Uma linha do POST que diz ser a linha `$pkVal` — e essa linha não existe
     * (mais) sob este pai. Ela já existiu e foi removida por outra tela?
     *
     *  - este formulário a entregou ou gravou (está em $known, confirmada): sim.
     *    Outra aba, ou outra pessoa, a removeu depois; recriá-la desfaria a
     *    exclusão de quem decidiu por último;
     *  - está em $known mas a transação que a criou não confirmou: não dá para
     *    dizer que existiu — é linha nova;
     *  - não está em $known e a chave é digitada na tela (não incremental e
     *    gravável): é linha nova com a chave que o usuário escolheu;
     *  - não está em $known e a chave é gerada pelo banco: se existe sob OUTRO
     *    pai, é o caso de sempre (vira linha nova daqui, nunca sequestra a
     *    alheia); se não existe em lugar nenhum — apagada ou excluída
     *    logicamente —, já foi removida.
     */
    private function _detailRowIsGone(string $cls, mixed $pkVal, ?array $known, array $unconfirmed, bool $canTypeKey): bool
    {
        if ($known !== null && array_key_exists($pkVal, $known)) {
            return !isset($unconfirmed[$pkVal]);
        }
        if ($canTypeKey) {
            return false;
        }

        return !$cls::query()->whereKey($pkVal)->exists();
    }

    /**
     * Registra linhas (posição na lista, a partir de 1), anexos (nome) e marcas
     * de um campo de seleção múltipla em outra tabela (rótulo do campo =>
     * quantas) que um Salvar deixou de regravar porque outra tela já os tinha
     * removido. A resposta da ação leva UM aviso com tudo (ver getPendingFlOps).
     *
     * @param list<int>         $rows
     * @param list<string>      $files
     * @param array<string,int> $options
     */
    public function warnChildRowsGone(array $rows = [], array $files = [], array $options = []): void
    {
        foreach ($rows as $position) {
            $this->_goneNotice['rows'][] = (int) $position;
        }
        foreach ($files as $file) {
            $this->_goneNotice['files'][] = (string) $file;
        }
        foreach ($options as $field => $count) {
            $field = (string) $field;
            $this->_goneNotice['options'][$field] = ($this->_goneNotice['options'][$field] ?? 0) + max(1, (int) $count);
        }
    }

    /** O aviso (diálogo) das linhas/anexos já removidos, ou null se não há o que avisar. */
    private function _goneNoticeOp(): ?array
    {
        $rows    = array_values(array_unique($this->_goneNotice['rows']));
        $files   = array_values(array_unique($this->_goneNotice['files']));
        $options = $this->_goneNotice['options'];
        if (!$rows && !$files && !$options) {
            return null;
        }
        sort($rows);

        $and  = ' ' . self::_text('mad.detail.and', [], 'e') . ' ';
        $list = static function (array $items) use ($and): string {
            $last = array_pop($items);

            return $items ? implode(', ', $items) . $and . $last : (string) $last;
        };

        $parts = [];
        if ($rows) {
            $parts[] = count($rows) === 1
                ? self::_text('mad.detail.gone_rows_one', ['rows' => $list($rows)], 'A linha :rows da lista já tinha sido removida em outra aba ou por outra pessoa e não foi gravada de novo.')
                : self::_text('mad.detail.gone_rows_many', ['rows' => $list($rows)], 'As linhas :rows da lista já tinham sido removidas em outra aba ou por outra pessoa e não foram gravadas de novo.');
        }
        if ($files) {
            $parts[] = count($files) === 1
                ? self::_text('mad.detail.gone_files_one', ['files' => $list($files)], 'O anexo :files já tinha sido removido em outra aba ou por outra pessoa.')
                : self::_text('mad.detail.gone_files_many', ['files' => $list($files)], 'Os anexos :files já tinham sido removidos em outra aba ou por outra pessoa.');
        }
        foreach ($options as $field => $count) {
            $parts[] = $count === 1
                ? self::_text('mad.detail.gone_options_one', ['field' => $field], 'Em :field, uma opção que esta tela ainda mostrava marcada já tinha sido desmarcada em outra aba ou por outra pessoa e não foi marcada de novo.')
                : self::_text('mad.detail.gone_options_many', ['field' => $field, 'count' => $count], 'Em :field, :count opções que esta tela ainda mostrava marcadas já tinham sido desmarcadas em outra aba ou por outra pessoa e não foram marcadas de novo.');
        }
        $parts[] = self::_text('mad.detail.gone_tail', [], 'O restante foi salvo. Atualize a tela para ver a situação atual.');

        return [
            'op'      => 'alert',
            'message' => implode(' ', $parts),
            'type'    => 'warning',
            'title'   => self::_text('mad.detail.gone_title', [], 'Itens já removidos'),
        ];
    }

    /** Texto do catálogo, com o texto em português de reserva quando a chave não existe. */
    private static function _text(string $key, array $replace, string $fallback): string
    {
        try {
            $text = mad_t($key, $replace);
        } catch (\Throwable) {
            $text = '';
        }
        if ($text !== '' && $text !== $key) {
            return $text;
        }
        foreach ($replace as $k => $v) {
            $fallback = str_replace(':' . $k, (string) $v, $fallback);
        }

        return $fallback;
    }

    /**
     * Linhas que a tela mostra SEM ter passado pelo autoLoadDetailRows():
     * carregadas pelo código da tela (`:rows`, `$form->fields[...]`,
     * `loadDetailRows()`) num detail que declara model + foreign-key. Chamado
     * pelo Blade do detail.
     *
     * Só vale no render que carregou um registro (`fill($record)`), só para
     * as linhas DESTE pai, e só acrescenta: o redesenho depois de uma ação
     * recebe as linhas que o navegador mandou, e elas não podem encolher o que
     * o servidor já entregou.
     *
     * @internal chamado pelas views do framework (field-list / detail-form)
     */
    public function noteDetailRows(string $name, string $model, string $foreignKey, array $rows): void
    {
        $source = $this->_sourceRecord;
        if (!$source || $name === '' || $foreignKey === '' || !$rows) {
            return;
        }
        $parentPk = method_exists($source, 'getKeyName') ? $source->getKeyName() : 'id';
        $parentId = $source->$parentPk ?? null;
        if ($parentId === null || $parentId === '') {
            return;
        }

        try {
            $cls      = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $detailPk = (new $cls())->getKeyName();
        } catch (\Throwable $e) {
            return;
        }

        $known   = $this->_knownFor($name, $parentId);
        $keys    = $known ?? [];
        $changed = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $row[$detailPk] ?? null;
            if (!is_scalar($key) || $key === '' || array_key_exists($key, $keys)) {
                continue;
            }
            if (!array_key_exists($foreignKey, $row) || (string) $row[$foreignKey] !== (string) $parentId) {
                continue;
            }
            $keys[$key] = is_scalar($row['__id'] ?? null) ? (string) $row['__id'] : '';
            $changed    = true;
        }

        if (!$changed) {
            return;
        }
        if ($known !== null) {
            // Só acrescenta: as marcas do registro (`u`, `g`) ficam como estão.
            $this->_known[$name]['k'] = $keys;
        } else {
            $this->_remember($name, $parentId, $keys);
        }
    }

    /**
     * As colunas da linha que um detail NÃO mostra (nem na grade, nem no
     * editor), como estavam no banco quando a tela carregou as linhas: em
     * `$_known[$name]`, `hc` = essas colunas e `h` = por linha, a impressão
     * digital de cada uma (na ordem de `hc`).
     *
     * A linha vai inteira para o navegador e volta inteira no Salvar — com as
     * colunas que a tela não mostra no valor de quando ela abriu. Gravá-las de
     * volta desfazia o que outra tela, outra pessoa ou outro processo tivesse
     * mudado na linha nesse intervalo (a baixa do item, o custo, a situação).
     * Com a base, o Salvar grava as colunas que o detail tem e, das que ele
     * não mostra, só as que o código da tela (ou a própria tela) MUDOU — ver
     * _withoutUntouchedRowValues(). Coluna a coluna, como na lista gravada à
     * mão: com uma impressão só para a linha, mudar uma coluna fazia a linha
     * ser gravada inteira, e as outras colunas fora da grade voltavam ao valor
     * de quando a tela abriu.
     *
     * Só no render que carregou o registro (`fill($registro)`): no redesenho
     * depois de uma ação as linhas são as que o navegador mandou. A base é o
     * BANCO, não a linha entregue: o que o hook de carga ou o código da tela
     * pôs por cima de uma coluna é valor do código.
     *
     * @internal chamado pelas views do framework (field-list / detail-form),
     *           depois de registrarem as colunas do detail
     */
    public function noteDetailColumns(string $name, string $model, string $foreignKey, array $rows = []): void
    {
        $read = $this->_loadedRows[$name] ?? null;
        unset($this->_loadedRows[$name]);

        // Lista gravada à mão (a tag não diz model nem foreign-key): quem leu
        // as linhas foi o loadDetailRows() da tela, nesta requisição.
        if ($model === '' || $foreignKey === '') {
            $this->_noteHandColumns($name, $read ?? $this->_adoptHandLoad($name, $rows));

            return;
        }

        $source = $this->_sourceRecord;
        $entry  = $this->_known[$name] ?? null;
        if (!$source || $name === '' || !is_array($entry) || empty($entry['k']) || !is_array($entry['k'])) {
            return;
        }
        $parentId = (string) ($entry['p'] ?? '');
        if ($parentId === '' || !method_exists($source, 'getKey') || (string) $source->getKey() !== $parentId) {
            return;
        }

        $shown = MadFormRegistry::detailShownFields($name);
        if ($shown === null) {
            unset($this->_known[$name]['h'], $this->_known[$name]['hc']);

            return;
        }

        // Linhas postas no formulário pelo código da tela: lê como estão no banco.
        if ($read === null || $read['p'] !== $parentId) {
            $read = ['p' => $parentId, 'rows' => []];
            try {
                $cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                foreach ($cls::query()->where($foreignKey, '=', $source->getKey())->get() as $obj) {
                    $read['rows'][$obj->getKey()] = $obj->attributesToArray();
                }
            } catch (\Throwable $e) {
                unset($this->_known[$name]['h'], $this->_known[$name]['hc']);

                return;
            }
        }

        [$columns, $prints] = self::_columnBase(array_keys($entry['k']), (array) $read['rows'], $shown);
        if ($prints) {
            $this->_known[$name]['h']  = $prints;
            $this->_known[$name]['hc'] = $columns;
        } else {
            unset($this->_known[$name]['h'], $this->_known[$name]['hc']);
        }
    }

    /**
     * A base, coluna a coluna, das linhas `$keys` como foram lidas do banco
     * (`$rows`: chave => atributos): as colunas que a lista não mostra e, por
     * linha, a impressão digital de cada uma, na ordem das colunas.
     *
     * @param  list<int|string>                      $keys
     * @param  array<int|string,array<string,mixed>> $rows
     * @param  array<string,mixed>                   $shown colunas que a lista tem
     * @return array{0: list<string>, 1: array<int|string,string>} [colunas fora da grade, chave => impressões]
     */
    private static function _columnBase(array $keys, array $rows, array $shown): array
    {
        $columns = [];
        foreach ($keys as $key) {
            foreach (array_keys((array) ($rows[$key] ?? [])) as $column) {
                $column = (string) $column;
                if (!isset($shown[$column]) && !str_starts_with($column, '__')) {
                    $columns[$column] = true;
                }
            }
        }
        ksort($columns);
        $columns = array_map('strval', array_keys($columns));

        $prints = [];
        foreach ($keys as $key) {
            $read = $rows[$key] ?? null;
            if (!is_array($read)) {
                continue;
            }
            $print = '';
            foreach ($columns as $column) {
                $print .= array_key_exists($column, $read) ? self::_columnPrint($read[$column]) : self::NO_COLUMN_PRINT;
            }
            $prints[$key] = $print;
        }

        return [$columns, $prints];
    }

    // ── Linhas gravadas À MÃO: loadDetailRows() + saveDetailItems() ──────────
    //
    // A lista que grava sozinha (`model` + `foreign-key` na tag) tem o nome do
    // detalhe e a base das linhas no formulário. A gravada à mão não tinha:
    // o `saveDetailItems()` recebe só as linhas — inteiras, como foram para o
    // navegador — e atribuía todas as chaves, devolvendo ao banco o valor de
    // quando a tela abriu em toda coluna que a lista não mostra.
    //
    // Agora o `loadDetailRows()` entrega ao formulário o que leu (handRowsLoaded)
    // e o formulário guarda, no mesmo lugar da lista que grava sozinha
    // (`$_known[lista]`), a base das linhas: as chaves (`k`), de que Model e
    // chave estrangeira elas são (`w` — é por onde o `saveDetailItems()` acha a
    // lista sem precisar do nome) e, COLUNA A COLUNA, como estava no banco o
    // que a lista não mostra: `hc` = as colunas fora da grade, `h` = por linha,
    // a impressão digital de cada uma, na ordem de `hc`.
    //
    // Coluna a coluna, e não uma impressão só para a linha, porque quem grava
    // à mão costuma ENRIQUECER as linhas antes de salvar (põe o custo, a
    // situação). A chave que o código mudou é gravada — e só ela: com uma
    // impressão por linha, mexer numa coluna fazia a linha ser gravada inteira,
    // e as outras colunas fora da lista voltavam ao valor de quando a tela abriu.

    /** De que Model e chave estrangeira são as linhas de uma lista gravada à mão (vai em `$_known[lista]['w']`). */
    private static function _handRef(string $model, string $foreignKey): string
    {
        try {
            $resolved = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            if ($resolved && class_exists($resolved)) {
                $model = (new \ReflectionClass($resolved))->getName();
            }
        } catch (\Throwable $e) {
            // Model que não resolve: vale o nome como veio.
        }

        return self::_fingerprint(ltrim($model, '\\') . '|' . $foreignKey);
    }

    /**
     * O código da tela leu do banco, com `loadDetailRows()`, as linhas de uma
     * lista que ele mesmo vai gravar (`saveDetailItems()`).
     *
     * Sem o nome da lista (as linhas vão pela prop `:rows`), a carga espera a
     * lista que as desenha — ver _adoptHandLoad(). A base (chaves e impressões
     * digitais das colunas fora da grade) sai no render, quando a lista diz as
     * colunas que tem, e só vale quando as linhas chegam ao navegador — ver
     * _noteHandColumns().
     *
     * @internal chamado por MadFieldListTrait::loadDetailRows()
     *
     * @param array<int|string,string>              $ids  chave da linha => `__id` com que ela vai para o navegador
     * @param array<int|string,array<string,mixed>> $read chave da linha => atributos como estão no banco
     */
    public function handRowsLoaded(?string $name, string $model, string $foreignKey, mixed $parentId, string $keyName, array $ids, array $read): void
    {
        if ($parentId === null || $parentId === '' || $foreignKey === '') {
            return;
        }
        $load = [
            'p' => (string) $parentId, 'rows' => $read, 'hand' => true,
            'ids' => $ids, 'w' => self::_handRef($model, $foreignKey), 'pk' => $keyName,
        ];

        if ($name === null || $name === '') {
            $this->_handLoads[] = $load;
        } else {
            $this->_loadedRows[$name] = $load;
        }
    }

    /**
     * A lista `$name` (sem model na tag) está sendo desenhada com `$rows`: se
     * alguma carga do `loadDetailRows()` desta requisição ainda não tem lista e
     * estas são as linhas dela, é a carga desta lista.
     *
     * @param  list<mixed> $rows linhas que a lista desenha
     * @return array<string,mixed>|null a carga (formato de `$_loadedRows`, com `hand`)
     */
    private function _adoptHandLoad(string $name, array $rows): ?array
    {
        foreach ($this->_handLoads as $i => $load) {
            $mine = 0;
            foreach ($rows as $row) {
                $key = is_array($row) ? ($row[$load['pk']] ?? null) : null;
                if (!is_scalar($key) || $key === '') {
                    continue;   // linha nova: não diz de que carga é
                }
                if (!array_key_exists($key, $load['ids'])) {
                    continue 2; // linha de outra origem: não é esta carga
                }
                $mine++;
            }
            if ($mine === 0) {
                continue;
            }
            unset($this->_handLoads[$i]);
            $this->_handLoads = array_values($this->_handLoads);

            return $load;
        }

        return null;
    }

    /**
     * A base das linhas de uma lista gravada à mão, tirada do que o
     * `loadDetailRows()` leu nesta requisição. Fica à espera de as linhas
     * chegarem ao navegador (ver $_handBase): depois de uma ação o componente
     * é sempre redesenhado, mas a resposta costuma levar só as operações — e a
     * tela que continua mostrando as linhas de antes tem de continuar com a
     * base de antes.
     *
     * Sem carga nesta requisição não há o que anotar: a base que a tela já tem
     * fica como está.
     *
     * @param array<string,mixed>|null $load
     */
    private function _noteHandColumns(string $name, ?array $load): void
    {
        if ($name === '' || $load === null || empty($load['hand']) || empty($load['ids'])) {
            return;
        }

        // Sem as colunas da lista não há base: as linhas seguem sendo gravadas
        // inteiras (ficam só as chaves).
        $columns = [];   // colunas da linha que a lista não mostra
        $prints  = [];   // chave da linha => impressões, na ordem de $columns
        $shown   = MadFormRegistry::detailShownFields($name);
        if ($shown !== null) {
            [$columns, $prints] = self::_columnBase(array_keys($load['ids']), (array) $load['rows'], $shown);
        }

        $this->_handBase[$name] = [
            'p' => (string) $load['p'], 'k' => $load['ids'], 'h' => $prints,
            'hc' => $columns, 'w' => (string) $load['w'],
        ];
        if (isset($this->_rowsReplaced[$name])) {
            $this->_applyHandBase($name);
        }
    }

    /** As linhas da carga chegaram ao navegador: a base anotada no render passa a valer. */
    private function _applyHandBase(string $name): void
    {
        $base = $this->_handBase[$name] ?? null;
        unset($this->_handBase[$name]);
        if (!is_array($base)) {
            return;
        }

        $this->_remember($name, $base['p'], $base['k']);
        if (!isset($this->_known[$name])) {
            return;
        }
        // Carga nova: as impressões de antes eram de outra leitura do banco.
        unset($this->_known[$name]['h'], $this->_known[$name]['hc']);
        if ($base['h'] && $base['hc']) {
            $this->_known[$name]['h']  = $base['h'];
            $this->_known[$name]['hc'] = $base['hc'];
        }
        $this->_known[$name]['w'] = $base['w'];
    }

    /** Tamanho da impressão digital de UMA coluna na base das linhas gravadas à mão. */
    private const COLUMN_PRINT = 8;

    /**
     * No lugar da impressão, quando a linha não tem base para a coluna: a linha
     * lida do banco não a trouxe, ou o código da tela já mexeu nela (ver
     * _withoutUntouchedRowValues). Nunca é igual à impressão de um valor.
     */
    private const NO_COLUMN_PRINT = '--------';

    /**
     * Impressão digital do valor de UMA coluna, para dizer se o que a linha
     * traz é o que estava no banco ao carregar. Tirada do valor na forma
     * comparável (_comparable): o mesmo valor escrito de outro jeito tem a
     * mesma impressão.
     */
    private static function _columnPrint(mixed $value): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

        return substr(md5((string) json_encode(self::_comparable($value), $flags)), 0, self::COLUMN_PRINT);
    }

    /**
     * `$value` numa forma em que o MESMO valor é sempre igual, venha do banco
     * (`attributesToArray()`), do navegador (JSON) ou do código da tela:
     *
     *  - vazio e nulo são a mesma coisa (o Salvar grava `''` como NULL);
     *  - número é número: 10, 10.0 e '10'; 1.5 e '1.50'. Só o número escrito
     *    do jeito comum — '007' e '1e3' são texto (um código, não um valor);
     *  - verdadeiro/falso são 1/0;
     *  - data: com 'T' ou espaço, com ou sem segundos e fração zerada, e a data
     *    sozinha é a meia-noite dela ('2026-10-08' = '2026-10-08 00:00:00').
     *    Com fuso ('Z', '-03:00') é outro valor que o sem fuso;
     *  - o resto é comparado como texto, exato (espaço e maiúscula contam).
     *
     * Na dúvida, diferente: a coluna tida por mudada é gravada com o que o
     * código passou — o contrário faria o valor que ele atribuiu se perder.
     */
    private static function _comparable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif (is_object($value)) {
            $value = method_exists($value, '__toString')
                ? (string) $value
                : json_decode((string) json_encode($value), true);
        }

        if (is_array($value)) {
            return array_map([self::class, '_comparable'], $value);
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return (string) $value;
            }
            $value = sprintf('%.10F', $value);
        }
        $text = (string) $value;

        // Número escrito do jeito comum: sem zero à esquerda, sem expoente.
        if (preg_match('/^(-?)(0|[1-9]\d*)(?:\.(\d+))?$/', $text, $m)) {
            $fraction = rtrim($m[3] ?? '', '0');
            $number   = $m[2] . ($fraction !== '' ? '.' . $fraction : '');

            return ($m[1] === '-' && $number !== '0' ? '-' : '') . $number;
        }

        // Data, com ou sem hora.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?(Z|[+-]\d{2}:?\d{2})?$/i', $text, $m)) {
            $fraction = rtrim($m[5] ?? '', '0');

            return $m[1] . ' ' . (($m[2] ?? '') !== '' ? $m[2] : '00') . ':' . (($m[3] ?? '') !== '' ? $m[3] : '00')
                . ':' . (($m[4] ?? '') !== '' ? $m[4] : '00')
                . ($fraction !== '' ? '.' . $fraction : '')
                . strtoupper(str_replace(':', '', $m[6] ?? ''));
        }

        return $text;
    }

    /**
     * O que o `saveDetailItems()` atribui a uma linha que JÁ existe e tem base:
     * as colunas que a lista tem e, das que ela não mostra, só as que o código
     * MUDOU — a chave cujo valor na linha é outro que não o que estava no banco
     * ao carregar (ver _comparable) e a chave que a linha carregada nem tinha.
     * A coluna fora da lista que a linha traz igual à base não é atribuída: o
     * que está no banco agora pode ser de outra pessoa. É a mesma régua do
     * `$each`, que atribui só o que quer.
     *
     * Sem `$name`, a lista é a que o `loadDetailRows()` carregou com este
     * Model e esta chave estrangeira, sob este pai, e que entregou a linha.
     * Linha sem base (carregada de outro jeito, montada inteira pelo código,
     * tela aberta antes desta versão): `$assign` volta inteiro, como sempre foi.
     *
     * @internal chamado por MadFieldListTrait::saveDetailItems()
     *
     * @param  array<string,mixed> $assign colunas a atribuir (a linha como o código a passou, sem a chave e sem `__*`)
     * @return array<string,mixed>
     */
    public function handRowAssign(string $model, string $foreignKey, mixed $parentId, object $instance, array $assign, ?string $name = null): array
    {
        $key = self::_existingKey($instance);
        if ($key === null) {
            return $assign;
        }

        if ($name === null || $name === '') {
            $ref  = self::_handRef($model, $foreignKey);
            $name = null;
            foreach ($this->_known as $candidate => $entry) {
                if (is_array($entry) && ($entry['w'] ?? null) === $ref
                    && (string) ($entry['p'] ?? '') === (string) $parentId
                    && is_array($entry['k'] ?? null) && array_key_exists($key, $entry['k'])) {
                    $name = (string) $candidate;
                    break;
                }
            }
            if ($name === null) {
                return $assign;
            }
        }

        $base    = $this->_knownFor($name, $parentId) !== null ? ($this->_known[$name]['h'][$key] ?? null) : null;
        $columns = $this->_known[$name]['hc'] ?? null;
        if (!is_string($base) || !is_array($columns) || strlen($base) !== count($columns) * self::COLUMN_PRINT) {
            return $assign;
        }

        // As colunas da lista como a tela as registrou (as mesmas com que a
        // base foi tirada no render); sem o token, o que a tela declarou no estado.
        MadFormRegistry::fromRequest();
        $shown = MadFormRegistry::detailShownFields($name);
        if ($shown === null && is_array($this->_known[self::DECLARED]['d'][$name]['c'] ?? null)) {
            $shown = array_fill_keys(array_map('strval', array_keys($this->_known[self::DECLARED]['d'][$name]['c'])), true);
        }
        if ($shown === null) {
            return $assign;
        }

        $at = array_flip(array_map('strval', $columns));
        foreach ($assign as $column => $value) {
            $column = (string) $column;
            // Coluna da lista: sempre. Coluna que a base não tinha: o código a pôs.
            if (isset($shown[$column]) || !isset($at[$column])) {
                continue;
            }
            if (substr($base, $at[$column] * self::COLUMN_PRINT, self::COLUMN_PRINT) === self::_columnPrint($value)) {
                unset($assign[$column]);
            }
        }

        return $assign;
    }

    /**
     * Impressão digital — UMA para a linha — das colunas que o detail NÃO
     * mostra: `$columns` (os atributos do Model da linha) menos `$shown`. É a
     * base que a versão anterior gravava no estado; só serve à tela que foi
     * aberta com ela (ver _withoutUntouchedRowValues).
     *
     * Os valores passam pelo navegador antes de voltar: vazio e nulo são a
     * mesma coisa (o Salvar já grava `''` como NULL). 10.0 volta como 10, e
     * a impressão digital (JSON) já não distingue os dois.
     *
     * @param array<string,mixed> $row
     * @param array<string,true>  $shown
     * @param list<int|string>    $columns
     */
    private static function _rowFingerprint(array $row, array $shown, array $columns): string
    {
        $plain = static function (mixed $value) use (&$plain): mixed {
            if (is_array($value)) {
                return array_map($plain, $value);
            }
            return $value === '' ? null : $value;
        };

        $rest = [];
        foreach ($columns as $column) {
            $column = (string) $column;
            if (isset($shown[$column]) || str_starts_with($column, '__')) {
                continue;
            }
            $rest[$column] = array_key_exists($column, $row) ? ['v' => $plain($row[$column])] : [];
        }
        ksort($rest);

        return self::_fingerprint($rest);
    }

    /**
     * Tira de `$assign` (o que vai ser atribuído a uma linha que JÁ existe) as
     * colunas que o detail não mostra e que voltaram do navegador iguais ao
     * que estava no banco ao carregar: ninguém mexeu nelas nesta tela, e o que
     * está no banco agora pode ser de outra pessoa.
     *
     * A decisão é COLUNA A COLUNA (a mesma régua da lista gravada à mão —
     * handRowAssign):
     *
     *  - coluna que o detail tem: sempre atribuída;
     *  - coluna fora da grade com o valor da base: fica como está no banco;
     *  - coluna fora da grade com OUTRO valor: o código da tela mexeu nela
     *    (`setRows()`, o gancho de carga, o `df_add` de um before-add). É
     *    atribuída — e passa a ser do código até a tela ser recarregada: volta
     *    a ser gravada a cada Salvar, mesmo que o código a devolva ao valor de
     *    quando a tela abriu;
     *  - chave que a linha carregada nem tinha (coluna que o Model esconde em
     *    `$hidden`): foi o código que a pôs, e é atribuída quando é coluna da
     *    tabela. O que o código acrescenta só para exibir, ou uma relação que
     *    veio junto, não é.
     *
     * Antes a base era uma impressão só para a linha: bastava o código mudar
     * UMA coluna fora da grade para a linha ser gravada inteira, e as outras
     * colunas que a grade não mostra voltavam ao valor de quando a tela abriu.
     *
     * Sem base para a linha (linha nova, linhas injetadas por op, tela aberta
     * antes de o formulário anotar as linhas): a linha é gravada inteira, como
     * sempre. Com a base da versão anterior (uma impressão por linha, sem as
     * colunas em `hc`) vale a regra dela até a tela ser recarregada: qualquer
     * coluna fora da grade diferente grava a linha inteira.
     *
     * @param  array<string,mixed> $row    linha como veio do navegador
     * @param  array<string,mixed> $assign colunas a atribuir
     * @return array<string,mixed>
     */
    private function _withoutUntouchedRowValues(string $detailName, mixed $parentId, object $instance, array $row, array $assign): array
    {
        $key = self::_existingKey($instance);
        if ($key === null || $this->_knownFor($detailName, $parentId) === null) {
            return $assign;
        }
        $print = $this->_known[$detailName]['h'][$key] ?? null;
        $shown = is_string($print) ? MadFormRegistry::detailShownFields($detailName) : null;
        if ($shown === null) {
            return $assign;
        }

        $columns = $this->_known[$detailName]['hc'] ?? null;
        if (!is_array($columns)) {
            // Base da versão anterior: uma impressão para a linha inteira.
            if (self::_rowFingerprint($row, $shown, array_keys($instance->attributesToArray())) !== $print) {
                unset($this->_known[$detailName]['h'][$key]);

                return $assign;
            }

            return array_intersect_key($assign, $shown);
        }
        if (strlen($print) !== count($columns) * self::COLUMN_PRINT) {
            return $assign;   // base que não é destas colunas: sem base
        }

        $at = array_flip(array_map('strval', $columns));
        foreach ($assign as $column => $value) {
            $column = (string) $column;
            if (isset($shown[$column])) {
                continue;
            }
            if (!isset($at[$column])) {
                if (!$this->_isRowStorable($instance, $column)) {
                    unset($assign[$column]);
                }
                continue;
            }
            $offset = $at[$column] * self::COLUMN_PRINT;
            if (substr($print, $offset, self::COLUMN_PRINT) === self::_columnPrint($value)) {
                unset($assign[$column]);
                continue;
            }
            // O código mexeu: daqui em diante a coluna é dele.
            $print = substr_replace($print, self::NO_COLUMN_PRINT, $offset, self::COLUMN_PRINT);
        }
        $this->_known[$detailName]['h'][$key] = $print;

        return $assign;
    }

    /**
     * Uma chave que a linha carregada do banco não tinha tem para onde ir no
     * Model da linha? — é coluna da tabela dele (a que o Model esconde em
     * `$hidden`, por exemplo) ou ele a trata num mutator. Sem conseguir ler as
     * colunas da tabela, vale o de sempre: quem decide é o `fill()` do Model.
     */
    private function _isRowStorable(object $instance, string $column): bool
    {
        if (!$instance instanceof \Illuminate\Database\Eloquent\Model) {
            return true;
        }
        $columns = $this->_tableColumns($instance);
        if ($columns === null || isset($columns[strtolower($column)])) {
            return true;
        }

        try {
            return $instance->hasSetMutator($column) || $instance->hasAttributeSetMutator($column);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Chave da linha de `$name` que este formulário gravou e que a tela ainda
     * mostra SEM a chave — a linha nova, identificada pelo `__id` (a tela não é
     * redesenhada depois do Salvar, e o navegador não fica sabendo a chave que
     * o banco deu). Null quando o formulário não conhece a linha.
     *
     * É por ela que a célula Arquivos de uma linha nova, numa tela redesenhada
     * inteira, lê os arquivos que acabaram de ser gravados: sem a chave ela
     * abria vazia, e o Salvar seguinte apagava os arquivos como removidos.
     *
     * @internal chamado pela view do framework (field-list)
     */
    public function knownRowKey(string $name, string $rowId): int|string|null
    {
        $keys = $rowId !== '' ? ($this->_known[$name]['k'] ?? null) : null;
        if (!is_array($keys)) {
            return null;
        }
        $key = array_search($rowId, $keys, true);

        return $key === false ? null : $key;
    }

    /**
     * Arquivos que um Upload Múltiplo em modo tabela está mostrando: as linhas
     * da tabela filha que ele carregou para este pai. Chamado pelo Blade do
     * campo — o Salvar só apaga o anexo que consta aqui e que o usuário
     * removeu do campo.
     *
     * @internal chamado pela view do framework (multi-file-field)
     *
     * @param list<string> $paths
     */
    public function rememberUploadFiles(string $name, mixed $parentId, array $paths): void
    {
        $keys = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            if ($path !== '') {
                $keys[$path] = '';
            }
        }
        $this->_remember($name, $parentId, $keys);
    }

    /**
     * O registro (pai) para o qual este formulário entregou `$name` ao
     * navegador — ou null quando não entregou nada (cadastro novo ainda não
     * salvo, leitura que falhou ao abrir).
     *
     * @internal chamado pela view do framework (multi-file-field)
     */
    public function knownParent(string $name): ?string
    {
        $entry = $this->_known[$name] ?? null;
        if (!is_array($entry) || !is_array($entry['k'] ?? null) || (string) ($entry['p'] ?? '') === '') {
            return null;
        }

        return (string) $entry['p'];
    }

    /**
     * Arquivos que um Upload Múltiplo em modo tabela está desenhando num
     * REDESENHO da tela (render depois de uma ação: o formulário veio do
     * estado, sem o registro) — ou `$paths` null quando a leitura falhou (o
     * campo sai vazio).
     *
     * Passa a valer quando o HTML for entregue (renderDelivered): na resposta
     * parcial o render é descartado e o campo continua mostrando o que
     * mostrava. Leitura que falhou → base VAZIA: o Salvar não apaga anexo.
     *
     * @internal chamado pela view do framework (multi-file-field)
     *
     * @param list<string>|null $paths
     */
    public function noteUploadFiles(string $name, mixed $parentId, ?array $paths): void
    {
        if ($name === '' || $parentId === null || $parentId === '') {
            return;
        }

        $keys = [];
        foreach ($paths ?? [] as $path) {
            $path = (string) $path;
            if ($path !== '') {
                $keys[$path] = '';
            }
        }
        $this->_rendering[$name] = ['p' => (string) $parentId, 'k' => $keys];
    }

    /**
     * A carga de `$name` falhou: o formulário deixa de considerar entregue o
     * que tinha guardado para ele (a tela está mostrando a lista vazia).
     *
     * @internal chamado pela view do framework (multi-file-field)
     */
    public function forgetChildRows(string $name): void
    {
        unset($this->_known[$name]);
    }

    // ── O que o render desenhou × o que chegou ao navegador ──────────────────

    /**
     * O HTML do render que acabou de rodar VAI para o navegador (abertura da
     * tela, redesenho completo, tela embutida): o que os campos desenharam
     * passa a ser o que este formulário entregou.
     *
     * Existe porque nem todo render chega ao navegador: depois de uma ação o
     * componente é sempre renderizado, mas a resposta costuma levar só as
     * operações (toast, valores) e o HTML é descartado. Um campo que relê o
     * banco a cada render não pode trocar a base do Salvar pelo que está no
     * banco AGORA se a tela continua mostrando o que mostrava: o Salvar
     * apagaria a ligação (ou o arquivo) que outra pessoa incluiu nesse meio
     * tempo e esta tela nunca exibiu.
     *
     * @internal chamado por MadComponent::_wrapRenderedHtml()
     */
    public function renderDelivered(): void
    {
        // Leitura do pivô que o campo não chegou a conferir contra as opções
        // (view que só chama loadPivotSelected): vale o que foi lido.
        foreach ($this->_pivotRead as $name => $read) {
            if (!isset($this->_rendering[$name])) {
                $this->_rendering[$name] = ['p' => $read['p'], 'k' => array_fill_keys($read['links'] ?? [], '')];
            }
        }
        $this->_pivotRead = [];

        // As linhas que este render desenhou chegam ao navegador: é contra elas
        // que o formulário confere o que volta nas colunas fora da grade.
        foreach ($this->_renderingRows as $name => $prints) {
            if (!isset($this->_known[self::DECLARED]['d'][$name])) {
                continue;
            }
            if ($prints) {
                $this->_known[self::DECLARED]['d'][$name]['s'] = $prints;
            } else {
                unset($this->_known[self::DECLARED]['d'][$name]['s']);
            }
        }
        $this->_renderingRows = [];

        // Listas gravadas à mão: a base do que o loadDetailRows() leu.
        foreach (array_keys($this->_handBase) as $name) {
            $this->_applyHandBase((string) $name);
        }

        foreach ($this->_rendering as $name => $entry) {
            $name = (string) $name;
            if (!empty($entry['add']) && $this->_knownFor($name, $entry['p']) !== null) {
                // Só acrescenta: as marcas do registro (`u`) ficam como estão.
                $this->_known[$name]['k'] = $this->_known[$name]['k'] + $entry['k'];
            } else {
                $this->_remember($name, $entry['p'], $entry['k']);
                if (array_key_exists('v', $entry) && isset($this->_known[$name])) {
                    $this->_known[$name]['v'] = (string) $entry['v'];
                }
            }
        }
        $this->_rendering = [];
    }

    // ── Campos gravados na própria coluna: o que o campo entregou ────────────

    /**
     * O render em curso desenha este campo com o que veio do REGISTRO — e não
     * com o que o navegador acabou de mandar de volta, nem com o que o
     * formulário já guardava de uma tela que continua aberta?
     *
     * Estes campos (seleção por vírgula, Upload por vírgula, arquivo único) se
     * desenham a partir do valor que o formulário guarda. Num redesenho depois
     * de uma ação esse valor é o que o navegador mandou (com as marcas que o
     * usuário fez e ainda não salvou) ou o de quando a tela abriu: tomá-lo por
     * "o que a tela entregou" faria o Salvar ignorar o que o usuário desmarcou
     * e recusar, como "desmarcado por outra pessoa", o que ele marcou.
     */
    private function _rendersFromRecord(string $name, mixed $parentId): bool
    {
        if ($this->_sourceRecord !== null) {
            return true;    // fill($record) neste request: o valor é o do banco
        }
        if ($this->_knownFor($name, $parentId) !== null) {
            return false;   // a tela já tem base: o redesenho não a troca
        }
        $posted = $_POST['mad_model'] ?? null;

        return !(is_array($posted) && array_key_exists($name, $posted));
    }

    /**
     * O que um campo de seleção múltipla gravado na PRÓPRIA coluna (por
     * vírgula) desenhou marcado: os itens da coluna que estão entre as opções.
     * É a base do Salvar — o item da coluna que a lista não mostra (opção
     * inativa, lista filtrada, opção que não carregou) não está aqui, não volta
     * no POST, e ninguém o desmarcou.
     *
     * Passa a valer quando o HTML for entregue (renderDelivered).
     *
     * @internal chamado por MadRenderContext::selectionRendered()
     *
     * @param list<int|string> $shown
     */
    public function selectionShown(string $name, mixed $parentId, array $shown): void
    {
        if ($name === '') {
            return;
        }
        if ($parentId === null || $parentId === '') {
            $parentId = $this->knownParent($name);
        }
        if ($parentId === null || !$this->_rendersFromRecord($name, $parentId)) {
            return;
        }

        $this->_rendering[$name] = [
            'p' => (string) $parentId,
            'k' => array_fill_keys(self::selectionKeys($shown), ''),
        ];
    }

    /**
     * Os arquivos que um Upload Múltiplo gravado na PRÓPRIA coluna (por
     * vírgula) está mostrando. O Salvar só tira da coluna (e do disco) o
     * arquivo que consta aqui e que o usuário removeu do campo.
     *
     * Passa a valer quando o HTML for entregue (renderDelivered).
     *
     * @internal chamado pela view do framework (multi-file-field)
     *
     * @param list<string> $paths
     */
    public function columnFilesShown(string $name, mixed $parentId, array $paths): void
    {
        if ($name === '') {
            return;
        }
        if ($parentId === null || $parentId === '') {
            $parentId = $this->knownParent($name);
        }
        if ($parentId === null || !$this->_rendersFromRecord($name, $parentId)) {
            return;
        }

        $this->noteUploadFiles($name, $parentId, $paths);
    }

    /**
     * O arquivo que um campo de arquivo ÚNICO gravado no disco está mostrando
     * (`$path` vazio = nenhum). O Salvar só remove o arquivo que a tela
     * mostrou, e não regrava na coluna o caminho de quando a tela abriu.
     *
     * Passa a valer quando o HTML for entregue (renderDelivered).
     *
     * @internal chamado por MadRenderContext::fileRendered()
     */
    public function fileShown(string $name, mixed $parentId, string $path): void
    {
        if ($name === '') {
            return;
        }
        if ($parentId === null || $parentId === '') {
            $parentId = $this->knownParent($name);
        }
        if ($parentId === null || !$this->_rendersFromRecord($name, $parentId)) {
            return;
        }

        $this->_rendering[$name] = [
            'p' => (string) $parentId,
            'k' => $path !== '' ? [$path => ''] : [],
            'v' => $path,
        ];
    }

    // ── Seleção múltipla em outra tabela (pivô): o que o campo entregou ──────

    /**
     * Um campo de seleção múltipla em mode=table leu do banco as ligações do
     * registro aberto — ou tentou: `$links` null é a leitura que falhou (o
     * campo abre sem marca nenhuma).
     *
     * Só anota a leitura deste render; quem decide o que foi entregue é o
     * pivotShown() (o que o campo de fato desenhou marcado) e o
     * renderDelivered() (se o HTML chegou ao navegador).
     *
     * @internal chamado por MadRenderContext::loadPivotSelected()
     *
     * @param list<int|string>|null $links
     */
    public function pivotLoaded(string $name, mixed $parentId, ?array $links): void
    {
        if ($name === '' || $parentId === null || $parentId === '') {
            return;
        }

        $this->_pivotRead[$name] = [
            'p'     => (string) $parentId,
            'links' => $links === null ? null : self::selectionKeys($links),
        ];
    }

    /**
     * O que o campo de seleção múltipla em mode=table DESENHOU marcado
     * (`$shown`). A base do Salvar é isso, não tudo o que está na tabela de
     * ligação: a ligação com um item que a fonte das opções não trouxe (fonte
     * filtrada, opções que não carregaram, item que quem está logado não
     * enxerga) não aparece na tela, não volta no POST — e ninguém a desmarcou.
     *
     * Nada muda aqui: o que este render desenhou só passa a valer quando o
     * HTML for entregue (renderDelivered).
     *
     * Seleção lida do banco NESTE render → o que foi desenhado vira a base.
     * Leitura que falhou → base VAZIA: "nada marcado" no Salvar seguinte não
     * desmarca ninguém.
     *
     * Seleção que NÃO veio do banco neste render (o código da tela a entregou,
     * ou é a que o navegador mandou na ação) → a base só CRESCE, e só com as
     * marcas que existem como ligação (`$readLinks`): a marca que o usuário
     * acabou de fazer ainda não é ligação de ninguém, e a lista que o
     * navegador manda não pode encolher o que o servidor entregou.
     *
     * @internal chamado por MadRenderContext::pivotRendered()
     *
     * @param list<int|string>               $shown
     * @param callable(): list<int|string>   $readLinks lê as ligações do banco (pode lançar)
     */
    public function pivotShown(string $name, mixed $parentId, array $shown, callable $readLinks): void
    {
        if ($name === '' || $parentId === null || $parentId === '') {
            return;
        }
        $shown = self::selectionKeys($shown);
        $read  = $this->_pivotRead[$name] ?? null;
        unset($this->_pivotRead[$name]);

        if ($read !== null && $read['p'] === (string) $parentId) {
            $this->_rendering[$name] = [
                'p' => (string) $parentId,
                'k' => array_fill_keys(array_values(array_intersect($read['links'] ?? [], $shown)), ''),
            ];

            return;
        }

        $known = $this->_knownFor($name, $parentId);
        $ask   = array_values(array_filter(
            $shown,
            static fn (string $id): bool => $known === null || !array_key_exists($id, $known)
        ));
        if (!$ask) {
            return;
        }

        try {
            $links = self::selectionKeys($readLinks());
        } catch (\Throwable $e) {
            return;   // sem conseguir conferir, nada entra na base
        }
        $add = array_fill_keys(array_values(array_intersect($ask, $links)), '');
        if ($add) {
            $this->_rendering[$name] = ['p' => (string) $parentId, 'k' => $add, 'add' => true];
        }
    }

    // ── Coluna Arquivos da Lista de itens: o que cada célula entregou ────────

    /** Nome, em `$_known`, da célula Arquivos de UMA linha da lista (o "pai" é a linha). */
    private static function _cellKnownName(string $detail, string $field, mixed $itemId): string
    {
        return $detail . '::' . $field . '::' . (string) $itemId;
    }

    /**
     * Arquivos que a célula Arquivos (`type="files"`) de uma linha da Lista de
     * itens está desenhando: as linhas da tabela neta lidas para ela — ou
     * `$keys` null quando a leitura falhou (a célula abre sem arquivos). O
     * Salvar só apaga o arquivo que a célula entregou e que o usuário tirou.
     *
     * Passa a valer quando o HTML for entregue (renderDelivered). Leitura que
     * falhou → base VAZIA: o Salvar não apaga nenhum arquivo da linha.
     *
     * @internal chamado pela view do framework (field-list)
     *
     * @param list<string>|null $keys chave de cada arquivo (caminho no disco, "id:<n>" no banco)
     */
    public function noteCellFiles(string $detail, string $field, mixed $itemId, ?array $keys): void
    {
        if ($detail === '' || $field === '' || $itemId === null || $itemId === '') {
            return;
        }

        $shown = [];
        foreach ($keys ?? [] as $key) {
            $key = (string) $key;
            if ($key !== '') {
                $shown[$key] = '';
            }
        }
        $this->_rendering[self::_cellKnownName($detail, $field, $itemId)] = ['p' => (string) $itemId, 'k' => $shown];
    }

    /** Chave do registro de linhas criadas/apagadas neste Salvar (ver $_rowsTouched). */
    private static function _rowsTouchedKey(string $class, string $fk, mixed $parentId): string
    {
        return ltrim($class, '\\') . '|' . $fk . '|' . (string) $parentId;
    }

    /** Anota uma linha filha que outro componente deste formulário criou ('created') ou apagou ('gone'). */
    private function _rowTouched(string $what, object $child, string $fk, mixed $parentId): void
    {
        $key = method_exists($child, 'getKey') ? $child->getKey() : null;
        if ($key === null || $key === '') {
            return;
        }
        $this->_rowsTouched[self::_rowsTouchedKey(get_class($child), $fk, $parentId)][$what][(string) $key] = true;
    }

    /**
     * Grava as linhas de um detail (Lista de itens / Detail Form) e remove as
     * que o usuário tirou.
     *
     * Cada linha é decidida por ELA MESMA, não pela primeira do POST:
     *   - traz a chave de uma linha deste pai → atualiza essa linha;
     *   - não traz chave, mas é uma linha que este formulário já gravou (mesmo
     *     `__id`: a tela não é redesenhada depois de um Salvar) → atualiza a
     *     mesma linha, em vez de criar outra e apagar a anterior;
     *   - o resto → linha nova.
     *
     * E só é APAGADA a linha que este formulário entregou ao navegador
     * (`$_known`) e que não voltou. Antes era "tudo o que não veio": bastava a
     * primeira linha não ter chave (linha nova no topo) para todas as linhas
     * do pai serem apagadas e recriadas com outra chave, e bastava a lista não
     * ter carregado para o Salvar apagar o que o usuário nem viu.
     *
     * Antes de qualquer escrita, a chave para outro cadastro que uma linha vai
     * GRAVAR passa pelas regras de referência do Model dela — ver
     * _guardRowReferences(). Uma linha recusada recusa o Salvar inteiro.
     *
     * @throws ReferenceViolation linha com a chave de um cadastro que quem salva não enxerga
     *
     * @param string        $model  Classe do model Eloquent do filho
     * @param string        $fk     Coluna FK no model filho
     * @param object        $mestre Record pai (já com ID)
     * @param array         $rows   Rows do formulário
     * @param callable|null $hook   fn($detalhe, $mestre, $formRow): void
     */
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

        // Filtra rows completamente vazias
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            foreach ($row as $k => $v) {
                if (str_starts_with($k, '__')) continue;
                if (self::isDisplayOnlyDetailKey($k)) continue;
                if (trim((string)($v ?? '')) !== '') return true;
            }
            return false;
        }));

        // Classe e chave do model FILHO — nem toda tabela de detalhe se chama
        // `id`. Com a chave errada nenhuma linha era reconhecida e toda linha
        // filha era destruida e recriada a cada gravacao (PK nova, e qualquer
        // coluna fora da field-list perdida).
        $cls        = $model;
        $detailPk   = 'id';
        $hasKey     = true;
        $canTypeKey = false;   // a chave é digitada na tela (não incremental e gravável)?
        try {
            $__cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            if ($__cls && class_exists($__cls)) {
                $__probe  = new $__cls();
                // Nome REAL da classe: `model="OsAnexo"` chega aqui como alias
                // do autoloader, e o que outro componente anotou em
                // $_rowsTouched está pelo get_class() da linha.
                $cls      = get_class($__probe);
                $detailPk = $__probe->getKeyName();
                $hasKey   = $this->_detailHasKeyColumn($__probe);
                $canTypeKey = !$__probe->getIncrementing() && $__probe->isFillable((string) $detailPk);
            }
        } catch (\Throwable $e) {}

        // O que este formulário entregou para este detail SOB este pai (null =
        // não carregou) e, pelo `__id`, as linhas que ele mesmo já gravou.
        $known   = $this->_knownFor($detailName, $parentId);
        $touched = $this->_rowsTouched[self::_rowsTouchedKey($cls, $fk, $parentId)] ?? [];

        // Tabela filha SEM coluna de chave (tabela legada importada): as linhas
        // não têm identidade — não há o que atualizar nem como apagar uma só. A
        // única sincronização possível é trocar o conjunto, e só quando esta
        // tela carregou as linhas deste pai (senão a lista vazia apagaria tudo).
        if (!$hasKey) {
            $lines = [];
            foreach ($rows as $position => $row) {
                $lines[] = [$row, new $cls(), $position + 1];
            }
            $values = $this->_guardRowReferences($cls, $fk, $parentId, $lines, $detailName, $fileColumns);

            if ($known !== null && empty($touched['created'])) {
                $cls::query()->where($fk, '=', $parentId)->delete();
            }
            foreach ($lines as $i => [$row, $instance]) {
                $this->_persistDetailInstance($instance, $fk, $parentId, $row, $hook, $mestre, $detailName, $fileColumns, $values[$i] ?? null);
            }
            $this->_remember($detailName, $parentId, [], $mestre);

            return;
        }

        $byRowId = [];
        foreach ($known ?? [] as $knownKey => $knownRowId) {
            if ($knownRowId !== '') {
                $byRowId[$knownRowId] = $knownKey;
            }
        }

        $unconfirmed = $known !== null ? (array) ($this->_known[$detailName]['u'] ?? []) : [];
        $quiet       = $known !== null ? (array) ($this->_known[$detailName]['g'] ?? []) : [];

        // ── 1) Quem é cada linha do POST ────────────────────────────────────
        $plan    = [];   // [linha, __id, instância existente | null, posição na lista]
        $kept    = [];   // chaves das linhas deste pai que voltaram no POST
        $stale   = [];   // chave => __id das linhas que outra tela já removeu
        $staleAt = [];   // posição dessas linhas na lista (a partir de 1)
        $silent  = [];   // chave => __id das que o usuário removeu aqui, pelo Upload

        foreach ($rows as $position => $row) {
            $rowId = is_scalar($row['__id'] ?? null) ? (string) $row['__id'] : '';
            // Sem (int): PK de texto ('ABC-1') viraria 0 e o whereKey erraria.
            $pkVal = (is_scalar($row[$detailPk] ?? null) && !empty($row[$detailPk])) ? $row[$detailPk] : null;
            if ($pkVal === null && $rowId !== '' && isset($byRowId[$rowId])) {
                $pkVal = $byRowId[$rowId];
            }

            // Linha que o Upload Múltiplo desta tela (mesma tabela) acabou de
            // apagar porque o usuário removeu o anexo: não é para voltar — e ele
            // não precisa de aviso, foi ele quem removeu.
            if ($pkVal !== null && isset($touched['gone'][(string) $pkVal])) {
                $silent[$pkVal] = $rowId;
                continue;
            }

            // Eloquent: carregar por pk p/ que save() faça UPDATE (setar id num
            // model novo causaria INSERT). ESCOPADO ao pai (fk): sem isso um
            // cliente podia passar o id de uma linha de OUTRO pai/tenant e
            // re-parenteá-la (IDOR de linha). Fora do escopo do pai → trata como
            // nova (INSERT), nunca sequestra a linha alheia.
            $instance = $pkVal !== null
                ? $cls::query()->where($fk, '=', $parentId)->whereKey($pkVal)->first()
                : null;
            if ($instance) {
                $kept[$instance->getKey()] = true;
            } elseif ($pkVal !== null && isset($quiet[$pkVal])) {
                // Removida num Salvar anterior desta mesma tela (pelo Upload); a
                // lista, que não foi recarregada, continua mandando a linha.
                $silent[$pkVal] = $rowId;
                continue;
            } elseif ($pkVal !== null && $this->_detailRowIsGone($cls, $pkVal, $known, $unconfirmed, $canTypeKey)) {
                // A tela (desatualizada) ainda mostra uma linha que outra aba, ou
                // outra pessoa, já removeu. NÃO volta: nem como estava, nem com
                // o que foi editado aqui — quem removeu por último decidiu. O
                // resto do Salvar segue, e o usuário é avisado.
                $stale[$pkVal] = $rowId;
                $staleAt[]     = $position + 1;
                continue;
            }
            $plan[] = [$row, $rowId, $instance, $position + 1];
        }
        if ($staleAt) {
            $this->warnChildRowsGone($staleAt);
        }

        // ── 1b) As chaves para outro cadastro que as linhas vão gravar ──────
        // Antes de apagar ou gravar o que for: a linha recusada não pode
        // deixar as outras gravadas pela metade.
        $lines = [];
        foreach ($plan as [$row, , $instance, $at]) {
            $lines[] = [$row, $instance ?? new $cls(), $at];
        }
        $values = $this->_guardRowReferences($cls, $fk, $parentId, $lines, $detailName, $fileColumns);

        // ── 2) O que apagar ─────────────────────────────────────────────────
        if ($known !== null) {
            // A linha que a tela mostrou e o usuário removeu. Linha que nunca
            // passou por esta tela (outra pessoa incluiu depois, outro
            // componente criou neste mesmo Salvar) não está em $known.
            $remove = array_diff(array_keys($known), array_keys($kept), array_keys($stale), array_keys($silent));
        } elseif ($kept !== []) {
            // Sem registro do que a tela mostrou (linhas injetadas por op,
            // tela aberta antes desta versão), mas o POST trouxe linhas que SÃO
            // deste pai: o navegador tinha a lista, e o que falta nela foi
            // removido. Menos o que nasceu neste Salvar por outro componente.
            $remove = array_diff(
                $cls::query()->where($fk, '=', $parentId)->pluck($detailPk)->all(),
                array_keys($kept),
                array_keys($touched['created'] ?? [])
            );
        } else {
            // A tela não carregou as linhas deste pai e o POST não prova que
            // as tinha: gravar o que veio é seguro, apagar o resto não.
            $remove = [];
        }

        // Antes de gravar: a linha que entra pode ocupar o lugar da que sai num
        // índice único (trocar o produto de uma linha, gerar as parcelas de novo).
        $this->_deleteDetailRows($cls, $fk, $parentId, $detailPk, array_values($remove));

        // ── 3) Grava: atualiza as que já existem, insere as novas ───────────
        $saved   = [];   // chave => __id
        $pending = [];   // chaves criadas agora (ou ainda não confirmadas)
        foreach ($plan as $i => [$row, $rowId, $instance]) {
            $isNew      = $instance === null;
            $instance ??= new $cls();
            $this->_persistDetailInstance($instance, $fk, $parentId, $row, $hook, $mestre, $detailName, $fileColumns, $values[$i] ?? null);

            $key = method_exists($instance, 'getKey') ? $instance->getKey() : null;
            if ($key !== null && $key !== '') {
                $saved[$key] = $rowId;
                if ($isNew || isset($unconfirmed[$key])) {
                    $pending[] = $key;
                }
            }
        }

        // A partir daqui a tela "tem" as linhas que gravou — e continua lembrando
        // das que outra tela removeu: enquanto não for recarregada, ela vai
        // mandá-las de novo a cada Salvar.
        $this->_remember($detailName, $parentId, $saved + $stale + $silent, $mestre, $pending, array_keys($silent));
    }

    /**
     * A tabela do model filho tem a coluna da chave? O `getKeyName()` responde
     * 'id' mesmo quando a tabela não tem chave nenhuma (tabela legada). Sem
     * conseguir consultar o schema, vale o caso comum: tem.
     */
    private function _detailHasKeyColumn(object $probe): bool
    {
        $keyName = (string) ($probe->getKeyName() ?? '');
        if ($keyName === '') {
            return false;
        }

        $cacheKey = get_class($probe) . '|' . $keyName;
        if (!array_key_exists($cacheKey, $this->_detailKeyColumnCache)) {
            try {
                $this->_detailKeyColumnCache[$cacheKey] = \Illuminate\Support\Facades\Schema::connection($probe->getConnectionName())
                    ->hasColumn($probe->getTable(), $keyName);
            } catch (\Throwable $e) {
                $this->_detailKeyColumnCache[$cacheKey] = true;
            }
        }

        return $this->_detailKeyColumnCache[$cacheKey];
    }

    /**
     * Apaga linhas filhas deste pai, uma a uma, pelo Model: é o `delete()` da
     * instância que conhece a exclusão lógica da tabela (HasMadSoftDeletes /
     * SoftDeletes) e dispara `deleting`/`deleted` — auditoria e observers. O
     * `delete()` na consulta apagava a linha de vez, sem evento nenhum.
     *
     * A linha cujo Model recusa a exclusão (`deleting` devolve false) fica.
     *
     * @param list<int|string> $keys
     */
    private function _deleteDetailRows(string $cls, string $fk, mixed $parentId, string $detailPk, array $keys): void
    {
        if (!$keys) {
            return;
        }

        // Chave de texto que parece número ('7') volta do estado como inteiro
        // (chave de array do PHP); num banco que não converte sozinho, comparar
        // a coluna de texto com um inteiro é erro de SQL.
        if ((new $cls())->getKeyType() === 'string') {
            $keys = array_map('strval', $keys);
        }

        foreach (array_chunk($keys, 200) as $chunk) {
            $orphans = $cls::query()->where($fk, '=', $parentId)->whereIn($detailPk, $chunk)->get();
            foreach ($orphans as $orphan) {
                $orphan->delete();
            }
        }
    }

    /**
     * O que uma linha do navegador atribui à instância dela: os escalares da
     * linha, menos o que não é coluna (metadado `__*`, coluna de exibição com
     * caminho de relacionamento, coluna de arquivo) e menos a chave VAZIA — a
     * linha nova não tem chave, e a reconhecida pelo `__id` já foi carregada
     * com a dela; num model sem `$fillable` restrito, o NULL iria para o INSERT
     * (o Postgres recusa) ou para o UPDATE da chave.
     *
     * Na linha que já existe, as colunas que o detail não mostra e em que
     * ninguém mexeu não são regravadas. E a chave gerada pelo banco, as
     * colunas de controle e a empresa nunca vêm da linha do navegador.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function _detailRowValues(object $instance, mixed $parentId, array $row, string $detailName, array $fileColumns): array
    {
        $keyName = $instance instanceof \Illuminate\Database\Eloquent\Model ? $instance->getKeyName() : null;

        $assign = [];
        foreach ($row as $k => $v) {
            if (str_starts_with($k, '__')) continue;
            if (self::isDisplayOnlyDetailKey($k)) continue;  // chain `{a->b->c}`: não é coluna
            if (isset($fileColumns[$k])) continue;   // coluna de arquivo: tratada à parte
            if ($k === $keyName && ($v === '' || $v === null)) continue;
            $assign[$k] = ($v === '') ? null : $v;
        }
        if (!$instance instanceof \Illuminate\Database\Eloquent\Model) {
            return $assign;
        }

        return $this->_withoutScreenGuarded(
            $instance,
            $this->_withoutUntouchedRowValues($detailName, $parentId, $instance, $row, $assign),
            $detailName !== '' ? $detailName : '-',
        );
    }

    /**
     * As chaves para outro cadastro que as linhas de um detail vão GRAVAR
     * passam pelas regras de referência (`exists`) do Model da linha — as que
     * a plataforma gera quando a coluna aponta para um cadastro da unidade ou
     * para um usuário. O campo de tabela da linha só lista o que quem salva
     * enxerga; sem isto a requisição alterada gravava o número que quisesse (o
     * cliente de outra unidade), porque só o formulário principal chamava
     * `rules()`.
     *
     * Só o que MUDA na linha é conferido: a chave que ela já tem gravada volta
     * igual no Salvar de quem não mexeu nela, mesmo apontando para um cadastro
     * que essa pessoa não enxerga (o administrador definiu alguém de outra
     * unidade). E só o que vem do navegador: o que o gancho de gravação atribui
     * depois é valor do código.
     *
     * Todas as linhas são conferidas ANTES de qualquer escrita, e uma recusada
     * recusa o Salvar. Model sem regra de referência: nada é calculado aqui
     * (devolve vazio, e cada linha segue como sempre).
     *
     * @param  list<array{0: array<string,mixed>, 1: object, 2: int}> $lines [linha, instância (a que existe ou uma nova), posição na lista]
     * @return list<array<string,mixed>> o que cada linha atribui, na ordem de `$lines` (vazio = não foi preciso calcular)
     *
     * @throws ReferenceViolation
     */
    private function _guardRowReferences(string $cls, string $fk, mixed $parentId, array $lines, string $detailName, array $fileColumns): array
    {
        $guarded = $lines !== [] ? ReferenceGuard::columns($cls) : [];
        if ($guarded === []) {
            return [];
        }

        $values = [];
        $check  = [];
        foreach ($lines as $i => [$row, $instance, $position]) {
            $values[$i] = $this->_detailRowValues($instance, $parentId, $row, $detailName, $fileColumns);
            $check[]    = [$position, $instance, $values[$i]];
        }

        $errors = self::rowReferenceErrors($cls, $guarded, $fk, $check, $this->rowLabels($detailName), $detailName, true);
        if ($errors) {
            throw new ReferenceViolation($errors);
        }

        return $values;
    }

    /**
     * Confere, linha a linha, as chaves para outro cadastro que cada linha vai
     * gravar (ver _guardRowReferences) e devolve os erros prontos para o
     * usuário: a lista, a linha e a mensagem da regra.
     *
     * @internal usado também por MadFieldListTrait::saveDetailItems()
     *
     * @param  array<string,string>                                        $guarded colunas com regra de referência (ReferenceGuard::columns)
     * @param  list<array{0: int, 1: ?object, 2: array<string,mixed>}>     $lines   [posição na lista, instância da linha (a que existe ou uma nova) | null, o que a linha atribui]
     * @param  array{list: string, columns: array<string,string>}          $labels  rótulos da tela (rowLabels)
     * @param  bool                                                        $viaFill a linha é atribuída pelo `fill()` do Model (a coluna fora do `$fillable` não é gravada)
     * @return array<string,string> `lista.linha.coluna` => mensagem
     */
    public static function rowReferenceErrors(string $cls, array $guarded, string $fk, array $lines, array $labels, string $name = '', bool $viaFill = false): array
    {
        $errors = [];
        foreach ($lines as [$position, $instance, $values]) {
            $model   = $instance instanceof \Illuminate\Database\Eloquent\Model ? $instance : null;
            $changed = [];
            foreach ($values as $column => $value) {
                $column = (string) $column;
                // A chave estrangeira do pai é posta pelo Salvar, não pela linha.
                if (!isset($guarded[$column]) || $column === $fk
                    || ($viaFill && $model !== null && !$model->isFillable($column))
                    || ($model !== null && self::_sameReference($model, $column, $value))) {
                    continue;
                }
                $changed[$column] = $value;
            }

            $key = $model !== null ? self::_existingKey($model) : null;
            foreach (ReferenceGuard::check($cls, $key, $changed, $labels['columns'], $values) as $column => $message) {
                $errors[$name . '.' . $position . '.' . $column] = $labels['list'] !== ''
                    ? self::_text('mad.form.row_invalid', ['list' => $labels['list'], 'row' => $position, 'message' => $message], 'Na lista :list, linha :row: :message')
                    : self::_text('mad.form.row_invalid_unnamed', ['row' => $position, 'message' => $message], 'Linha :row: :message');
                ReferenceGuard::logRefused(sprintf('a coluna "%s" da linha %d da lista "%s"', $column, $position, $name !== '' ? $name : $cls));
            }
        }

        return $errors;
    }

    /** A linha já tem gravado, em `$column`, exatamente este valor? (comparação de chave: texto com texto, sem conversão) */
    private static function _sameReference(\Illuminate\Database\Eloquent\Model $instance, string $column, mixed $value): bool
    {
        if (!$instance->exists) {
            return false;
        }
        $stored = $instance->getRawOriginal($column);
        if ($stored === null || $stored === '' || $value === null || $value === '') {
            // Vazio com vazio é "igual"; esvaziar não é referência a conferir.
            return $value === null || $value === '';
        }

        return is_scalar($stored) && is_scalar($value) && (string) $stored === (string) $value;
    }

    /**
     * Os rótulos com que a tela mostra uma lista e as colunas dela, para a
     * mensagem de uma linha recusada: o título da lista e o rótulo de cada
     * coluna (Lista de itens) ou de cada `<mad-col>` (Detail Form).
     *
     * Sem o nome (lista gravada à mão cujas linhas vão pela prop `:rows`), a
     * lista é a que o `loadDetailRows()` carregou com este Model e esta chave
     * estrangeira, sob este pai.
     *
     * @internal usado também por MadFieldListTrait::saveDetailItems()
     *
     * @return array{list: string, columns: array<string,string>}
     */
    public function rowLabels(?string $detailName, string $model = '', string $foreignKey = '', mixed $parentId = null): array
    {
        if (($detailName === null || $detailName === '') && $model !== '') {
            $ref = self::_handRef($model, $foreignKey);
            foreach ($this->_known as $candidate => $entry) {
                if (is_array($entry) && ($entry['w'] ?? null) === $ref && (string) ($entry['p'] ?? '') === (string) $parentId) {
                    $detailName = (string) $candidate;
                    break;
                }
            }
        }
        $detailName = (string) $detailName;

        $entry   = (array) ($this->_known[self::DECLARED]['d'][$detailName] ?? []);
        $columns = [];
        foreach ((array) ($entry['t'] ?? []) as $column => $label) {
            if (is_string($label) && $label !== '') {
                $columns[(string) $column] = $label;
            }
        }

        MadFormRegistry::fromRequest();
        foreach ((array) (MadFormRegistry::getDetailForm($detailName)['columns'] ?? []) as $column) {
            $field = is_array($column) ? (string) ($column['field'] ?? '') : '';
            $label = is_array($column) ? trim(strip_tags((string) ($column['label'] ?? ''))) : '';
            if ($field !== '' && $label !== '' && !isset($columns[$field])) {
                $columns[$field] = $label;
            }
        }

        return ['list' => is_string($entry['tl'] ?? null) ? $entry['tl'] : '', 'columns' => $columns];
    }

    /**
     * Popula, processa uploads por-linha e persiste UMA instância de detail.
     *
     * Colunas de arquivo (registradas em MadFormRegistry::registerDetailFileColumns)
     * NÃO são copiadas como escalar: o arquivo enviado em
     * $_FILES['mad_fl_files'][detail__rowId__field] é gravado no disco (path na
     * coluna, antes do save) ou como BLOB base64 (depois do save). Sem arquivo
     * novo, o valor existente é mantido (linha carregada via find()).
     *
     * @param array<string,mixed>|null $values o que a linha atribui, quando já foi calculado (_guardRowReferences)
     */
    private function _persistDetailInstance(
        object      $instance,
        string      $fk,
        mixed       $parentId,
        array       $row,
        ?callable   $hook,
        object      $mestre,
        string      $detailName,
        array       $fileColumns,
        ?array      $values = null
    ): object {
        $rowId = (string) ($row['__id'] ?? '');

        // O que a linha atribui vai pelo fill(), para RESPEITAR o
        // $fillable/$guarded do model (defesa de mass-assignment): o cliente NÃO
        // grava colunas não-fillable (user_id/tenant_id/price/created_by/…) numa
        // linha de detail. Antes era atribuição direta ($instance->$k = $v), que
        // ignora o $fillable — o vetor de mass-assignment de detail-row.
        $values ??= $this->_detailRowValues($instance, $parentId, $row, $detailName, $fileColumns);
        if ($instance instanceof \Illuminate\Database\Eloquent\Model) {
            $this->_rowColumnsWithoutColumn($detailName, $instance, $values, $hook !== null);
            $instance->fill($values);
        } else {
            foreach ($values as $k => $v) {
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
        $cellFiles = [];   // coluna de arquivo único => [caminho gravado => impressão digital]
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
            $unique  = self::_uniqueFileNames($meta['fileName'] ?? 'prefix');
            $current = '';
            $hash    = '';
            if (!$multi) {
                $current = (string) ($instance->$field ?? '');
                // A célula não é esvaziada depois do Salvar: ela manda o
                // arquivo de novo a cada Salvar. O que esta tela JÁ gravou
                // nesta linha (mesmo conteúdo) não é troca — regravá-lo passava
                // por cima do que outra aba tivesse posto no lugar.
                $hash = self::_fileHash((string) $files[0]['tmp_name']);
                $base = $this->_fileBase(self::_cellKnownName($detailName, $field, self::_existingKey($instance) ?? ''), self::_existingKey($instance));
                if ($base !== null && $base['hash'] !== '' && $hash !== '' && hash_equals($base['hash'], $hash)) {
                    if ($current !== $base['path']) {
                        $this->warnChildRowsGone([], [self::_storedFileLabel($base['path'])]);
                    }
                    continue;
                }
            }
            $paths = [];
            foreach ($files as $file) {
                $fileName = $this->_buildFileName($file['name'], $meta['fileName'] ?? 'prefix', $instance);
                $dest     = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                if ($this->_storeFile($file['tmp_name'], $dest, $instance, $unique)) {
                    $paths[] = $dest;
                }
                if (!$multi) break;
            }
            if ($paths) {
                if (!$multi) {
                    // Substituição de single-file: o arquivo antigo sai do
                    // disco quando a transação confirmar. Apagá-lo na hora
                    // deixava, num Salvar que falha depois, a linha de volta
                    // apontando para um arquivo que não existe mais.
                    $this->_discardFile($current, $instance);
                    $cellFiles[$field] = [$paths[0] => $hash];
                }
                $instance->$field = $multi ? implode(',', $paths) : $paths[0];
                if ($nameColumn) {
                    $instance->$nameColumn = $this->_sanitizeUploadName($files[0]['name']);
                }
            }
        }

        $instance->save();

        // A linha passa a "ter" o arquivo que acabou de ser gravado nela (com a
        // impressão digital, para reconhecer o reenvio da célula).
        foreach ($cellFiles as $field => $stored) {
            $key = self::_existingKey($instance);
            if ($key !== null) {
                $this->_remember(self::_cellKnownName($detailName, (string) $field, $key), $key, $stored, $instance, array_keys($stored));
            }
        }

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
     * (chave = path no disk, "id:<n>" no db). Reconcilia: apaga (linha + arquivo
     * no disco) o neto que a célula MOSTROU (`$_known`, gravado pelo Blade ao
     * ler a tabela neta) e que não voltou entre os mantidos; depois insere os
     * novos. Antes era "todo neto da linha que não veio": com a célula aberta
     * sem os arquivos (leitura que falhou) o Salvar apagava todos, e o arquivo
     * que outra pessoa anexou depois sumia no Salvar de uma tela desatualizada.
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
        $existing = $existingQuery->get();

        // O que esta célula mostrou (null = a tela não tem registro dela: linha
        // nova, ou tela aberta antes desta versão).
        $known = self::_cellKnownName($detailName, $field, $itemId);
        $shown = $this->_knownFor($known, $itemId);

        // Sem registro do que a célula mostrou: só reconcilia se o POST prova
        // que ela tinha a lista — alguma chave mantida é de um arquivo desta
        // linha (o navegador só conhece a chave que o servidor entregou).
        $proven = false;
        if ($shown === null) {
            foreach ($existing as $gf) {
                $gkey = ($storage === 'db') ? ('id:' . $gf->id) : (string) ($gf->$pathColumn ?? '');
                if ($gkey !== '' && in_array($gkey, $kept, true)) {
                    $proven = true;
                    break;
                }
            }
        }

        $alive     = [];
        $keptShown = [];   // mantidos que a célula de fato mostrou
        foreach ($existing as $gf) {
            $gkey = ($storage === 'db') ? ('id:' . $gf->id) : (string) ($gf->$pathColumn ?? '');
            if ($gkey === '') {
                continue;   // sem arquivo: não é um arquivo desta célula
            }
            $alive[$gkey] = true;
            if ($shown !== null ? !array_key_exists($gkey, $shown) : !$proven) {
                continue;   // a célula nunca mostrou este arquivo
            }
            if (in_array($gkey, $kept, true)) {
                $keptShown[] = $gkey;
                continue;
            }
            // Linha primeiro: o Model que recusa a exclusão fica com o arquivo.
            if ($gf->delete() === false) {
                continue;
            }
            if ($storage === 'disk') {
                // Só quando a transação confirmar: se o Salvar falhar depois, a
                // linha volta — com o arquivo dela.
                $this->_discardFile($gkey, $gf);
            }
        }

        // Arquivo que a célula (desatualizada) ainda mostra e que outra tela já
        // removeu: não há o que regravar. O usuário é avisado.
        $goneFiles = [];
        foreach ($kept as $gkey) {
            if ($shown !== null && array_key_exists($gkey, $shown) && !isset($alive[$gkey])) {
                $bn = $storage === 'db' ? str_replace('id:', 'arquivo_', $gkey) : basename($gkey);
                $goneFiles[] = preg_match('/^[a-f0-9]{13,16}_(.+)$/i', $bn, $m) ? $m[1] : $bn;
            }
        }
        if ($goneFiles) {
            $this->warnChildRowsGone([], $goneFiles);
        }

        // ── 2) Insere os novos uploads ──────────────────────────────────────
        $newKeys = [];
        $saved   = [];   // o que a célula recebe de volta (files_saved)
        $files   = $this->_madFlFilesFor($detailName, $rowId, $field);

        if (empty($files)) {
            // nada a inserir
        } elseif ($storage === 'disk') {
            $folder = $this->_normalizeUploadFolder((string) ($meta['folder'] ?? 'uploads'));
            $unique = self::_uniqueFileNames($meta['fileName'] ?? 'prefix');
            foreach ($files as $file) {
                $fileName = $this->_buildFileName($file['name'], $meta['fileName'] ?? 'prefix', $itemInstance);
                $dest     = rtrim($folder, '/') . '/' . $fileName;   // relativo (banco)
                $gc       = new $model();
                if ($this->_storeFile($file['tmp_name'], $dest, $gc, $unique)) {
                    $gc->$fk = $itemId;
                    if ($useStorageCol) {
                        $gc->storage = 'disk';
                    }
                    $gc->$pathColumn = $dest;
                    if ($nameColumn) {
                        $gc->$nameColumn = $this->_sanitizeUploadName($file['name']);
                    }
                    $gc->save();
                    $newKeys[] = $dest;

                    $label   = $nameColumn ? (string) $gc->$nameColumn : basename($dest);
                    $saved[] = ['uid' => (string) ($file['uid'] ?? ''), 'id' => (int) $gc->id, 'key' => $dest, 'name' => $label, 'url' => self::_storedFileUrl($dest, $label)];
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
                $newKeys[] = 'id:' . $gc->id;

                $label   = $nameColumn ? (string) $gc->$nameColumn : ('arquivo_' . $gc->id);
                $saved[] = ['uid' => (string) ($file['uid'] ?? ''), 'id' => (int) $gc->id, 'key' => 'id:' . $gc->id, 'name' => $label, 'url' => function_exists('mad_blob_url') ? (string) mad_blob_url($model, (int) $gc->id, $pathColumn, $label) : ''];
            }
        }

        // A célula passa a "ter" os mantidos e os recém-gravados: a tela não é
        // redesenhada depois do Salvar, e é a resposta que conta à célula que
        // eles já estão gravados (files_saved). Daí em diante ela os manda
        // entre os mantidos — não de novo como arquivo novo, o que fazia cada
        // Salvar apagar a linha do envio anterior e criar outra, com outra chave.
        $this->_remember($known, $itemId, array_fill_keys(array_merge($keptShown, $newKeys), ''), $itemInstance);
        $this->_announceSavedFiles($cellKey, $saved, $itemInstance);
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
        $ops = $this->_pendingFlOps;

        // Linhas/anexos que este Salvar não regravou porque outra tela já os
        // tinha removido: o usuário precisa saber, qualquer que seja a
        // resposta que o código da tela devolveu.
        if ($notice = $this->_goneNoticeOp()) {
            $ops[] = $notice;
        }

        // O que outra aba ou outra pessoa marcou/desmarcou num checklist
        // gravado pelo código da tela, e que este Salvar não desfez.
        if ($notice = $this->_marksNoticeOp()) {
            $ops[] = $notice;
        }

        // O que o usuário digitou e este Salvar não gravou (campo ou coluna de
        // lista que não é coluna da tabela).
        if ($notice = $this->_notStoredNoticeOp()) {
            $ops[] = $notice;
        }
        // Campo que um Salvar anterior recusou e que agora passou: a mensagem sai.
        foreach (array_keys($this->_passedNow) as $field) {
            $ops = [...$ops, ...(new MadResponse())->clearFieldError((string) $field)->getOps()];
        }
        $this->_passedNow = [];

        if ($this->_pendingFocus === null) {
            return $ops;
        }

        // Por último: o foco vem depois de valores/linhas que a ação mexeu.
        return [...$ops, ['op' => 'focus', 'name' => $this->_pendingFocus]];
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
        // Só os campos que o detalhe TEM (editor e grade): o bucket vem do
        // navegador, e o que entra aqui fica em `$fields` durante a ação.
        if ($this->declares()) {
            $columns = $this->_known[self::DECLARED]['d'][$detail]['c'] ?? null;
            $extra   = is_array($columns) ? array_diff_key($values, $columns) : $values;
            if ($extra) {
                $names = array_map('strval', array_keys($extra));
                sort($names);
                $this->_refused['[' . $detail . ']'] = (is_array($columns)
                    ? 'o editor do detalhe mandou campos que ele não tem: '
                    : 'a tela não tem um detalhe com este nome; campos enviados: ') . implode(', ', $names);
                $values = array_diff_key($values, $extra);
            }
        }

        // Campo do editor que é Editor HTML: o que a ação lê já vem limpo.
        $values = $this->_cleanRowHtml($detail, $values);

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
        $state = [
            '__mad_form_name' => $this->name,
            'fields'          => $this->fields,
            'items'           => $this->items,
            'placeholders'    => $this->placeholders,
            'hidden'          => $this->hidden,
            'readonly'        => $this->readonly,
            'disabled'        => $this->disabled,
        ];

        // Só quando há o que lembrar (registro carregado, linha filha, anexo):
        // o formulário de filtro ou de cadastro novo mantém o estado de sempre.
        if ($this->_known !== []) {
            $state['known'] = $this->_known;
        }

        return $state;
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
        $instance->_known       = is_array($data['known'] ?? null) ? $data['known'] : [];
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