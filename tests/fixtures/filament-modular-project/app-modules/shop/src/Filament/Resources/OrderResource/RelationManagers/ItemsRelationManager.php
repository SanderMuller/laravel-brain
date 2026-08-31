<?php

namespace Modules\Shop\Filament\Resources\OrderResource\RelationManagers;

use Modules\Shop\Filament\Support\ShopRelationManager;

class ItemsRelationManager extends ShopRelationManager
{
    protected static string $relationship = 'items';
}
