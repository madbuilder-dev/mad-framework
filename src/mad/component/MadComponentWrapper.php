<?php
namespace Mad\Component;
use Mad\View\MadBlade;


/**
 * MadComponentWrapper — responsável por envolver um MadComponent em modal ou drawer.
 *
 * Extrai a lógica de _buildModal() e _buildDrawer() de MadComponent,
 * separando a responsabilidade de wrapping do ciclo de vida do componente.
 */
class MadComponentWrapper
{
    /**
     * Envolve o HTML do componente no wrapper configurado pela classe.
     * Retorna HTML pronto para enviar ao cliente.
     *
     * `$as` troca o wrapper da classe por este (a cortina do
     * `<mad-row-expand>` no modo cartão abre uma listagem, que é INTERNAL);
     * `$title` troca o título da classe, quando não vazio.
     */
    public static function wrap(MadComponent $component, string $html, ?string $as = null, string $title = ''): string
    {
        return match ($as ?? $component::getWrapper()) {
            MadComponent::MODAL  => self::buildModal($component, $html, $title),
            MadComponent::DRAWER => self::buildDrawer($component, $html, $title),
            default              => $html,
        };
    }

    private static function buildModal(MadComponent $component, string $html, string $title = ''): string
    {
        return MadBlade::render('_wrapper-modal', [
            'content' => $html,
            'id'      => $component->_getId(),
            'title'   => $title !== '' ? $title : $component::getTitle(),
            'size'    => $component::getSize(),
            'closeOnBackdrop' => $component::getCloseOnBackdrop(),
            'confirmClose'    => self::confirmDiscard($component),
            'draggable'       => self::staticFlag($component, 'draggable'),
            'help'            => \Mad\Help\ManualRepository::headerButtonScreen($component),
            'back'            => self::returnTo($component),
        ]);
    }

    private static function buildDrawer(MadComponent $component, string $html, string $title = ''): string
    {
        return MadBlade::render('_wrapper-drawer', [
            'content' => $html,
            'id'      => $component->_getId(),
            'title'   => $title !== '' ? $title : $component::getTitle(),
            'size'    => $component::getSize(),
            'side'    => $component::getSide(),
            'closeOnBackdrop' => $component::getCloseOnBackdrop(),
            'confirmClose'    => self::confirmDiscard($component),
            'help'            => \Mad\Help\ManualRepository::headerButtonScreen($component),
            'back'            => self::returnTo($component),
        ]);
    }

    /**
     * Para onde volta a gaveta/modal que É a tela quando fecha sem trocar de
     * rota (fw#638): aberta pela URL — link de e-mail, favorito, F5, "Ir
     * para…" de uma trava — ela não tem tela por trás, e fechar no Salvar
     * (`closeDrawer()`), no X ou no Esc deixava a página vazia. O mad.js tenta
     * antes o voltar do histórico, quando a entrada anterior é do app; este é
     * o destino seguinte:
     *
     *   1. a tela declarada na classe (aceita `'Tela::metodo'`):
     *          protected static ?string $returnTo = 'ClienteList';
     *   2. por convenção, a listagem de onde o formulário herda a permissão
     *      (rota de resource) ou, pelo nome, `ClienteForm` → `ClienteList`,
     *      quando a classe existe;
     *   3. nenhuma (null): o mad.js vai para a tela inicial.
     *
     * Destino que a pessoa não pode abrir também cai na tela inicial.
     * Devolve `{class, method, url}` — a URL amigável assada aqui, como a do
     * `close="Tela"` do <mad-btn>.
     *
     * @return array{class:string, method:string, url:string}|null
     */
    private static function returnTo(MadComponent $component): ?array
    {
        try {
            $target = self::staticString($component, 'returnTo');
            if ($target === '') {
                $target = self::conventionalReturn($component);
            }
            if ($target === '') {
                return null;
            }
            [$class, $method] = array_pad(explode('::', $target, 2), 2, '');
            $class  = trim($class);
            $method = trim($method) !== '' ? trim($method) : 'show';
            if ($class === '' || !\Mad\Security\PermissionGate::canAccess($class)) {
                return null;
            }
            $url = '';
            try {
                $url = \Mad\Ui\MadAction::to($class, $method)->url();
            } catch (\Throwable) {
                $url = '';
            }

            return ['class' => $class, 'method' => $method, 'url' => $url];
        } catch (\Throwable) {
            return null;
        }
    }

    /** `XForm` → a listagem do resource (herança de permissão) ou `XList`, se existe; senão ''. */
    private static function conventionalReturn(MadComponent $component): string
    {
        $fqcn = get_class($component);
        $id   = \Mad\Registry\ControlRegistry::idFor($fqcn);
        $pos  = strrpos($id, '\\');
        $id   = $pos === false ? $id : substr($id, $pos + 1);

        $resourceList = \Mad\Routing\MadRoutes::resourceFormList($id);
        if ($resourceList !== null && $resourceList !== '' && $resourceList !== $id) {
            return $resourceList;
        }
        if (!str_ends_with($id, 'Form') || strlen($id) <= 4) {
            return '';
        }
        $list = substr($id, 0, -4) . 'List';
        $sibling = substr($fqcn, 0, -4) . 'List';
        if (str_ends_with($fqcn, 'Form') && class_exists($sibling) && is_subclass_of($sibling, MadComponent::class)) {
            return $list;
        }
        $resolved = \Mad\Registry\ControlRegistry::resolve($list);

        return ($resolved !== null && is_subclass_of($resolved, MadComponent::class)) ? $list : '';
    }

    /**
     * A tela pede confirmação antes de fechar com alteração não salva?
     * Opt-in na classe da tela:
     *
     *     protected static bool $confirmDiscard = true;
     *
     * Esc, X e clique fora perguntam antes de fechar a gaveta/modal; o
     * fechamento que vem do servidor (closeDrawer() depois de salvar), o
     * Voltar e o Cancelar fecham direto. Lido por reflexão: tela que não
     * declara a propriedade fecha como sempre.
     */
    private static function confirmDiscard(MadComponent $component): bool
    {
        return self::staticFlag($component, 'confirmDiscard');
    }

    /**
     * Interruptor opt-in declarado na classe da tela (`protected static bool
     * $<nome> = true;`). Lido por reflexão, sem declaração na base: uma tela
     * que já tem uma propriedade de instância com esse nome continua valendo
     * (redeclarar como estática daria erro fatal) e conta como desligado.
     *
     *   $confirmDiscard — pergunta antes de fechar com alteração não salva.
     *   $draggable      — modal da tela arrastável pelo cabeçalho (fórum #245).
     */
    private static function staticFlag(MadComponent $component, string $name): bool
    {
        try {
            $prop = new \ReflectionProperty($component, $name);
        } catch (\ReflectionException) {
            return false;
        }
        if (!$prop->isStatic()) {
            return false;
        }
        $prop->setAccessible(true);
        return filter_var($prop->getValue(), FILTER_VALIDATE_BOOL);
    }

    /**
     * Texto declarado na classe da tela (`protected static ?string $<nome> =
     * '...';`), lido por reflexão como o {@see staticFlag()}: sem declaração
     * na base. Ausente, de instância, null ou não-texto: ''.
     *
     *   $returnTo — tela para onde a gaveta/modal aberta pela URL volta ao fechar (fw#638).
     */
    private static function staticString(MadComponent $component, string $name): string
    {
        try {
            $prop = new \ReflectionProperty($component, $name);
        } catch (\ReflectionException) {
            return '';
        }
        if (!$prop->isStatic()) {
            return '';
        }
        $prop->setAccessible(true);
        $value = $prop->getValue();

        return is_string($value) ? trim($value) : '';
    }
}
