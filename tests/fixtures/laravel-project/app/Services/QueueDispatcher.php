<?php

namespace App\Services;

use App\Jobs\ProcessReport;
use App\Jobs\RebuildCache;
use App\Jobs\SyncInventory;

class QueueDispatcher
{
    public function run(): void
    {
        $this->dispatch(new ProcessReport);
        $this->dispatchSync(new SyncInventory);
        dispatch_sync(new RebuildCache);
    }
}
