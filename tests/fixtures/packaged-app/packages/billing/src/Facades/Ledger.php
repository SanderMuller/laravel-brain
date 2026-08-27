<?php

namespace Acme\Billing\Facades;

use Acme\Billing\Support\LedgerService;
use Illuminate\Support\Facades\Facade;

class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerService::class;
    }
}
