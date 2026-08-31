<?php

namespace Modules\Shop\Filament\Resources;

use Modules\Shop\Filament\Resources\OrderResource\Pages;
use Modules\Shop\Filament\Resources\OrderResource\RelationManagers\ItemsRelationManager;
use Modules\Shop\Filament\Support\ShopResource;
use Modules\Shop\Models\Order;

class OrderResource extends ShopResource
{
    protected static ?string $model = Order::class;

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
        ];
    }
}
