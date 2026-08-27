<?php

namespace Modules\Shop\Filament;

use Filament\Facades\Filament;
use Filament\Panel;

/**
 * A panel built outside the *PanelProvider convention: the module registers it
 * itself, so the file is named after the panel rather than after a provider.
 */
class ShopPanel
{
    public function register(): void
    {
        Filament::registerPanel($this->panel());
    }

    private function panel(): Panel
    {
        return Panel::make()
            ->id('shop')
            ->path('shop')
            ->discoverResources(in: __DIR__.'/Resources', for: 'Modules\\Shop\\Filament\\Resources')
            ->discoverPages(in: __DIR__.'/Pages', for: 'Modules\\Shop\\Filament\\Pages');
    }
}
