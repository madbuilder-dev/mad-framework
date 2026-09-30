<?php
namespace Mad\Form;


/**
 * FieldListAction — Builder fluent de acoes por linha para <mad-field-list>.
 *
 * Analogo ao GridAction, mas com runtime client-side: todas as acoes sao
 * renderizadas dentro do x-for Alpine e acionadas via Mad.call() ou Mad.go()
 * com dados da row resolvidos em JS (nao em PHP compile-time como o grid).
 *
 * Suporta dois modos:
 *   1) Action (mad:click) — chama metodo PHP do componente host
 *   2) Navigate           — abre outra classe com auto-detect de wrapper
 */
class FieldListAction
{
    // ── Action (mad:click) ──────────────────────────────────
    public string $method = '';

    // ── Navigate (alternativo a method) ─────────────────────
    public bool   $isNav     = false;
    public string $navClass  = '';
    public string $navMethod = 'show';
    public bool   $navDrawer = false;
    /** @var array ['param_name' => '{campo}' ou 'valor_fixo'] */
    public array  $navParams = [];

    // ── Visual ──────────────────────────────────────────────
    public string $icon    = '';
    public string $label   = '';
    public string $title   = '';
    public string $variant = '';   // 'danger' | 'primary' | 'success' | 'warning' | 'info' | 'ghost' | ''
    public string $color   = '';   // CSS livre (hex, var(--x), nome) — override do icone/hover

    // ── Confirm ─────────────────────────────────────────────
    public string $confirm = '';

    /** @var array Params extras (action mode — merged com row antes do Mad.call) */
    public array  $params = [];

    // ── Factories ───────────────────────────────────────────
    public static function make(string $method = ''): self
    {
        $a = new self();
        $a->method = $method;
        return $a;
    }

    public static function makeNav(string $class, string $method = 'show'): self
    {
        $a = new self();
        $a->isNav     = true;
        $a->navClass  = $class;
        $a->navMethod = $method;
        return $a;
    }

    // ── Fluent setters ──────────────────────────────────────
    public function icon(string $v): self    { $this->icon    = $v; return $this; }
    public function label(string $v): self   { $this->label   = $v; return $this; }
    public function title(string $v): self   { $this->title   = $v; return $this; }
    public function variant(string $v): self { $this->variant = $v; return $this; }
    public function color(string $v): self   { $this->color   = $v; return $this; }
    public function confirm(string $v): self { $this->confirm = $v; return $this; }
    public function params(array $v): self   { $this->params  = $v; return $this; }
    public function drawer(): self           { $this->navDrawer = true; return $this; }

    public function danger(): self           { $this->variant = 'danger';  return $this; }
    public function primary(): self          { $this->variant = 'primary'; return $this; }
    public function success(): self          { $this->variant = 'success'; return $this; }
    public function warning(): self          { $this->variant = 'warning'; return $this; }
    public function info(): self             { $this->variant = 'info';    return $this; }

    /**
     * Retorna a classe CSS do botao conforme variant.
     */
    public function btnClass(): string
    {
        $variants = ['danger', 'primary', 'success', 'warning', 'info', 'ghost'];
        if (in_array($this->variant, $variants, true)) {
            return 'mad-fl-action-btn mad-fl-action-' . $this->variant;
        }
        return 'mad-fl-action-btn';
    }

    /**
     * Retorna style inline quando color livre e definido.
     * A cor sobrescreve o tom do icone; hover mantem a cor com bg suave.
     */
    public function btnStyle(): string
    {
        return $this->color !== '' ? 'color:' . $this->color . ';' : '';
    }

    /**
     * Serializa para JSON consumido pelo Alpine madFieldList._runAction().
     *
     * Para navegacao, detecta no servidor (via reflection):
     *   - navIsOverlay: target e MadComponent DRAWER/MODAL → Mad.overlay/Mad.get
     *   - navStatic:    deve adicionar static=1 na URL? (so pra classes nao-MadComponent)
     *
     * Essa logica espelha MadAction::url()/auto() para que o cliente nao precise
     * adivinhar o wrapper — evita static=1 errado em MadComponent INTERNAL, que
     * pularia o show() (mount + render).
     */
    public function toArray(): array
    {
        // Quando e navigate, os navParams vao em 'navParams'.
        // Quando e action (method), os params extras vao em 'params'.
        // O resolver JS _runAction usa ambos conforme o tipo.
        if ($this->isNav) {
            // Se o dev passou ->params([...]) em uma acao nav, usamos como navParams.
            $navParams = !empty($this->navParams) ? $this->navParams : $this->params;
        } else {
            $navParams = [];
        }

        // Detecta wrapper do destino (so faz sentido quando isNav)
        $navIsOverlay = false;
        // static=1 só p/ método REALMENTE estático (echo direto, sem show()) —
        // espelha MadAction::url()/MadAppController::run. MadComponent sempre usa
        // show() (instância) → nunca static; demais classes só carimbam quando o
        // método de destino é estático de fato. Evita static=1 em nav de instância.
        $navStatic    = false;
        if ($this->isNav && $this->navClass !== '' && class_exists($this->navClass)) {
            if (is_subclass_of($this->navClass, \Mad\Component\MadComponent::class)) {
                // MadComponent usa show() como entry (mount + render) — nunca static=1
                $navStatic = false;
                $w = $this->navClass::getWrapper();
                $navIsOverlay = ($w === \Mad\Component\MadComponent::DRAWER
                              || $w === \Mad\Component\MadComponent::MODAL);
            } else {
                $m = $this->navMethod !== '' ? $this->navMethod : 'show';
                $navStatic = method_exists($this->navClass, $m)
                    && (new \ReflectionMethod($this->navClass, $m))->isStatic();
            }
        }
        // navDrawer=true forca overlay independente do wrapper da classe
        if ($this->navDrawer) {
            $navIsOverlay = true;
        }

        // Assa um TEMPLATE de URL amigavel (/app/slug) no servidor, com
        // placeholders __MAD_<key>__ no lugar dos params (resolvidos no client a
        // partir da row). O _runAction (mad-ui.js) substitui os placeholders pelos
        // valores da linha. Sem isso, o client nao teria a rota (mapa de rotas
        // removido do client por seguranca) e cairia em 404.
        $navUrl = null;
        if ($this->isNav && $this->navClass !== '') {
            $placeholders = [];
            foreach (array_keys($navParams) as $k) {
                $placeholders[$k] = '__MAD_' . $k . '__';
            }
            if ($navStatic) {
                $placeholders['static'] = '1';
            }
            $navUrl = \Mad\Routing\MadRoutes::urlFor($this->navClass, $this->navMethod, $placeholders);
        }

        return [
            'method'       => $this->method,
            'isNav'        => $this->isNav,
            'navClass'     => $this->navClass,
            'navMethod'    => $this->navMethod,
            'navDrawer'    => $this->navDrawer,
            'navIsOverlay' => $navIsOverlay,
            'navStatic'    => $navStatic,
            'navUrl'       => $navUrl,
            'navParams'    => $navParams,
            'icon'         => $this->icon,
            'label'        => $this->label,
            'title'        => $this->title,
            'variant'      => $this->variant,
            'color'        => $this->color,
            'confirm'      => $this->confirm,
            'params'       => $this->params,
        ];
    }
}
