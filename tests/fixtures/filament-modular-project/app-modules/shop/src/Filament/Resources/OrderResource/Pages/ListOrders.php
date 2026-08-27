<?php

namespace Modules\Shop\Filament\Resources\OrderResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Shop\Filament\Resources\OrderResource;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
