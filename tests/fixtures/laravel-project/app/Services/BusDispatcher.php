<?php

namespace App\Services;

use App\Jobs\ChargeOrder;
use App\Jobs\NotifyWarehouse;
use App\Jobs\ReindexOrder;
use App\Jobs\ShipOrder;
use Illuminate\Support\Facades\Bus;

class BusDispatcher
{
    public function dispatchAll(): void
    {
        Bus::dispatch(new ShipOrder);
        Bus::chain([new ChargeOrder, new NotifyWarehouse]);
        Bus::batch([new ReindexOrder]);
    }
}
