<?php

declare(strict_types=1);

namespace Mad\Ui\Menu;

/**
 * Provedor de contador e de visibilidade por item do menu.
 *
 * O app implementa (ou estende BaseMenuItemProvider) e registra com
 * `MadMenu::provider(MinhaClasse::class)` ou pela config `mad.menu.providers`.
 * O casco consulta o provedor ao desenhar o menu — lateral, rail de módulos,
 * menu superior, mega-menu, atalhos do topo e a busca ⌘K — uma vez por item
 * e por requisição.
 *
 * Visibilidade NÃO é controle de acesso: um item escondido some do HTML, mas a
 * tela continua abrindo por URL para quem tem a permissão. Proteja a tela na
 * própria tela (ou no grupo de permissão) quando ela for sensível.
 */
interface MenuItemProvider
{
    /**
     * Número (ou texto curto) exibido ao lado do rótulo. `null`, `0` ou `''`
     * = sem contador.
     */
    public function badge(MenuItem $item): int|string|null;

    /** `false` tira o item do menu. Módulo que fica sem item some junto. */
    public function visible(MenuItem $item): bool;
}
