<?php
namespace Mad\Grid;
use Mad\Ui\MadAction;


/**
 * GridAction — Builder fluent de ações por linha para MadDataGrid.
 */
class GridAction
{
    public string  $method    = '';
    public string  $label     = '';
    public string  $icon      = '';
    public string  $confirm        = '';
    public string  $confirmPopover = '';
    public bool    $isDanger  = false;
    public bool    $isPrimary = false;
    public         $whenFn     = null;   // callable ($row) => bool
    public         $disabledFn = null;  // callable ($row) => bool
    public         $transformFn = null; // callable ($row) => array de overrides
    public array   $params    = [];
    public string  $idField   = 'id';

    // Navegação (nav action — sem AJAX no grid)
    public bool    $isNav      = false;
    public string  $navClass   = '';
    public string  $navMethod  = 'show';
    public bool    $navDrawer  = false;
    public bool    $navRow     = false; // abre o alvo anexado à linha (quick-edit)
    public array   $navParams  = [];   // ['param_name' => '{campo}' ou 'valor_fixo']

    // Permissão por ação (tela de Perfis) — ver \Mad\Security\ActionGuard.
    /** Classe DONA da permissão: a tela do grid, ou o destino de uma navegação. */
    public string  $permClass  = '';
    /** Ação a perguntar ao perfil; vazio = o próprio $method. */
    public string  $permAction = '';

    public static function make(string $method): self
    {
        $a = new self();
        $a->method = $method;
        return $a;
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function icon(string $lucideIcon): self
    {
        $this->icon = $lucideIcon;
        return $this;
    }

    public function confirm(string $message): self
    {
        $this->confirm = $message;
        return $this;
    }

    public function danger(): self
    {
        $this->isDanger = true;
        return $this;
    }

    public function primary(): self
    {
        $this->isPrimary = true;
        return $this;
    }

    /** Callable ou string 'Classe::metodo' que recebe (array $row) e retorna bool. */
    public function when($condition): self
    {
        $this->whenFn = $condition;
        return $this;
    }

    /** Callable ou string 'Classe::metodo' que recebe (array $row) e retorna bool. */
    public function disabled($condition): self
    {
        $this->disabledFn = $condition;
        return $this;
    }

    /**
     * Define uma transformação dinâmica por linha.
     * O callable/string 'Classe::metodo' recebe (array $row) e retorna um array de overrides:
     *   ['label'=>'...', 'icon'=>'...', 'confirm'=>'...', 'danger'=>true, 'primary'=>false]
     * Campos omitidos mantêm o valor original da ação.
     */
    public function transform($fn): self
    {
        $this->transformFn = $fn;
        return $this;
    }

    /**
     * Retorna um clone da ação com as propriedades sobrescritas pelo transformFn.
     * Se não houver transformFn, retorna a própria instância sem clonar.
     *
     * Signature do callable (auto-detecta via reflection):
     *   - 1 arg: fn(array $row): array   — legado MAD (compat)
     *   - 2 args: fn(?object $object, array $row): array — compat legado
     */
    public function getTransformed(array $row): self
    {
        if ($this->transformFn === null || !is_callable($this->transformFn)) {
            return $this;
        }
        $override = (array) self::_callRowCallback($this->transformFn, $row);
        if (empty($override)) {
            return $this;
        }
        $clone = clone $this;
        if (array_key_exists('label',   $override)) $clone->label    = (string)$override['label'];
        if (array_key_exists('icon',    $override)) $clone->icon     = (string)$override['icon'];
        if (array_key_exists('confirm', $override)) $clone->confirm  = (string)$override['confirm'];
        if (array_key_exists('danger',  $override)) $clone->isDanger  = (bool)$override['danger'];
        if (array_key_exists('primary', $override)) $clone->isPrimary = (bool)$override['primary'];
        return $clone;
    }

    public function params(array $extra): self
    {
        $this->params = $extra;
        return $this;
    }

    public function idField(string $field): self
    {
        $this->idField = $field;
        return $this;
    }

    /**
     * Marca a ação como navegação — clique abre uma página/drawer sem AJAX no grid.
     *
     * @param string $targetClass  Classe destino (ex: 'PessoaForm')
     * @param string $targetMethod Método destino (padrão: 'show')
     * @param bool   $drawer       true → abre como drawer/modal (@madGet)
     */
    public function nav(string $targetClass, string $targetMethod = 'show', bool $drawer = false): self
    {
        $this->isNav     = true;
        $this->navClass  = $targetClass;
        $this->navMethod = $targetMethod;
        $this->navDrawer = $drawer;
        return $this;
    }

    /**
     * Abre o alvo ANEXADO à linha da grid (quick-edit inline).
     * Em card-view (sem <tr>) o client cai no comportamento overlay padrão.
     */
    public function row(bool $on = true): self
    {
        $this->navRow = $on;
        return $this;
    }

    /**
     * Gera os atributos HTML do botão para ações de navegação.
     *
     * Auto-detecta o wrapper da classe alvo:
     *   DRAWER/MODAL → Mad.overlay() (abre por cima)
     *   INTERNAL     → Mad.load()   (navega)
     *
     * O atributo `drawer` no template força overlay independente do wrapper.
     *
     * @param  mixed  $id  Valor do ID da linha
     * @return string      String de atributos HTML (safe para usar com {!! !!})
     */
    public function getNavAttr(mixed $id, array $row = []): string
    {
        // Resolve parâmetros: {campo} → valor do registro
        if (!empty($this->navParams)) {
            $params = [];
            foreach ($this->navParams as $key => $val) {
                $params[$key] = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($row, $id) {
                    if (array_key_exists($m[1], $row)) {
                        return (string)$row[$m[1]];
                    }
                    // `{id}` numa tabela cuja PK tem nome proprio (`codigo`,
                    // `matricula`): a linha nao tem coluna `id`, mas o grid ja
                    // resolveu o id da linha. Sem isto o navigate saia vazio e
                    // o botao Editar abria o formulario em branco.
                    return $m[1] === 'id' ? (string)$id : '';
                }, $val);
            }
        } else {
            $params = ['id' => (string)$id];
        }

        $action = MadAction::to($this->navClass, $this->navMethod, $params);

        // navRow → form anexado à linha (quick-edit)
        if ($this->navRow) {
            return $action->onRowAttach();
        }

        // navDrawer forçado → sempre overlay
        if ($this->navDrawer) {
            return $action->onget();
        }

        // Auto-detect via MadAction (le wrapper da classe destino)
        return $action->auto();
    }

    /**
     * Declara a quem perguntar pela permissão desta ação.
     *
     * A ação de uma linha pertence à TELA que contém o grid (é ela que o perfil
     * marca: "Pedidos", não "MadGrid"), exceto quando a ação NAVEGA — aí quem
     * manda é a tela de destino, que é a que vai abrir.
     *
     * @param string $class  Classe dona (vazio = ação fora do alcance do perfil).
     * @param string $action Ação a perguntar; vazio = o próprio método.
     */
    public function perm(string $class, string $action = ''): self
    {
        $this->permClass  = trim($class);
        $this->permAction = trim($action);
        return $this;
    }

    /**
     * O que o perfil diz sobre esta ação: allow | hide | disable.
     *
     * @return array{mode: 'allow'|'hide'|'disable', key: string|null, title: string}
     */
    private function permDecision(): array
    {
        $acao = $this->permAction !== '' ? $this->permAction : $this->method;

        return \Mad\Security\ActionGuard::decide($this->permClass, $acao);
    }

    /**
     * A decisão do perfil sobre esta ação, SEM olhar a linha: allow | hide |
     * disable.
     *
     * Existe separado de {@see isVisible()}/{@see isDisabled()} porque nem toda
     * lista tem linha em PHP: o `<mad-detail-form>` monta as linhas no
     * navegador (Alpine), então os callbacks por linha não rodam ali. Permissão
     * não depende da linha — é da ação — e por isso pode ser respondida assim.
     */
    public function permMode(): string
    {
        return $this->permDecision()['mode'];
    }

    /**
     * A dica do botão recusado ("Sem permissão para excluir"), ou '' quando a
     * ação é permitida. Vira o `title` do botão da linha — que por isso não
     * leva `disabled` (ver stateAttrs()).
     */
    public function denyTitle(): string
    {
        return $this->permDecision()['title'];
    }

    /**
     * Verifica se a ação deve ser exibida para a linha dada.
     *
     * Signature do callable (auto-detecta via reflection):
     *   - 1 arg: fn(array $row): bool   — legado MAD (compat)
     *   - 2 args: fn(?object $object, array $row): bool — compat legado
     *
     * Roda dentro de transação aberta — pode tocar banco livremente.
     */
    public function isVisible(array $row): bool
    {
        // Perfil sem a ação, no modo "ocultos": o botão sai da linha.
        if ($this->permDecision()['mode'] === 'hide') {
            return false;
        }
        if ($this->whenFn !== null && is_callable($this->whenFn)) {
            return (bool) self::_callRowCallback($this->whenFn, $row);
        }
        return true;
    }

    /**
     * Verifica se a ação está desabilitada para a linha dada.
     * Mesma signature flexivel de isVisible.
     */
    public function isDisabled(array $row): bool
    {
        // Perfil sem a ação, no modo "desabilitados": fica cinza, com a dica.
        if ($this->permDecision()['mode'] === 'disable') {
            return true;
        }
        if ($this->disabledFn !== null && is_callable($this->disabledFn)) {
            return (bool) self::_callRowCallback($this->disabledFn, $row);
        }
        return false;
    }

    /**
     * Atributos de ESTADO do botão desta ação na linha (vão crus na tag):
     *
     *  - perfil sem a ação, modo "desabilitados, com dica":
     *    `aria-disabled="true" data-mad-deny` — NUNCA `disabled`. O tema do app
     *    põe `pointer-events:none` em `button:disabled`: o mouse atravessava o
     *    botão, a dica (o `title` = denyTitle()) nunca aparecia e o teclado nem
     *    chegava nele. Assim ele segue focável, o leitor de tela anuncia
     *    "indisponível" e o mad-ui.js barra o clique e mostra a dica;
     *  - regra da linha (`disabled()` do desenvolvedor): `disabled`, como sempre;
     *  - senão, nada.
     */
    public function stateAttrs(array $row): string
    {
        if ($this->permDecision()['mode'] === 'disable') {
            return 'aria-disabled="true" data-mad-deny';
        }

        return $this->isDisabled($row) ? 'disabled' : '';
    }

    /**
     * Invoca callable de row passando args conforme aridade detectada.
     *   1 arg → callable(array $row)
     *   2+ args → callable(?object $record, array $row)
     *
     * Em caso de duvida, passa 2 args (record, row).
     */
    private static function _callRowCallback(callable $fn, array $row): mixed
    {
        $object = $row['__record'] ?? null;
        try {
            $ref = is_array($fn)
                ? new \ReflectionMethod($fn[0], $fn[1])
                : (is_string($fn) && str_contains($fn, '::')
                    ? new \ReflectionMethod(...explode('::', $fn, 2))
                    : new \ReflectionFunction($fn));
            $n = $ref->getNumberOfParameters();
        } catch (\Throwable $e) {
            $n = 2;
        }
        if ($n <= 1) {
            return call_user_func($fn, $row);
        }
        return call_user_func($fn, $object, $row);
    }

    /**
     * Retorna a classe CSS de variante do botão.
     */
    public function btnClass(): string
    {
        if ($this->isDanger)  return 'mad-dg-action-btn mad-dg-action-danger';
        if ($this->isPrimary) return 'mad-dg-action-btn mad-dg-action-primary';
        return 'mad-dg-action-btn';
    }
}