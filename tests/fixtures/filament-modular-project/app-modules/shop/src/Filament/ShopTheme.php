<?php

namespace Modules\Shop\Filament;

/**
 * Negative control: a plain class living in the very directory panel_paths points
 * at. It never builds a panel, so it must not be reported as one — otherwise every
 * class under a source tree would become a panel.
 */
class ShopTheme
{
    public function id(): string
    {
        return 'shop-theme';
    }

    public function path(): string
    {
        return 'theme';
    }
}
