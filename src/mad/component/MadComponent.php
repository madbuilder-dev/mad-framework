<?php
namespace Mad\Component;
use Illuminate\Database\Eloquent\Model;
use Mad\Form\MadComboOrigin;
use Mad\Form\MadForm;
use Mad\Form\ModelOptionsLoader;
use Mad\Grid\MadDataGrid;
use Mad\Http\MadRequest;
use Mad\Http\MadResponse;
use Mad\Http\MadStateCrypt;
use Mad\Ui\MadAction;
use Mad\Ui\MadErrorPage;
use Mad\Ui\MadErrorRenderer;
use Mad\View\MadBlade;



/**
 * MadComponent — Componente PHP reativo, similar ao Livewire.
 *
 * ┌─ Como criar um componente ────────────────────────────────────────────────┐
 * │                                                                           │
 * │  1. Crie a classe em app/components/                                      │
 * │     class ContadorComponent extends \Mad\Component\MadComponent                     │
 * │     {                                                                     │
 * │         public int    $contador = 0;     ← propriedades públicas          │
 * │         public string $nome     = '';    ← são sincronizadas              │
 * │         public MadForm $form;            ← formulário como VO             │
 * │                                                                           │
 * │         public function incrementar(): void { $this->contador++; }       │
 * │         public function resetar(): void     { $this->contador = 0; }     │
 * │                                                                           │
 * │         protected function view(): string|array                           │
 * │         {                                                                 │
 * │             return 'components.contador';                                 │
 * │             // OU com dados extras para a view:                           │
 * │             // return ['components.contador', ['chart' => $html]];        │
 * │         }                                                                 │
 * │     }                                                                     │
 * │                                                                           │
 * │  2. Crie a view em app/resources/views/components/contador.blade.php      │
 * │     <div>                                                                 │
 * │         <p>{{ $contador }}</p>                                             │
 * │         <button mad:click="incrementar">+</button>                        │
 * │         <input mad:model="nome" value="{{ $nome }}">                      │
 * │     </div>                                                                │
 * │                                                                           │
 * │  3. Use na sua view ou controller:                                        │
 * │     {!! \Mad\View\MadBlade::component(ContadorComponent::class) !!}            │
 * │     {!! \Mad\View\MadBlade::component(MeuComp::class, ['id' => 5]) !!}        │
 * │                                                                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Lifecycle (inspirado no Livewire 3) ─────────────────────────────────────┐
 * │                                                                           │
 * │  Primeira carga (show):                                                   │
 * │    boot() → mount($params) → rendering() → render() → rendered()         │
 * │    → dehydrate()                                                          │
 * │                                                                           │
 * │  Requisições AJAX (mad:click, mad:model):                                 │
 * │    boot() → hydrate() → updating/updated → action() →                    │
 * │    rendering() → render() → rendered() → dehydrate()                     │
 * │                                                                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Hooks disponíveis ────────────────────────────────────────────────────────┐
 * │                                                                           │
 * │  boot()                          Toda requisição (inicial e AJAX)         │
 * │  mount(array $params)            Apenas na primeira carga                 │
 * │  hydrate()                       Após restaurar estado (AJAX)             │
 * │  updating(prop, old, new)        Antes de prop mudar via mad:model        │
 * │  updatingProp(new, old)          Idem, por prop específica                │
 * │  updated(prop, value)            Após prop mudar via mad:model            │
 * │  updatedProp(value)              Idem, por prop específica                │
 * │  rendering()                     Antes de render()                        │
 * │  rendered(string $html)          Após render()                            │
 * │  dehydrate()                     Antes de serializar o estado             │
 * │  exception(Throwable, callable)  Captura exceções na action               │
 * │                                                                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * Diretivas disponíveis no Blade:
 *   mad:click="metodo"           → chama o método ao clicar
 *   mad:click="metodo(1, 'x')"   → chama com parâmetros
 *   mad:model="propriedade"      → two-way binding (sincroniza no próximo action)
 *   mad:model.live="propriedade" → two-way binding imediato (debounced)
 *   mad:submit="metodo"          → chama o método ao enviar o form
 *   mad:loading                  → visível apenas durante requisição
 *   mad:loading.remove           → escondido durante requisição
 */
abstract class MadComponent
{
    /**
     * Ações genéricas dos blocos de banco (`<mad-db-blocks>` e os presets
     * `<mad-comments>`/`<mad-attachments>`): blockAdd/blockEdit/blockRemove/
     * blockUpdate. Ficam na BASE de propósito — o Blade sozinho declara a
     * lista de filhos com formulário e o dev não escreve handler nenhum
     * (antes era preciso lembrar do `use MadDbBlocksTrait;` no controller,
     * exatamente o tipo de passo manual que se esquece).
     *
     * Segurança: toda ação exige o state CRIPTOGRAFADO que o componente
     * emitiu (model/FK/colunas viajam assinados — cliente não forja) e passa
     * pelo PermissionGate do wire, como qualquer outra action.
     *
     * Subclasses que já declaram `use MadDbBlocksTrait;` continuam válidas
     * (PHP permite a filha reusar trait do pai; a versão da filha vence).
     */
    use \Mad\Form\MadDbBlocksTrait;

    /** Tipos de wrapper de página. Use nas subclasses:
     *   protected static string $wrapper = self::MODAL;
     *
     * PADRÃO DE APRESENTAÇÃO: $wrapper declara a apresentação PADRÃO do
     * componente quando aberto standalone (MadAction::auto() lê via
     * getWrapper()). O CHAMADOR pode sobrescrever a apresentação por
     * invocação — `drawer` e `row` no <mad-nav> da grid — sem que o
     * componente precise mudar. No modo row-attach (form anexado à linha
     * da grid) o chrome drawer/modal é pulado e a view pode se adaptar
     * consultando $that->isRowAttach() (ex.: esconder abas, layout
     * compacto de edição rápida).
     */
    const INTERNAL = 'internal';
    const MODAL    = 'modal';
    const DRAWER   = 'drawer';

    /** Define como o componente é apresentado ao chamar show(). */
    protected static string $wrapper = self::MODAL;

    /** Título exibido no header do modal ou drawer. */
    protected static string $title = '';

    /** Tamanho do modal/drawer: sm | md | lg | xl — ou valor CSS direto: '800px', '75%', '60vw' */
    protected static string $size = 'lg';

    /** Lado do drawer: right | left */
    protected static string $side = 'right';

    /**
     * Bag de dados de formulário legado — armazena automaticamente qualquer campo
     * cujo `name` não corresponda a uma prop pública declarada pelo desenvolvedor.
     *
     * @deprecated Usar MadForm como propriedade pública. Ex: public MadForm $form;
     */
    public array $_fields = [];

    private string $_id    = '';
    private string $_class = '';

    /** Forward params persistidos no state para restaurar em MadWire requests. */
    protected array $_forwardParams = [];

    /**
     * Aberto em modo row-attach (anexado à linha da grid)? Detectado pelo
     * header X-Mad-Row-Attach no GET inicial e PERSISTIDO no state — os POSTs
     * do MadWire não reenviam o header, mas o re-render (ex.: erro de
     * validação) precisa manter a view no modo compacto.
     */
    protected bool $_rowAttach = false;

    /**
     * Combo que abriu esta tela ("Sem resultados → Cadastrar novo"), decriptado
     * do token assinado `_mad_origin`. Guardado como array porque PERSISTE NO
     * STATE — o POST do MadWire (o save) não reenvia a query string do GET
     * inicial, e é justamente no save que a origem precisa ser conhecida.
     * Exposto tipado por comboOrigin().
     *
     * @var array<string, string>
     */
    protected array $_comboOrigin = [];

    /**
     * Cache de nomes de props públicas por classe.
     * Evita Reflection repetida a cada render/getState.
     *
     * @var array<class-string, string[]>
     */
    private static array $_propCache = [];

    /** Indica que boot()+mount() já foram executados (evita dupla chamada). */
    private bool $_mounted = false;

    // ── Getters estáticos públicos (usados por MadComponentWrapper) ───────────

    public static function getWrapper(): string { return static::$wrapper; }
    public static function getTitle(): string   { return static::translatedTitle(static::$title); }
    public static function getSize(): string    { return static::$size; }
    public static function getSide(): string    { return static::$side; }

    /**
     * Título da tela no idioma do usuário. O título é gravado como texto no
     * idioma principal do projeto (`'Cadastro de cliente'`); o MadBuilder
     * exporta em `lang/{locale}.json` a tradução de cada texto cadastrado em
     * Traduções, então `__()` devolve o texto traduzido — ou o próprio texto
     * quando não há tradução. Chave `grupo.chave` também funciona.
     * Resultado não-string (texto que colide com nome de arquivo de `lang/`,
     * ex.: "Billing" devolveria o array do grupo) → o texto original.
     */
    protected static function translatedTitle(string $title): string
    {
        if ($title === '' || !function_exists('__')) {
            return $title;
        }
        try {
            $translated = __($title);
        } catch (\Throwable) {
            return $title;
        }
        return is_string($translated) && $translated !== '' ? $translated : $title;
    }

    // ── Lifecycle hooks — sobrescreva nas subclasses ──────────────────────────

    /**
     * Chamado no início de TODA requisição, antes de qualquer outro hook.
     * Use para setup compartilhado entre primeira carga e AJAX (ex: auth, DB).
     */
    public function boot(): void {}

    /**
     * Chamado uma única vez na primeira montagem do componente.
     * Use para inicializar propriedades com base nos parâmetros recebidos.
     *
     * Subclasses podem declarar o tipo que preferirem:
     *   mount(array $params = [])       — array associativo (compatibilidade)
     *   mount(MadRequest $request)      — objeto tipado com accessors
     *   mount()                         — sem parametros
     *
     * O _resolveAndCall() injeta o tipo correto automaticamente.
     */
    public function mount(): void {}

    /**
     * Chamado após restaurar o estado nas requisições AJAX (não na primeira carga).
     * Use para recarregar dados que não foram serializados no mad_state.
     */
    public function hydrate(): void {}

    /**
     * Chamado antes de uma prop ser atualizada via mad:model.
     *
     * @param string $prop  Nome da propriedade
     * @param mixed  $old   Valor atual
     * @param mixed  $new   Valor que será atribuído
     */
    public function updating(string $prop, mixed $old, mixed $new): void {}

    /**
     * Chamado após uma prop ser atualizada via mad:model.
     *
     * @param string $prop  Nome da propriedade
     * @param mixed  $value Novo valor (já atribuído)
     */
    public function updated(string $prop, mixed $value): void {}

    /**
     * Chamado imediatamente antes de render().
     * Use para preparar dados de última hora para a view.
     */
    public function rendering(): void {}

    /**
     * Chamado imediatamente após render(), recebe o HTML gerado.
     *
     * @param string $html HTML renderizado pelo Blade
     */
    public function rendered(string $html): void {}

    /**
     * Flag runtime para forçar full re-render na request atual.
     * Use `$this->forceFullRender()` dentro de uma action para ativar.
     */
    protected bool $_forceFullRender = false;

    /**
     * Força o MadComponentHandler a emitir HTML completo em vez de ops parciais
     * no final da action atual. Útil quando a mudança de estado afeta estrutura
     * (ex: troca de view mode que muda blocos inteiros do template).
     */
    public function forceFullRender(): void
    {
        $this->_forceFullRender = true;
    }

    /**
     * Retorna true se o componente carregou dados internos não-serializados
     * (ex: MadDataGrid após loadData) e precisa de re-render completo,
     * ignorando a otimização auto-bind do MadComponentHandler.
     *
     * @internal Consultado por MadComponentHandler::process()
     */
    public function _needsFullRender(): bool { return $this->_forceFullRender; }

    /**
     * Chamado antes de serializar o estado para o mad_state (dehydrate).
     * Use para limpar props pesadas que não precisam ser persistidas.
     */
    public function dehydrate(): void {}

    /**
     * Chamado quando uma exceção ocorre durante a execução de uma action.
     * Chame $stopPropagation() para tratar o erro sem relançar a exceção.
     *
     * @param \Throwable $e               Exceção lançada
     * @param callable   $stopPropagation Chame para impedir que a exceção suba
     */
    public function exception(\Throwable $e, callable $stopPropagation): void {}

    /**
     * Retorna o nome da view Blade a renderizar, opcionalmente com dados extras.
     *
     * Formas de uso:
     *   return 'components.contador';                              // só o nome
     *   return ['components.dashboard', ['chart' => $chartHtml]];  // nome + dados
     *
     * Dados retornados aqui NÃO são serializados no state — são recalculados
     * a cada render, ideal para HTML gerado, objetos pesados, etc.
     *
     * @return string|array{0: string, 1: array<string, mixed>}
     */
    abstract protected function view(): string|array;

    // ── Ciclo de vida — orquestração ──────────────────────────────────────────

    /**
     * Renderiza e exibe o componente respeitando o $wrapper definido na classe.
     *
     * @param array $params Parâmetros passados para mount()
     */
    public function show(array $params = []): void
    {
        try {
            if (static::_requestIsRowAttach()) {
                $this->_rowAttach = true;
            }

            // Merge $_REQUEST para garantir que mount() tenha acesso aos dados
            // do request independente do caminho de entrada (Mad.go, Mad.get, direto)
            $params = array_merge($_REQUEST, $params);

            // Extrai _forward_param_* do request e propaga via MadAction
            $forwardParams = [];
            $cleanParams   = [];
            foreach ($params as $k => $v) {
                if (str_starts_with($k, '_forward_param_')) {
                    $cleanKey = substr($k, strlen('_forward_param_'));
                    $forwardParams[$cleanKey] = $v;
                } else {
                    $cleanParams[$k] = $v;
                }
            }
            if (!empty($forwardParams)) {
                MadAction::setForwardParams($forwardParams);
                $this->_forwardParams = $forwardParams;
                // Merge forward params (sem prefixo) para o mount() ter acesso
                $cleanParams = array_merge($forwardParams, $cleanParams);
            }

            // Origem "Sem resultados → Cadastrar novo": só este caminho precisa
            // capturar. Os outros call sites de mount() (MadResponse::teleport,
            // MadBlade::component, MadSitePage) embutem o componente sem chrome
            // de overlay e sem a query string do Mad.go — não há combo para onde
            // voltar.
            $this->_captureComboOrigin($cleanParams);

            if (!$this->_mounted) {
                $this->boot();
                $this->_resolveAndCall('mount', $cleanParams);
                $this->_mounted = true;
            }

            $method = $cleanParams['method'] ?? null;
            if ($method && $method !== 'show' && is_callable([$this, $method])) {
                $result = $this->_resolveAndCall($method, $cleanParams);
                if ($result !== false) {
                    // Depois do método (create-action pode ser onNovo, não show):
                    // prefill não pode ser atropelado pelo que o método montou.
                    $this->_applyComboOriginPrefill($cleanParams);
                    echo $this->_renderWrapped();
                }
                return;
            }

            $this->_applyComboOriginPrefill($cleanParams);
            echo $this->_renderWrapped();

        } catch (\Throwable $e) {
            $this->_renderError($e);
        }
    }

    // ── Origem "Sem resultados → Cadastrar novo" ──────────────────────────────

    /**
     * Combo que abriu esta tela, ou null quando ela foi aberta normalmente.
     *
     *   if (($o = $this->comboOrigin()) && $o->field() === 'pais_id') { … }
     */
    public function comboOrigin(): ?MadComboOrigin
    {
        return $this->_comboOrigin === [] ? null : MadComboOrigin::fromArray($this->_comboOrigin);
    }

    /**
     * Esta tela foi aberta por um combo E tem como devolver a option?
     *
     * INTERNAL responde false de propósito: um formulário de página inteira
     * SUBSTITUI a tela de origem (Mad.go → _injectFull), então o <select> que
     * receberia a option já não está mais no DOM.
     */
    public function openedFromCombo(): bool
    {
        return $this->comboOrigin() !== null && static::$wrapper !== self::INTERNAL;
    }

    /**
     * Resposta que devolve o registro recém-salvo ao combo de origem: insere a
     * option, seleciona, avisa e fecha o overlay. Retorna **null** quando a tela
     * não veio de um combo — é essa a forma de usar:
     *
     *   if (($r = $this->returnToCombo($this->recordId)) !== null) {
     *       return $r;                      // veio de combo: volta pra lá
     *   }
     *   return (new MadResponse())->…;      // fluxo normal da tela
     *
     * @param int|string|null $id    Chave do registro salvo.
     * @param string          $label Rótulo da option. Vazio = resolvido sozinho.
     * @param string          $toast Mensagem de sucesso. '' = sem mensagem.
     */
    public function returnToCombo(
        int|string|null $id,
        string $label = '',
        string $toast = 'Registro cadastrado e selecionado.',
    ): ?MadResponse {
        $origin = $this->comboOrigin();
        if ($origin === null || ! $this->openedFromCombo() || $id === null || $id === '') {
            return null;
        }

        $response = (new MadResponse())
            ->addComboOption(
                $origin->field(),
                (string) $id,
                $label !== '' ? $label : $this->_resolveComboOriginLabel($origin, $id),
                true,
                $origin->component(),
            );

        if ($toast !== '') {
            $response->toast($toast, 'success');
        }

        return static::$wrapper === self::MODAL
            ? $response->closeModal()
            : $response->closeDrawer();
    }

    /**
     * Decripta o token assinado da origem. Sem token (ou token adulterado/de
     * outra instalação) simplesmente não há origem — nunca lança.
     *
     * @param array<string, mixed> $params
     */
    private function _captureComboOrigin(array $params): void
    {
        if (empty($params['_mad_origin'])) {
            return;
        }

        $payload = MadStateCrypt::decrypt((string) $params['_mad_origin']);
        if (! is_array($payload)) {
            return;
        }

        // `term` e `component` viajam FORA do token: mudam a cada abertura e não
        // são sensíveis (o term é o que o usuário digitou; o mad-id é público no
        // DOM). O que precisa de assinatura é model/database/field.
        $payload['term']      = (string) ($params['_term'] ?? '');
        $payload['component'] = (string) ($params['_mad_origin_cmp'] ?? '');

        $origin = MadComboOrigin::fromArray($payload);
        if ($origin !== null) {
            $this->_comboOrigin = $origin->toArray();
        }
    }

    /**
     * Pré-preenche o campo de exibição com o termo que o usuário digitou no
     * combo antes de não achar nada ("Testelândia" → `nome`).
     *
     * @param array<string, mixed> $params
     */
    private function _applyComboOriginPrefill(array $params): void
    {
        $origin = $this->comboOrigin();
        if ($origin === null || ! empty($params['id'])) {
            return; // edição não é sobrescrita pelo termo de busca
        }

        $display = $origin->display();
        $term    = $origin->term();
        if ($term === '' || $display === '' || $origin->isMask()) {
            return; // máscara (`{nome} — {sigla}`) não é nome de campo
        }

        $form = $this->_findMadForm();
        if ($form === null) {
            return;
        }

        if ((string) $form->get($display, '') === '') {
            $form->set($display, $term);
        }
    }

    /**
     * MadForm da tela. Descoberto por varredura porque `public MadForm $form;` é
     * CONVENÇÃO do gerador, não contrato: wizard, telas importadas e componentes
     * escritos à mão usam outros nomes — e a prop tipada fica *uninitialized*
     * até o mount() rodar, onde um acesso direto seria Error fatal.
     */
    private function _findMadForm(): ?MadForm
    {
        foreach (get_object_vars($this) as $value) {
            if ($value instanceof MadForm) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Rótulo da nova option, na ordem: valor do form (quando o display é uma
     * coluna) → mesmo caminho que montou as options do combo (cobre máscara e
     * display computado) → heurística de nome → a própria chave.
     *
     * Nunca propaga exceção: resolver rótulo não pode transformar um save
     * bem-sucedido em erro na tela.
     */
    private function _resolveComboOriginLabel(MadComboOrigin $origin, int|string $id): string
    {
        $display = $origin->display();
        $form    = $this->_findMadForm();

        if ($display !== '' && ! $origin->isMask() && $form !== null) {
            $value = (string) $form->get($display, '');
            if ($value !== '') {
                return $value;
            }
        }

        if ($display !== '' && $origin->model() !== '') {
            try {
                $class  = ModelOptionsLoader::resolveModelClass($origin->model());
                $record = $class::query()->where($origin->key(), $id)->first();
                if ($record !== null) {
                    $label = $origin->isMask()
                        ? ModelOptionsLoader::mask($record, $display)
                        : (string) ($record->{$display} ?? '');
                    if ($label !== '') {
                        return $label;
                    }
                }
            } catch (\Throwable) {
                // segue para a heurística
            }
        }

        if ($form !== null) {
            foreach (['nome', 'name', 'descricao', 'description', 'titulo', 'title', 'razao_social'] as $guess) {
                $value = (string) $form->get($guess, '');
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return (string) $id;
    }

    /**
     * Renderiza o erro de forma rica (Ignition se disponível, senão MadErrorRenderer).
     * Captura internamente para evitar que o renderizador de exceções legado intercepte.
     *
     * @internal
     */
    protected function _renderError(\Throwable $e): void
    {
        // PRODUÇÃO (app.debug desligado): o usuário final recebe a tela mínima
        // traduzida com um código de erro, e o detalhe vai para o log.
        //
        // Sem esta guarda, TODA exceção não tratada de QUALQUER control MAD
        // imprimia caminho de arquivo, TRECHO DO CÓDIGO FONTE e stack trace na
        // cara de quem usa o app publicado — bastava um favorito velho de uma
        // tela que exige parâmetro (pego no navegador em 18/09/2026, com
        // `APP_DEBUG=false`).
        //
        // Com debug LIGADO nada muda: a tela rica (Ignition/MadErrorRenderer)
        // continua idêntica, que é o que o desenvolvedor precisa.
        if (! MadErrorPage::debugEnabled()) {
            $errorId = MadErrorPage::errorId($e);
            MadErrorPage::report($e, $errorId, ['component' => static::class]);
            echo MadErrorPage::internal($errorId);

            return;
        }

        if (class_exists(\Spatie\Ignition\Ignition::class)) {
            try {
                $ignition = \Spatie\Ignition\Ignition::make()
                    ->applicationPath(defined('PATH') ? PATH : dirname(__DIR__, 3))
                    ->setTheme('dark');

                ob_start();
                $ignition->renderException($e);
                $ignitionHtml = ob_get_clean();

                if ($ignitionHtml) {
                    $class = htmlspecialchars(static::class, ENT_QUOTES);

                    // Remove branding Flare/Laravel/Ignition
                    $ignitionHtml = preg_replace('/<a[^>]*flare\b[^>]*>.*?<\/a>/si', '', $ignitionHtml);
                    $ignitionHtml = preg_replace('/<a[^>]*laravel[^>]*>.*?<\/a>/si', '', $ignitionHtml);
                    $ignitionHtml = preg_replace('/<a[^>]*ignition[^>]*>.*?<\/a>/si', '', $ignitionHtml);
                    $ignitionHtml = preg_replace('/<a[^>]*spatie[^>]*>.*?<\/a>/si', '', $ignitionHtml);
                    $ignitionHtml = preg_replace('/<section[^>]*>\s*<a\s+id=["\']footer["\'][^>]*>.*?<\/section>/si', '', $ignitionHtml);

                    // CSS: esconder branding restante + impedir links de navegar o parent
                    $extraCSS = '<style>'
                        . '#footer,footer,[class*="flare"],[class*="Flare"],[data-flare],'
                        . '[class*="sponsor"],[class*="Sponsor"],'
                        . 'a[href*="flareapp"],a[href*="laravel.com"],a[href*="spatie.be"],'
                        . 'a[href*="github.com/spatie"]'
                        . '{display:none!important;}'
                        . '</style>';
                    $ignitionHtml = str_replace('</head>', $extraCSS . '</head>', $ignitionHtml);
                    $encoded = htmlspecialchars($ignitionHtml, ENT_QUOTES);

                    $mdEncoded = htmlspecialchars(
                        MadErrorRenderer::toMarkdown($e, static::class),
                        ENT_QUOTES
                    );

                    echo <<<HTML
                    <div onclick="if(event.target===this)this.remove()" style="position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;">
                        <div style="width:95%;height:95%;background:#1e1e2e;border-radius:12px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.5);display:flex;flex-direction:column;">
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 16px;background:#313244;color:#cdd6f4;font-family:-apple-system,sans-serif;font-size:13px;">
                                <span>Erro em <strong>{$class}</strong></span>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <button data-md="{$mdEncoded}" onclick="
                                        var ta=document.createElement('textarea');
                                        ta.value=this.getAttribute('data-md');
                                        ta.style.cssText='position:fixed;left:-9999px';
                                        document.body.appendChild(ta);
                                        ta.select();
                                        document.execCommand('copy');
                                        ta.remove();
                                        this.textContent='Copiado!';
                                        var b=this;setTimeout(function(){b.textContent='Copiar MD';},2000);
                                    " style="background:#45475a;border:none;color:#a6e3a1;font-size:12px;cursor:pointer;padding:4px 10px;border-radius:4px;">Copiar MD</button>
                                    <button onclick="this.closest('[onclick]').remove()" style="background:none;border:none;color:#f38ba8;font-size:20px;cursor:pointer;padding:0 4px;">&times;</button>
                                </div>
                            </div>
                            <iframe srcdoc="{$encoded}" sandbox="allow-scripts allow-same-origin" style="flex:1;border:none;width:100%;"></iframe>
                        </div>
                    </div>
                    HTML;
                    return;
                }
            } catch (\Throwable $_) {
                // Fallback silencioso se Ignition falhar
            }
        }
        echo MadErrorRenderer::render($e, static::class);
    }

    /**
     * Renderiza o componente como HTML puro (sem wrapper de modal/drawer).
     * Dispara rendering() antes e rendered() depois.
     */
    public function render(): string
    {
        $this->rendering();

        // Resolve a view: string pura ou [string, array]
        $viewResult = $this->view();
        $viewName   = is_array($viewResult) ? $viewResult[0] : $viewResult;
        $viewData   = is_array($viewResult) ? $viewResult[1] : [];

        // Props serializadas (MadForm→toArray) para state
        $props = $this->_publicProps();

        // View recebe o objeto real (não serializado): MadForm para $form->get()
        // funcionar e Model para {{ $registro->titulo }} / relações continuarem
        // valendo (o envelope de state é detalhe de transporte, não da view).
        foreach ($this->_getPublicPropNames() as $name) {
            if (!$this->_isInitialized($name)) continue;
            if ($this->$name instanceof MadForm || $this->$name instanceof Model) {
                $props[$name] = $this->$name;
            }
        }

        // Props para contexto: MadForm→fields (permite flattening correto de campos)
        $propsForCtx = $this->_publicPropsForContext();
        $context     = MadRenderContext::push($propsForCtx, $this);
        $mad         = MadRenderContext::madHelper($context);

        $html = MadBlade::render(
            $viewName,
            array_merge($props, $viewData, $context, ['_component' => $this, '__component' => $this, 'that' => $this, 'mad' => $mad])
        );

        MadRenderContext::pop();

        $this->rendered($html);

        return $html;
    }

    /**
     * Popula propriedades públicas a partir de um array (ex: model->toArray()).
     * Preserva o tipo declarado na propriedade.
     *
     * Suporta chaves com ponto para MadForm: "form.campo" → $this->form->fields['campo']
     */
    public function fill(array $data): void
    {
        foreach ($data as $key => $value) {
            // Suporte a chave pontilhada: "nomeForm.campo" → MadForm->fields['campo']
            if (str_contains($key, '.')) {
                [$formName, $field] = explode('.', $key, 2);
                if ($this->_isPublicProp($formName) && $this->$formName instanceof MadForm) {
                    $this->$formName->fields[$field] = $value;
                    continue;
                }
            }

            if ($this->_isPublicProp($key)) {
                $ref      = new \ReflectionProperty($this, $key);
                $original = $ref->isInitialized($this) ? $this->$key : null;

                // Prop array + valor string: o wire serializa <select multiple>
                // e afins como JSON ('["1","2"]'); '' = seleção vazia. Sem isso
                // o settype abaixo embrulharia a string crua num array de 1 item.
                if (is_array($original) && is_string($value)) {
                    $trim = trim($value);
                    if ($trim === '') {
                        $this->$key = [];
                        continue;
                    }
                    if ($trim[0] === '[') {
                        $decoded = json_decode($trim, true);
                        if (is_array($decoded)) {
                            $this->$key = array_values(array_filter(
                                array_map(fn ($v) => is_scalar($v) ? (string) $v : $v, $decoded),
                                fn ($v) => $v !== '' && $v !== null
                            ));
                            continue;
                        }
                    }
                }

                if ($original !== null && !is_object($value)) {
                    settype($value, gettype($original));
                }

                // Mesma trava do _setState: array vindo de um model->toArray()
                // não pode estourar TypeError numa prop tipada de classe.
                if (!self::_valueFitsProp($ref, $value)) {
                    self::_warnState($key, sprintf(
                        'fill() recebeu %s incompatível com %s — ignorado',
                        get_debug_type($value),
                        (string) $ref->getType(),
                    ));
                    continue;
                }

                $this->$key = $value;
            } else {
                $this->_setInArrayProp($key, $value);
            }
        }
    }

    /**
     * Tenta definir $key dentro de alguma prop pública do tipo array ou MadForm.
     *
     * ⚠️ Prop de lista do FRAMEWORK nunca é destino (`_isProtectedArrayProp`).
     * A busca é "primeira prop array que já tem essa chave", e as do framework
     * carregam config/allowlist: `mad_model[0][field]=is_admin` reescrevia
     * `exportColConfigs[0]` (a allowlist do `onInlineSave` → gravar qualquer
     * coluna) e `mad_model[model]=User` reescrevia o `gridConfig['model']` do
     * MadGrid (listar outra tabela). O bloqueio por NOME do
     * `_isModelAssignable` não pega isso: a chave que chega é `0`/`model`, não
     * o nome da prop.
     */
    private function _setInArrayProp(string $key, mixed $value): void
    {
        $firstForm = null;

        foreach ($this->_getPublicPropNames() as $propName) {
            if ($propName === '_fields') continue;

            $ref = new \ReflectionProperty($this, $propName);
            if (!$ref->isInitialized($this)) continue;

            $propValue = $ref->getValue($this);

            if ($propValue instanceof MadForm) {
                if ($firstForm === null) {
                    $firstForm = $propValue;
                }
                if (array_key_exists($key, $propValue->fields)) {
                    $propValue->fields[$key] = $value;
                    return;
                }
                continue;
            }

            if (is_array($propValue) && array_key_exists($key, $propValue)) {
                if ($this->_isProtectedArrayProp($propName)) {
                    continue;
                }
                $original = $propValue[$key];
                settype($value, gettype($original));
                $propValue[$key] = $value;
                $ref->setValue($this, $propValue);
                return;
            }
        }

        if ($firstForm !== null) {
            $firstForm->fields[$key] = $value;
            return;
        }

        $this->_fields[$key] = $value;
    }

    /**
     * Prop de lista que o `_setInArrayProp` não pode usar como destino:
     *
     *  - declarada por uma classe do framework (namespace `Mad\`) em qualquer
     *    ponto da herança — é estado/config do componente (grid, seek, filtro
     *    avançado), preenchido por estado assinado ou por handler, nunca por
     *    campo digitado. Nenhum template do framework liga `mad:model` a elas;
     *  - com nome estrutural ou sensível (mesmas listas do
     *    `_isModelAssignable`), que cobre a subclasse que redeclara a prop.
     *
     * Array declarado pela TELA (`public array $dados = ['nome' => '']`, o
     * padrão legado de formulário sem MadForm) continua sendo destino.
     */
    private function _isProtectedArrayProp(string $propName): bool
    {
        $lower = strtolower($propName);
        if (in_array($lower, self::_MODEL_STRUCTURAL_BLOCKED, true)
            || in_array($lower, self::_MODEL_BLOCKED_PROPS, true)) {
            return true;
        }

        for ($class = static::class; $class !== false; $class = get_parent_class($class)) {
            // Classe anônima herda o nome do pai (`Mad\…\MadComponent@anonymous`):
            // não é do framework, é a tela.
            if (str_contains($class, '@anonymous')) {
                continue;
            }
            if (str_starts_with($class, 'Mad\\') && property_exists($class, $propName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Injeta dados de field-lists diretamente no primeiro MadForm do componente.
     * Chamado pelo MadComponentHandler ao receber mad_field_lists do AJAX.
     */
    public function _setFieldListData(array $flData): void
    {
        foreach ($this->_getPublicPropNames() as $name) {
            if (!$this->_isInitialized($name)) continue;
            if ($this->$name instanceof MadForm) {
                foreach ($flData as $flName => $rows) {
                    $this->$name->fields[$flName] = $rows;
                }
                return;
            }
        }
    }

    /**
     * Renderiza o componente envolvido no wrapper que o mad-livewire.js precisa.
     * Chama dehydrate() antes de serializar o estado.
     */
    public function _renderWrapped(): string
    {
        return $this->_wrapRenderedHtml($this->render());
    }

    /**
     * Envolve HTML já renderizado com o wrapper do mad-livewire.js.
     * Chama dehydrate() e criptografa o estado.
     *
     * @internal Usado por _renderWrapped() e MadComponentHandler (reuso de render).
     */
    public function _wrapRenderedHtml(string $html): string
    {
        $this->dehydrate();

        $state = $this->_encryptState();
        $id    = $this->_id ?: ('mc_' . bin2hex(random_bytes(6)));
        $this->_id = $id;

        $class    = $this->_wireClassName();
        $endpoint = $this->_wireEndpoint($class);

        $component = sprintf(
            '<div mad-component="%s" mad-id="%s" mad-state="%s" mad-endpoint="%s">%s</div>',
            htmlspecialchars($class,    ENT_QUOTES),
            htmlspecialchars($id,       ENT_QUOTES),
            htmlspecialchars($state,    ENT_QUOTES),
            htmlspecialchars($endpoint, ENT_QUOTES),
            $html
        );

        // Row-attach (quick-edit anexado à linha da grid): o client injeta o
        // componente cru dentro da <tr> — o chrome drawer/modal não se aplica.
        // Checa o header além da flag: _renderWrapped pode rodar sem show()
        // (ex.: MadResponse::teleport).
        $isRowAttach = $this->_rowAttach || static::_requestIsRowAttach();

        if (!empty($_POST['mad_state']) || static::$wrapper === self::INTERNAL || $isRowAttach) {
            return $component;
        }

        return MadComponentWrapper::wrap($this, $component);
    }

    // ── Gerenciamento de estado ───────────────────────────────────────────────

    /** Retorna todas as propriedades públicas de instância como array. */
    public function _getState(): array
    {
        $state = $this->_publicProps();
        if (!empty($this->_forwardParams)) {
            $state['_forwardParams'] = $this->_forwardParams;
        }
        if ($this->_rowAttach) {
            $state['_rowAttach'] = true;
        }
        // Origem do combo: o POST do MadWire (o save) não reenvia a query string
        // do GET que abriu o drawer — sem isto, returnToCombo() sempre veria null.
        if ($this->_comboOrigin !== []) {
            $state['_comboOrigin'] = $this->_comboOrigin;
        }
        return $state;
    }

    /**
     * Restaura propriedades públicas a partir de um array.
     * Reconstitui MadForm a partir de arrays serializados.
     */
    public function _setState(array $state): void
    {
        // Restaura forward params internos (não são props públicas)
        if (isset($state['_forwardParams']) && is_array($state['_forwardParams'])) {
            $this->_forwardParams = $state['_forwardParams'];
            unset($state['_forwardParams']);
        }

        // Restaura modo row-attach (interno, sobrevive aos POSTs do wire)
        if (!empty($state['_rowAttach'])) {
            $this->_rowAttach = true;
            unset($state['_rowAttach']);
        }

        // Restaura a origem do combo (interna, sobrevive aos POSTs do wire)
        if (isset($state['_comboOrigin']) && is_array($state['_comboOrigin'])) {
            $this->_comboOrigin = $state['_comboOrigin'];
            unset($state['_comboOrigin']);
        }

        foreach ($state as $key => $value) {
            if (!$this->_isPublicProp($key)) continue;

            $ref     = new \ReflectionProperty($this, $key);
            $type    = $ref->getType();
            $current = $ref->isInitialized($this) ? $this->$key : null;

            // MadForm: tipo declarado OU instância atual
            $isFormProp = ($type instanceof \ReflectionNamedType && $type->getName() === MadForm::class)
                || $current instanceof MadForm;
            if ($isFormProp && is_array($value)) {
                $this->$key = MadForm::fromArray($value);
                continue;
            }

            // Model Eloquent serializado por _publicProps() → reidrata a
            // instância. Sem isso o array cru voltava pra uma prop tipada
            // (`public ?Tickets $registro`) e o PHP matava a request com
            // TypeError — 500 em HTML no meio do ciclo wire.
            if (self::_isModelStateEnvelope($value)) {
                $model = self::_modelFromState($value);
                if ($model === null) {
                    self::_warnState($key, 'envelope de Model inválido no state');
                    continue;
                }
                $value = $model;
            }

            // Cast pelo valor ATUAL (comportamento histórico). Não mexe quando o
            // novo valor já é objeto (Model reidratado) — settype o destruiria.
            if ($current !== null && !is_object($value)) {
                settype($value, gettype($current));
            }

            // Última trava: state antigo/incompatível não pode derrubar a request.
            if (!self::_valueFitsProp($ref, $value)) {
                self::_warnState($key, sprintf(
                    'valor %s incompatível com o tipo declarado %s — ignorado',
                    get_debug_type($value),
                    (string) $type,
                ));
                continue;
            }

            $this->$key = $value;
        }
    }

    // ── Model Eloquent no state (serialização/reidratação) ───────────────────

    /** Chave que marca um Model serializado dentro do state. */
    private const _MODEL_ENVELOPE_KEY = '__mad_model';

    /**
     * Teto de relações reidratadas por Model. Cada relação vira UMA query no
     * load() da volta; um Model com dezenas de relações carregadas explodiria o
     * custo por POST. Além do teto, o excedente é ignorado com aviso.
     */
    private const _MAX_STATE_RELATIONS = 20;

    /**
     * Model → array serializável no state (o state vira JSON criptografado).
     *
     * Guarda os atributos CRUS (getAttributes) — round-trip exato com
     * setRawAttributes, sem casts aplicados duas vezes.
     *
     * Relações: só os NOMES das já carregadas (top-level) viajam — nunca os
     * dados. Na volta, _modelFromState faz UM load() em lote (1 query por
     * relação, não N+1) para reidratá-las. Sem isso, tocar em `$reg->itens` no
     * re-render dispararia lazy-load — ou, com lazy-loading proibido
     * (preventLazyLoading), um LazyLoadingViolationException. Aninhadas
     * (`itens.produto`) NÃO round-trip: só o nível de topo.
     */
    private static function _modelToState(Model $model): array
    {
        $envelope = [
            self::_MODEL_ENVELOPE_KEY => get_class($model),
            'attributes'              => $model->getAttributes(),
            'exists'                  => $model->exists,
            'connection'              => $model->getConnectionName(),
        ];

        $relations = array_keys($model->getRelations());
        if ($relations !== []) {
            $envelope['relations'] = array_values($relations);
        }

        return $envelope;
    }

    /**
     * O valor é um Model serializado por _modelToState()?
     *
     * @internal Público só para o MadForm/handler distinguirem envelope de
     *           array de dados ao achatar/diferenciar o state.
     */
    public static function _isModelStateEnvelope(mixed $value): bool
    {
        return is_array($value)
            && isset($value[self::_MODEL_ENVELOPE_KEY])
            && is_string($value[self::_MODEL_ENVELOPE_KEY]);
    }

    /**
     * Envelope → instância de Model. Retorna null se a classe não existir mais
     * ou não for Model (state velho / adulteração — o state é cifrado, isto é
     * defesa em profundidade).
     */
    private static function _modelFromState(array $envelope): ?Model
    {
        $class = (string) ($envelope[self::_MODEL_ENVELOPE_KEY] ?? '');

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class();

        $connection = $envelope['connection'] ?? null;
        if (is_string($connection) && $connection !== '') {
            $model->setConnection($connection);
        }

        $attributes = $envelope['attributes'] ?? [];
        $model->setRawAttributes(is_array($attributes) ? $attributes : [], true);
        $model->exists = !empty($envelope['exists']);

        $relations = $envelope['relations'] ?? [];
        if (is_array($relations) && $relations !== [] && $model->exists && $model->getKey() !== null) {
            self::_reloadStateRelations($model, $relations);
        }

        return $model;
    }

    /**
     * Reidrata as relações nomeadas via UM load() em lote (1 query por relação,
     * NUNCA N+1 — a query é por-relação, não por-linha).
     *
     * Guardas:
     *   • relação sumiu do Model (renome/remoção entre deploys) → pula + avisa;
     *   • nome que não resolve para uma Relation Eloquent (método público que
     *     não é relação) → pula + avisa. Os nomes vêm de getRelations() e o
     *     state é cifrado (cliente não forja), mas revalidamos por robustez;
     *   • acima do teto (_MAX_STATE_RELATIONS) → carrega até o teto, avisa o resto.
     *
     * @param string[] $names
     */
    private static function _reloadStateRelations(Model $model, array $names): void
    {
        $valid = [];

        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (!method_exists($model, $name)) {
                self::_warnState($name, 'relação sumiu do Model — não reidratada');
                continue;
            }

            // Resolver a relação NÃO executa query (só monta o builder) — validação barata.
            try {
                $relation = $model->{$name}();
            } catch (\Throwable $e) {
                self::_warnState($name, 'não resolve para relação: ' . $e->getMessage());
                continue;
            }

            if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                self::_warnState($name, 'método não é relação Eloquent — ignorado');
                continue;
            }

            $valid[] = $name;
        }

        if ($valid === []) {
            return;
        }

        if (count($valid) > self::_MAX_STATE_RELATIONS) {
            $dropped = array_slice($valid, self::_MAX_STATE_RELATIONS);
            $valid   = array_slice($valid, 0, self::_MAX_STATE_RELATIONS);
            self::_warnState(
                implode(',', $dropped),
                'relações além do teto de ' . self::_MAX_STATE_RELATIONS . ' não reidratadas',
            );
        }

        try {
            $model->load($valid);
        } catch (\Throwable $e) {
            // load() nunca pode derrubar o ciclo wire — o pior caso é a relação
            // ficar não-carregada (lazy-load no acesso, comportamento pré-fix).
            self::_warnState(implode(',', $valid), 'load() falhou: ' . $e->getMessage());
        }
    }

    /**
     * O valor cabe no tipo declarado da propriedade? Só bloqueia o que o PHP
     * mataria com TypeError (tipo de classe recebendo não-instância); tipos
     * builtin/união/sem tipo continuam passando (settype já cuidou).
     */
    private static function _valueFitsProp(\ReflectionProperty $ref, mixed $value): bool
    {
        $type = $ref->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return true;
        }

        if ($value === null) {
            return $type->allowsNull();
        }

        $name = $type->getName();

        return $name === 'object' || $value instanceof $name;
    }

    /** Aviso de state descartado (nunca derruba a request). */
    private static function _warnState(string $prop, string $why): void
    {
        $msg = sprintf('[MadComponent] state da prop "%s" descartado: %s', $prop, $why);

        if (function_exists('logger')) {
            logger()->warning($msg);
            return;
        }

        error_log($msg);
    }

    /**
     * Lista negra de props sensiveis que NUNCA podem ser modificadas via
     * mad_model do cliente. Defesa contra mass-assignment quando o componente
     * declara props publicas com nomes equivalentes a flags de seguranca.
     *
     * Componentes podem expandir/contrair isso sobrescrevendo
     * _isModelAssignable() com logica adicional especifica.
     */
    private const _MODEL_BLOCKED_PROPS = [
        // Credenciais
        'password', 'password_hash', 'password_confirm', 'password_confirmation',
        'pwd', 'pass', 'senha', 'senha_hash',
        // Privilegios
        'permissions', 'permission', 'role', 'roles',
        'is_admin', 'is_root', 'is_superadmin', 'isadmin', 'isroot',
        'admin', 'superadmin', 'root', 'super_admin',
        // Audit / state-machine
        'created_by', 'updated_by', 'deleted_by', 'deleted_at',
        // Tenant / scoping (system_user_id = alias legado p/ apps pré-rename)
        'user_id', 'system_user_id', 'tenant_id',
        // Token interno do framework
        '_token', 'csrf_token',
    ];

    /**
     * Props ESTRUTURAIS do framework (grid/seek/config) que carregam
     * template SQL / configuração de query. NUNCA são canal legítimo de
     * mad:model — são populadas por estado assinado (mad_state) ou por ações
     * server-side que decifram token (onColFilter). Bloquear a escrita direta
     * via mad_model fecha o vetor de SQLi por mass-assignment (colFilters[sub]
     * / gridConfig[query_sql] → whereRaw/fromRaw). Ver PermissionGate/wire.
     */
    private const _MODEL_STRUCTURAL_BLOCKED = [
        'colfilters', 'gridconfig', 'filters', 'filterops', 'filtervalues',
        'exportcolconfigs', 'colconfigs', 'columns', 'query_sql', 'querysql',
        'sub', 'criteria', 'joins', 'rawwhere', 'rawselect', 'orderby',
        // Config do grid que decide COLUNA consultada/exportada ou tela aberta.
        // Assignáveis, viravam oráculo: `searchColumns[]=senha` ou
        // `baseFilters=[['senha','like','a%']]` + contagem de linhas revelam o
        // valor; `exportGroupConfig` agrupava o PDF por qualquer coluna.
        'searchcolumns', 'basefilters', 'baseorder', 'exportmeta', 'exportgroupconfig',
        'bulkactions', 'requirefilterfields',
        // Filtros obrigatórios do bloco de filtros (MadFiltersTrait): config
        // do Blade, não campo digitado — zerá-la pulava a trava de busca.
        'requiredfilters',
        // Filtro avançado do grid (MadGridCustomFilters): defs = allowlist de
        // colunas, state = regras (só mudam pelos handlers onCustomFilter*).
        'customfilterdefs', 'customfilterstate', 'customfilterconfig', 'customfilterseal',
    ];

    /**
     * Decide se uma chave de mad_model pode ser aplicada ao componente.
     *
     * Camadas de defesa (override em subclasse para customizar):
     *
     *   1) Recusa chaves comecadas com `_` (estado interno do framework).
     *
     *   2) Para PROPS PUBLICAS: aplica blacklist _MODEL_BLOCKED_PROPS
     *      (impede override direto de flags sensiveis como `is_admin`,
     *      `role`, `password` quando o componente declarou prop com nome
     *      colidente).
     *
     *   3) Para PROPS PUBLICAS: nao deixa sobrescrever objetos (ex: MadForm)
     *      com escalar do cliente.
     *
     *   4) Para CHAVES NAO-PUBLICAS (form fields que vao parar em
     *      $this->form->fields via _setInArrayProp): SEMPRE permitido.
     *      A defesa nesse fluxo fica em camada inferior — o model deve usar
     *      addAttribute() para whitelist de colunas em models sensiveis.
     *      Sem essa permissao o login/forms quebram (ex: `password`,
     *      `senha`, `login` sao nomes legitimos de campo).
     */
    protected function _isModelAssignable(string $prop): bool
    {
        if ($prop === '' || $prop[0] === '_') {
            return false;
        }

        // Chaves pontilhadas "form.field" — sempre permitido (delegado ao MadForm).
        if (str_contains($prop, '.')) {
            return true;
        }

        // Props estruturais (grid/seek/config) NUNCA são assignáveis via cliente —
        // fecham o vetor de SQLi por mass-assignment (colFilters/gridConfig →
        // whereRaw/fromRaw). Bloqueio precede a divisão público/não-público.
        if (in_array(strtolower($prop), self::_MODEL_STRUCTURAL_BLOCKED, true)) {
            return false;
        }

        // Caso 1: prop publica do componente — aplica regras estritas.
        if ($this->_isPublicProp($prop)) {
            if (in_array(strtolower($prop), self::_MODEL_BLOCKED_PROPS, true)) {
                return false;
            }
            if ($this->_isInitialized($prop) && is_object($this->$prop)) {
                return false;
            }
            return true;
        }

        // Caso 2: nao eh prop publica — vai para MadForm->fields via
        // _setInArrayProp. Permitido (defesa de mass-assignment ocorre no
        // nivel de fillRecord/adição de atributo no record legado).
        return true;
    }

    /**
     * Aplica valores vindos de mad:model (digitados pelo usuário).
     * Dispara hooks updating/updatingProp antes e updated/updatedProp depois.
     *
     * Filtra mass-assignment via _isModelAssignable() antes de tocar nas props.
     */
    public function _applyModelValues(array $values): void
    {
        // Filtra props nao-assignaveis (mass-assignment defense).
        $filtered = [];
        foreach ($values as $prop => $newValue) {
            if ($this->_isModelAssignable((string) $prop)) {
                $filtered[$prop] = $newValue;
            }
        }
        $values = $filtered;

        // ── Fase 1: hooks updating (antes de alterar) ─────────────────────────
        foreach ($values as $prop => $newValue) {
            if (!$this->_isPublicProp($prop)) continue;

            $old = $this->_isInitialized($prop) ? $this->$prop : null;

            $this->updating($prop, $old, $newValue);

            $specific = 'updating' . ucfirst($prop);
            if (method_exists($this, $specific)) {
                $this->$specific($newValue, $old);
            }
        }

        // ── Fase 2: aplica os valores ─────────────────────────────────────────
        $this->fill($values);

        // ── Fase 3: hooks updated (após alterar) ──────────────────────────────
        foreach ($values as $prop => $_) {
            if (!$this->_isPublicProp($prop)) continue;

            $current = $this->_isInitialized($prop) ? $this->$prop : null;

            $this->updated($prop, $current);

            $specific = 'updated' . ucfirst($prop);
            if (method_exists($this, $specific)) {
                $this->$specific($current);
            }
        }
    }

    public function _setId(string $id): void       { $this->_id = $id; }
    public function _getId(): string               { return $this->_id; }
    public function _getForwardParams(): array     { return $this->_forwardParams; }

    /**
     * O registro que esta tela tem aberto — ou null quando é um cadastro novo.
     *
     * Serve a quem precisa distinguir "incluir" de "editar" sem olhar o corpo
     * da tela: a permissão de "Salvar" (ver Mad\Security\PermissionGate) e o
     * auto-load das pivots (MadRenderContext::loadPivotSelected).
     *
     * A escada existe porque três gerações de tela nomearam a mesma coisa de
     * três jeitos: `registroId` (legado), `recordId` (forms gerados) e `id`.
     * Se nenhuma delas estiver preenchida, sobra o registro que o `fill()` pôs
     * no MadForm — o caminho do `onEdit`, que carrega o registro sem escrever
     * o id numa prop.
     *
     * Sobrescreva numa tela cujo "registro aberto" não caiba nessa escada.
     */
    public function _recordId(): int|string|null
    {
        foreach (['registroId', 'recordId', 'id'] as $prop) {
            if (!$this->_isInitialized($prop)) {
                continue;
            }
            $valor = $this->$prop;
            if (empty($valor)) {
                continue;
            }
            if (is_int($valor) || is_string($valor)) {
                return $valor;
            }
        }

        $record = $this->_primaryForm()?->getSourceRecord();
        if ($record && method_exists($record, 'getKey')) {
            $chave = $record->getKey();
            if (is_int($chave) || is_string($chave)) {
                return $chave;
            }
        }

        return null;
    }

    /** Primeiro MadForm público do componente (o form "da tela"). */
    private function _primaryForm(): ?MadForm
    {
        foreach ($this->_getPublicPropNames() as $name) {
            if (!$this->_isInitialized($name)) continue;
            if ($this->$name instanceof MadForm) {
                return $this->$name;
            }
        }

        return null;
    }

    /**
     * O componente está aberto em modo row-attach (anexado à linha da grid)?
     * Use na view para adaptar o layout (esconder abas, versão compacta):
     *   @if($that->isRowAttach()) ... @endif
     * Válido no GET inicial E nos re-renders do MadWire (flag vive no state).
     */
    public function isRowAttach(): bool
    {
        return $this->_rowAttach;
    }

    /** O request atual carrega o header X-Mad-Row-Attach? */
    protected static function _requestIsRowAttach(): bool
    {
        return (function_exists('request') && request()->hasHeader('X-Mad-Row-Attach'))
            || !empty($_SERVER['HTTP_X_MAD_ROW_ATTACH']);
    }

    /**
     * Entry-point estático chamado pelo mad-livewire.js via rota wire (/app/_mad-wire).
     */
    public static function wire(): never
    {
        MadComponentHandler::handle();
    }

    /**
     * Executa o hook exception() do componente.
     * Retorna true se $stopPropagation foi chamado (erro tratado pelo componente).
     *
     * @internal Chamado por MadComponentHandler::process()
     */
    public function _callExceptionHook(\Throwable $e): bool
    {
        $stopped = false;
        $this->exception($e, function () use (&$stopped) { $stopped = true; });
        return $stopped;
    }

    // ── Identidade da classe ─────────────────────────────────────────────────

    /**
     * Nome da classe usado no endpoint wire e no estado criptografado.
     * Subclasses em namespace (ex: Mad\Seek\MadSeekGrid) podem sobrescrever
     * para retornar o nome curto registrado no mapa de classes legado.
     */
    protected function _wireClassName(): string
    {
        return get_class($this);
    }

    /**
     * URL do endpoint reativo (mad-endpoint do wrapper): /app/_mad-wire (rota
     * do web.php com AuthAdminMiddleware + checagem de ação no controller).
     * Subclasses podem sobrescrever para apontar a outro handler — ex.: um
     * instalador standalone que processa o wire no próprio entrypoint
     * (install.php).
     */
    protected function _wireEndpoint(string $class): string
    {
        return \Mad\Routing\RoutingDriver::wireEndpoint();
    }

    // ── Criptografia ──────────────────────────────────────────────────────────

    /** Serializa e criptografa o estado atual para enviar ao cliente. */
    public function _encryptState(): string
    {
        return MadStateCrypt::encrypt([
            'class' => $this->_wireClassName(),
            'state' => $this->_getState(),
        ]);
    }

    /** Descriptografa o token enviado pelo cliente. Retorna null se inválido. */
    public static function _decryptState(string $token): ?array
    {
        return MadStateCrypt::decrypt($token);
    }

    // ── Reflexão (com cache) ──────────────────────────────────────────────────

    /**
     * Retorna os nomes das propriedades públicas de instância da classe.
     * Resultado é cacheado por classe — Reflection roda apenas uma vez.
     *
     * @return string[]
     */
    private function _getPublicPropNames(): array
    {
        $class = static::class;

        if (!isset(self::$_propCache[$class])) {
            $ref   = new \ReflectionClass($this);
            $names = [];

            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isStatic()) continue;
                $names[] = $prop->getName();
            }

            self::$_propCache[$class] = $names;
        }

        return self::$_propCache[$class];
    }

    /**
     * Retorna apenas as propriedades públicas de instância como array.
     * MadForm é serializado via toArray(); Model Eloquent vira envelope
     * (_modelToState) para voltar como instância no _setState do próximo POST.
     */
    private function _publicProps(): array
    {
        $props = [];

        foreach ($this->_getPublicPropNames() as $name) {
            if (!(new \ReflectionProperty($this, $name))->isInitialized($this)) continue;
            $value = $this->$name;

            if ($value instanceof MadForm) {
                $props[$name] = $value->toArray();
            } elseif ($value instanceof Model) {
                $props[$name] = self::_modelToState($value);
            } else {
                $props[$name] = $value;
            }
        }

        return $props;
    }

    /**
     * Props para o contexto de render: MadForm é representado como seus fields
     * (array plano), permitindo que o flattening coloque nome, email, etc. no
     * topo do contexto para os field-components lerem automaticamente.
     */
    private function _publicPropsForContext(): array
    {
        $props    = [];
        $excluded = $this->_viewContextExcludedProps();

        foreach ($this->_getPublicPropNames() as $name) {
            if (in_array($name, $excluded, true)) continue;
            if (!(new \ReflectionProperty($this, $name))->isInitialized($this)) continue;
            $value = $this->$name;
            $props[$name] = ($value instanceof MadForm) ? $value->fields : $value;
        }

        return $props;
    }

    /**
     * Props públicas que ficam FORA do contexto da view — nem pelo nome, nem
     * achatadas. O `MadRenderContext::push` espalha as chaves de toda prop
     * array como variáveis do Blade, e o contexto vence os dados da view: um
     * estado interno com chave `label`/`max`/`rules` virava `$label`/`$max`/
     * `$rules` na tela do usuário. Sobrescreva para esconder estado que a view
     * não lê (ela recebe o que precisa por view data).
     *
     * @return list<string>
     */
    protected function _viewContextExcludedProps(): array
    {
        return [];
    }

    // ── Resolução automática de parâmetros ──────────────────────────────

    /**
     * Chama um método do componente resolvendo parâmetros automaticamente via Reflection.
     *
     * Suporta:
     *   onEdit(int $id)                → extrai 'id' do array e faz cast
     *   onEdit(int $id, string $nome)  → extrai múltiplos params
     *   onEdit(array $params)          → passa o array inteiro
     *   mount(array $params)           → passa o array inteiro
     *   onAction()                     → sem parâmetros
     *
     * Resolução por parâmetro:
     *   1. Se o tipo é `array` → passa o $data inteiro
     *   2. Senão busca por nome no $data: $data[$paramName]
     *   3. Se não encontrar, usa default do parâmetro
     *   4. Cast automático para int, float, string, bool
     *
     * @param string $method Nome do método
     * @param array  $data   Dados disponíveis (ex: $_REQUEST, params do AJAX, etc.)
     * @return mixed          Retorno do método
     */
    public function _resolveAndCall(string $method, array $data = []): mixed
    {
        $ref    = new \ReflectionMethod($this, $method);
        $params = $ref->getParameters();

        // Sem parâmetros → chamada direta
        if (empty($params)) {
            return $this->$method();
        }

        // Array posicional (keys 0,1,2...) → mapeia por posição
        // Ex: MadWire envia [rowData, editIndex] como [0 => {...}, 1 => -1]
        $isPositional = array_is_list($data) && !empty($data);

        if ($isPositional) {
            return $this->$method(...$data);
        }

        // Um único parâmetro do tipo array → passa o $data inteiro (padrão legado)
        // Um único parâmetro do tipo MadRequest → injeta MadRequest com $data
        if (count($params) === 1) {
            $type = $params[0]->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'array') {
                return $this->$method($data);
            }
            if ($type instanceof \ReflectionNamedType && $type->getName() === MadRequest::class) {
                return $this->$method(new MadRequest($data));
            }
        }

        // Resolve cada parâmetro individualmente por nome
        $args = [];
        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // Injeta MadRequest automaticamente quando o tipo e MadRequest
            if ($type instanceof \ReflectionNamedType && $type->getName() === MadRequest::class) {
                $args[] = new MadRequest($data);
                continue;
            }

            if (array_key_exists($name, $data)) {
                $value = $data[$name];

                // Cast para o tipo declarado
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $args[] = $value;
                } elseif ($type instanceof \ReflectionNamedType) {
                    $args[] = match ($type->getName()) {
                        'int'    => (int)    $value,
                        'float'  => (float)  $value,
                        'string' => (string) $value,
                        'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                        default  => $value,
                    };
                } else {
                    $args[] = $value;
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                $args[] = null;
            } else {
                return $this->$method($data);
            }
        }

        return $this->$method(...$args);
    }

    /** Verifica se uma propriedade é pública e de instância. */
    private function _isPublicProp(string $name): bool
    {
        return in_array($name, $this->_getPublicPropNames(), true);
    }

    /** Verifica se uma prop pública está inicializada (seguro para typed props). */
    private function _isInitialized(string $name): bool
    {
        if (!property_exists($this, $name)) return false;
        return (new \ReflectionProperty($this, $name))->isInitialized($this);
    }

    public function onShow(): void
    {

    }

    // ── ORM / tree helpers (reusáveis por forms com PK slug e hierarquia) ──

    /**
     * Gera um ID único (slug) para um model cuja PK é texto/slug.
     * Deriva o slug de `$text` (lowercase, não-alfanum → "_") e sufixa
     * `_2`, `_3`… até achar um valor livre.
     *
     * Faz query no model para checar unicidade (Eloquent; sem transação obrigatória).
     *
     * @param class-string $modelClass  Ex.: 'GanttTask'
     * @param string       $text        Texto base (ex.: nome da tarefa)
     * @param string       $field       Coluna a checar unicidade (default PK 'id')
     */
    protected function generateUniqueId(string $modelClass, string $text, string $field = 'id'): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($text)));
        $base = trim((string) $base, '_') ?: 'item';
        $candidate = $base;
        $n = 1;
        while ($modelClass::where($field, '=', $candidate)->first()) {
            $candidate = $base . '_' . (++$n);
        }
        return $candidate;
    }

    /**
     * Coleta os IDs de todos os descendentes (recursivo) numa árvore
     * self-referenciada por `$parentField`. Útil para cascade delete.
     *
     * Faz queries no model (Eloquent); envolva numa transação se a deleção for atômica.
     *
     * @param class-string    $modelClass   Ex.: 'GanttTask'
     * @param string|int      $rootId       ID raiz
     * @param string          $parentField  Coluna FK pro pai (ex.: 'parent_id')
     * @param bool            $includeRoot  Inclui o próprio root no resultado
     * @return string[]                     Lista de IDs (root + descendentes)
     */
    protected function collectDescendantIds(string $modelClass, string|int $rootId, string $parentField, bool $includeRoot = true): array
    {
        $out   = $includeRoot ? [(string) $rootId] : [];
        $stack = [(string) $rootId];
        while ($stack) {
            $cur  = array_pop($stack);
            $kids = $modelClass::where($parentField, '=', $cur)->get();
            foreach ($kids as $k) {
                $out[]   = (string) $k->id;
                $stack[] = (string) $k->id;
            }
        }
        return $out;
    }
}