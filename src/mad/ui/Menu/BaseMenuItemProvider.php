<?php

declare(strict_types=1);

namespace Mad\Ui\Menu;

/**
 * Base com o comportamento neutro (sem contador, tudo visível): o app
 * sobrescreve só o método de que precisa.
 */
abstract class BaseMenuItemProvider implements MenuItemProvider
{
    public function badge(MenuItem $item): int|string|null
    {
        return null;
    }

    public function visible(MenuItem $item): bool
    {
        return true;
    }
}
