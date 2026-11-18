<?php

namespace Mad\Form;

use Mad\Component\MadRenderContext;
use Mad\Security\ActionVocab;

/**
 * Registro aberto só para visualização (fórum #351).
 *
 * A ação "Visualizar" da listagem (`<mad-grid view-action>`) abre o mesmo
 * formulário do Editar, pelo `onEdit` dele, com o parâmetro `mad_view=1`: a
 * tela carrega o registro como sempre, mostra os campos em leitura
 * (rótulo e valor, {@see ReadMode}) e esconde os botões que gravam. O modo vive
 * no estado criptografado do componente, então o navegador não o desliga: o
 * canal reativo recusa salvar, incluir, editar, excluir e as ações próprias da
 * tela enquanto ele estiver ligado — esconder o botão não basta.
 *
 * Continuam valendo: navegação (buscar, paginar, combos), Visualizar e
 * Exportar. Ação própria que só lê (imprimir) entra pela lista da tela:
 *
 *   protected static array $viewActions = ['onImprimir'];
 */
final class ViewOnly
{
    /** Parâmetro da URL que abre a tela no modo Visualizar. */
    public const PARAM = 'mad_view';

    /** Chaves do vocabulário que continuam valendo no modo Visualizar. */
    private const ALLOWED_KEYS = ['view', 'export'];

    /** Métodos de desenho da tela. */
    private const RENDER = ['show', 'onShow', 'render'];

    /**
     * A ação é recusada numa tela aberta só para visualização?
     *
     * @param object|null $component  a tela (para a lista `$viewActions` dela)
     */
    public static function refuses(string $method, ?object $component = null): bool
    {
        $method = trim($method);
        if ($method === '' || in_array($method, self::RENDER, true)) {
            return false;
        }
        if ($component !== null && method_exists($component, '_viewOnlyAllows') && $component->_viewOnlyAllows($method)) {
            return false;
        }
        if (in_array($method, ActionVocab::CONTEXTUAL, true)) {
            return true;
        }
        $key = ActionVocab::staticKey($method);
        if ($key !== null) {
            return ! in_array($key, self::ALLOWED_KEYS, true);
        }
        if (ActionVocab::isNavigation($method)) {
            return false;
        }

        // Ação própria da tela (onAprovar, salvar…): pode gravar.
        return true;
    }

    /** A tela (a do render atual, sem argumento) está aberta só para visualização? */
    public static function active(?object $component = null): bool
    {
        $component ??= MadRenderContext::getComponent();

        return $component !== null && method_exists($component, 'isViewOnly') && $component->isViewOnly();
    }

    /** O botão que executa `$method` some da tela aberta só para visualização? */
    public static function hides(string $method): bool
    {
        $component = MadRenderContext::getComponent();

        return self::active($component) && self::refuses($method, $component);
    }

    /** Recusa do canal reativo: "Este registro está aberto só para visualização." */
    public static function wirePayload(): array
    {
        return [
            'error'     => self::text('mad.view_only_refused', 'Este registro está aberto só para visualização.'),
            'title'     => self::text('mad.view_only_heading', 'Somente visualização'),
            'forbidden' => true,
            'status'    => 403,
        ];
    }

    private static function text(string $key, string $fallback): string
    {
        try {
            $t = \Mad\I18n\MadLang::t($key);
            if (is_string($t) && $t !== '' && $t !== $key) {
                return $t;
            }
        } catch (\Throwable) {
            // cai na reserva
        }

        return $fallback;
    }
}
