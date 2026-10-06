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
     */
    public static function wrap(MadComponent $component, string $html): string
    {
        return match ($component::getWrapper()) {
            MadComponent::MODAL  => self::buildModal($component, $html),
            MadComponent::DRAWER => self::buildDrawer($component, $html),
            default              => $html,
        };
    }

    private static function buildModal(MadComponent $component, string $html): string
    {
        return MadBlade::render('_wrapper-modal', [
            'content' => $html,
            'id'      => $component->_getId(),
            'title'   => $component::getTitle(),
            'size'    => $component::getSize(),
            'closeOnBackdrop' => $component::getCloseOnBackdrop(),
        ]);
    }

    private static function buildDrawer(MadComponent $component, string $html): string
    {
        return MadBlade::render('_wrapper-drawer', [
            'content' => $html,
            'id'      => $component->_getId(),
            'title'   => $component::getTitle(),
            'size'    => $component::getSize(),
            'side'    => $component::getSide(),
            'closeOnBackdrop' => $component::getCloseOnBackdrop(),
        ]);
    }
}