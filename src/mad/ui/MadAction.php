<?php
namespace Mad\Ui;
use Mad\Component\MadComponent;


/**
 * MadAction — Builder central de navegação para o MAD Framework.
 *
 * Centraliza a geração de onclick/href para todas as ações de navegação:
 * botões, grid actions, links, respostas AJAX.
 *
 * ┌─ Uso básico ─────────────────────────────────────────────────────────────┐
 * │                                                                          │
 * │  // Auto-detect: escolhe Mad.load() ou Mad.get() conforme wrapper       │
 * │  MadAction::to('ClienteForm', 'show', ['id' => 42])->auto()            │
 * │  // DRAWER → onclick="Mad.get('ClienteForm@show', {"id":42})"          │
 * │  // INTERNAL → onclick="Mad.go('ClienteForm','show',{...},'/app/…')"    │
 * │                                                                          │
 * │  // Navegação completa (força Mad.load)                                 │
 * │  MadAction::to('ClienteList')->onclick()                                │
 * │                                                                          │
 * │  // Parcial (força Mad.get — modal/drawer)                              │
 * │  MadAction::to('ClienteForm', 'show', ['id' => 42])->onget()           │
 * │                                                                          │
 * │  // Forward params — propagação automática                              │
 * │  MadAction::setForwardParams(['negociacao_id' => 401]);                 │
 * │  MadAction::to('ArquivoForm')->auto()                                   │
 * │  // → inclui _forward_param_negociacao_id=401 automaticamente           │
 * │                                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class MadAction
{
    private string $class;
    private string $method;
    private array  $params;

    /** Forward params globais — setados pelo MadComponent no show(). */
    private static array $forwardParams = [];

    public function __construct(string $class, string $method = 'show', array $params = [])
    {
        $this->class  = $class;
        $this->method = self::normalizeMethod($method);
        $this->params = $params;
    }

    /**
     * Tira parênteses VAZIOS do nome do método (`onShow()` → `onShow`).
     *
     * O studio gravava `navigate="Classe::onShow()"` para métodos sem parâmetro.
     * Quem não reconhecia a chamada deixava o nome com os parênteses colados; daí
     * o método vira segmento de URL (`rawurlencode` → `onShow%28%29`) e a rota do
     * expose() — que restringe o segmento a `[A-Za-z][A-Za-z0-9_]*` — responde 404.
     *
     * Só o par vazio some. `onEdit({id})` fica intacto: descartar argumento em
     * silêncio seria trocar um 404 por uma tela abrindo com o registro errado.
     */
    private static function normalizeMethod(string $method): string
    {
        return (string) preg_replace('/\(\s*\)$/', '', trim($method));
    }

    /** Cria uma nova MadAction de forma estática e fluente. */
    public static function to(string $class, string $method = 'show', array $params = []): self
    {
        return new self($class, $method, $params);
    }

    // ── Forward Params ──────────────────────────────────────────────────────

    /**
     * Define os forward params globais (chamado pelo MadComponent::show()).
     * Params sem prefixo — o prefixo _forward_param_ é adicionado no output.
     */
    public static function setForwardParams(array $params): void
    {
        self::$forwardParams = $params;
    }

    /** Retorna os forward params atuais (sem prefixo). */
    public static function getForwardParams(): array
    {
        return self::$forwardParams;
    }

    /** Limpa os forward params. */
    public static function clearForwardParams(): void
    {
        self::$forwardParams = [];
    }

    // ── Fluent API ──────────────────────────────────────────────────────────

    /** Adiciona (ou sobrescreve) parâmetros e retorna $this para encadeamento. */
    public function with(array $params): self
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    // ── Auto-detect ─────────────────────────────────────────────────────────

    /**
     * Detecta se a classe destino é DRAWER/MODAL (overlay) ou INTERNAL (página).
     */
    public function isOverlay(): bool
    {
        if (class_exists($this->class) && is_subclass_of($this->class, MadComponent::class)) {
            $w = $this->class::getWrapper();
            return $w === MadComponent::DRAWER || $w === MadComponent::MODAL;
        }
        return false;
    }

    // ── Params com forward ──────────────────────────────────────────────────

    /**
     * Retorna params mergeados com forward params (_forward_param_*).
     * Forward params têm menor prioridade — params explícitos ganham.
     */
    private function allParams(): array
    {
        $fwd = [];
        foreach (self::$forwardParams as $k => $v) {
            $fwd[$k] = $v;                           // param limpo (para o destino usar)
            $fwd['_forward_param_' . $k] = $v;       // com prefixo (para propagar adiante)
        }
        return array_merge($fwd, $this->params);
    }

    // ── Output: URL ─────────────────────────────────────────────────────────

    /** Retorna a URL amigável da action (/app/slug/...). */
    public function url(): string
    {
        $params = $this->allParams();

        // static=1 só faz sentido p/ método REALMENTE estático: o backend
        // (MadAppController::run) faz echo direto do método, pulando show().
        // Em método de instância o static=1 é ignorado (cai no fluxo de
        // fragmento), então não o emitimos — senão vira ruído na URL amigável
        // de toda navegação não-MadComponent.
        if ($this->isStaticTarget()) {
            $params['static'] = '1';
        }

        return \Mad\Routing\MadRoutes::urlFor($this->class, $this->method, $params);
    }

    /**
     * O destino (class@method) é um método PHP estático real? Espelha o gate do
     * backend (MadAppController::run via ReflectionMethod->isStatic), que é quem
     * de fato honra ?static=1. Garante que endpoints estáticos legítimos
     * (MadDbComboService::load, *Service::onSearch, getEvents, SearchBox::menu…)
     * continuem com static=1, e navegações de instância não.
     */
    private function isStaticTarget(): bool
    {
        return class_exists($this->class)
            && method_exists($this->class, $this->method)
            && (new \ReflectionMethod($this->class, $this->method))->isStatic();
    }

    // ── Output: JS ──────────────────────────────────────────────────────────

    /**
     * URL amigável (/app/slug) assada no servidor. Centraliza a transformação
     * no PHP para que a UI receba a forma correta pronta, sem precisar do mapa
     * de rotas no client.
     */
    private function webUrl(): string
    {
        return $this->url();
    }

    /** JS para navegação completa: Mad.go(cls,method,params,friendlyUrl). */
    public function js(): string
    {
        $params = $this->paramsToJsObject($this->allParams());
        return "Mad.go('" . addslashes($this->class) . "','" . addslashes($this->method)
             . "'," . $params . ",'" . addslashes($this->webUrl()) . "')";
    }

    /** JS para chamada parcial via Mad.get() (modal/drawer). */
    public function jsGet(): string
    {
        $classMethod = $this->class . '@' . $this->method;
        $paramsJson  = $this->paramsToJsObject($this->allParams());
        $web = $this->webUrl();
        $urlArg = $web !== null ? ", null, '" . addslashes($web) . "'" : '';
        return "Mad.get('" . $classMethod . "', " . $paramsJson . $urlArg . ")";
    }

    /** JS para overlay via Mad.overlay(). */
    public function jsOverlay(): string
    {
        $classMethod = $this->class . '@' . $this->method;
        $paramsJson  = $this->paramsToJsObject($this->allParams());
        $web = $this->webUrl();
        $urlArg = $web !== null ? ", '" . addslashes($web) . "'" : '';
        return "Mad.overlay('" . $classMethod . "', " . $paramsJson . $urlArg . ")";
    }

    /**
     * JS para abrir o alvo ANEXADO à linha da grid (quick-edit).
     * `this` no onclick é o botão clicado — é por ele que o client acha a <tr>.
     */
    public function jsRowAttach(): string
    {
        $classMethod = $this->class . '@' . $this->method;
        $paramsJson  = $this->paramsToJsObject($this->allParams());
        return "Mad.rowAttach(this, '" . $classMethod . "', " . $paramsJson
             . ", '" . addslashes($this->webUrl()) . "')";
    }

    /**
     * JS auto-detect: escolhe Mad.get() ou Mad.load() conforme wrapper.
     */
    public function jsAuto(): string
    {
        return $this->isOverlay() ? $this->jsGet() : $this->js();
    }

    // ── Output: HTML attr ───────────────────────────────────────────────────

    /** Atributo onclick="Mad.load(...)" — navegação completa. */
    public function onclick(): string
    {
        return 'onclick="' . htmlspecialchars($this->js(), ENT_QUOTES) . '"';
    }

    /** Atributo onclick="Mad.get(...)" — chamada parcial. */
    public function onget(): string
    {
        return 'onclick="' . htmlspecialchars($this->jsGet(), ENT_QUOTES) . '"';
    }

    /** Atributo onclick="Mad.rowAttach(this,...)" — form anexado à linha da grid. */
    public function onRowAttach(): string
    {
        return 'onclick="' . htmlspecialchars($this->jsRowAttach(), ENT_QUOTES) . '"';
    }

    /**
     * Atributo onclick auto-detect: escolhe conforme wrapper da classe destino.
     * DRAWER/MODAL → Mad.get()
     * INTERNAL → Mad.load()
     */
    public function auto(): string
    {
        return $this->isOverlay() ? $this->onget() : $this->onclick();
    }

    // ── Alvo declarativo (click-target / call) ──────────────────────────────

    /**
     * Alvo declarativo de clique → o trio que o client precisa: classe, método
     * e URL amigável ASSADA NO SERVIDOR.
     *
     * Aceita as três formas que os widgets usam num atributo Blade:
     *
     *   'ClienteForm'                 → método $defaultMethod
     *   'ClienteForm::onShow'         → método explícito
     *   'ClienteForm::onShow({id})'   → args declarados (o `({id})` some: o
     *                                   valor vira param, resolvido no client)
     *
     * Por que existe: o mapa de rotas NÃO vive no client. Um widget que passa só
     * `Classe` + `método` pro JS força o `Mad.go` a cair na forma genérica
     * `/app/<Classe>/<metodo>`, que só existe para classe registrada com
     * `exposeClass()` — uma tela de `resource()`/`expose()` mora em
     * `/app/clientes/novo` e a forma genérica dá 404.
     *
     * As chaves em $paramKeys entram na URL como placeholder `__MAD_<key>__`
     * (mesmo contrato do FieldListAction): o servidor não conhece o id do card
     * clicado, então assa o TEMPLATE e o client substitui na hora do clique —
     * inclusive quando o id vai no PATH (rota `edit` de resource()).
     *
     * @param  string   $target        Alvo cru vindo do Blade.
     * @param  string   $defaultMethod Método quando o alvo não traz `::metodo`.
     * @param  string[] $paramKeys     Chaves cujo valor só o client conhece.
     * @return array{class:string,method:string,url:string}|null  null se vazio.
     */
    public static function navTarget(string $target, string $defaultMethod = 'show', array $paramKeys = ['id']): ?array
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        $class  = $target;
        $method = $defaultMethod;

        // Aceita tanto `Classe::metodo` (atributo do Blade) quanto `Classe@metodo`
        // (forma do Mad.exec/Mad.get) — o mesmo alvo aparece nas duas grafias.
        foreach (['::', '@'] as $sep) {
            if (str_contains($target, $sep)) {
                [$class, $method] = explode($sep, $target, 2);
                break;
            }
        }

        $class = trim($class);
        // `onShow({id})` → `onShow`. Os args declarados NÃO viram segmento de
        // URL: `rawurlencode` transformaria em `onShow%28%7Bid%7D%29` e a
        // constraint da rota (`[A-Za-z][A-Za-z0-9_]*`) responderia 404.
        $method = trim((string) preg_replace('/\(.*\)$/s', '', trim($method)));

        if ($class === '') {
            return null;
        }
        if ($method === '') {
            $method = $defaultMethod;
        }

        $placeholders = [];
        foreach ($paramKeys as $k) {
            $k = (string) $k;
            if ($k !== '') {
                $placeholders[$k] = '__MAD_' . $k . '__';
            }
        }

        return [
            'class'  => $class,
            'method' => $method,
            'url'    => self::to($class, $method, $placeholders)->url(),
        ];
    }

    /**
     * A rota amigável desta URL aceita POST?
     *
     * `resource()` registra a tela com `Route::get` — só leitura. Quem POSTa
     * (o `Mad.exec` do `action=`, por exemplo) numa URL dessas troca um 404 por
     * um 405, que não é progresso nenhum. `expose()`/`exposeService()`/
     * `exposeClass()` registram `match(['get','post'])` e passam.
     *
     * O path é normalizado a partir de `/app/`: a `appBase()` pode trazer
     * prefixo de sub-path (ou o caminho do script, em CLI), mas as rotas são
     * sempre registradas como `/app/...`.
     */
    public static function acceptsPost(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $pos  = strpos($path, '/app/');
        if ($pos !== false) {
            $path = substr($path, $pos);
        }
        if ($path === '') {
            return false;
        }

        try {
            \Illuminate\Support\Facades\Route::getRoutes()
                ->match(\Illuminate\Http\Request::create($path, 'POST'));
            return true;
        } catch (\Throwable $e) {
            // MethodNotAllowed / NotFound — não dá pra POSTar aqui.
            return false;
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Converte params para JS object literal sem aspas nas chaves.
     * Evita conflito de aspas duplas em atributos HTML.
     * Ex: {negociacao_id:401,id:42,nome:'João'}
     */
    private function paramsToJsObject(array $params): string
    {
        if (empty($params)) {
            return '{}';
        }
        $pairs = [];
        foreach ($params as $k => $v) {
            if (is_numeric($v)) {
                $pairs[] = $k . ':' . $v;
            } elseif (is_bool($v)) {
                $pairs[] = $k . ':' . ($v ? 'true' : 'false');
            } elseif (is_null($v)) {
                $pairs[] = $k . ':null';
            } elseif (is_array($v)) {
                // Lista (ex. filtro multi-valor) → CSV: a querystring do
                // Mad.get/overlay juntaria com vírgula de qualquer forma e o
                // MadFiltersTrait::_coerceFilterValue desfaz em array.
                $flat = array_filter($v, 'is_scalar');
                $pairs[] = $k . ":'" . addslashes(implode(',', array_map('strval', $flat))) . "'";
            } else {
                $pairs[] = $k . ":'" . addslashes((string) $v) . "'";
            }
        }
        return '{' . implode(',', $pairs) . '}';
    }

    /** Cast para string retorna a URL. */
    public function __toString(): string
    {
        return $this->url();
    }
}