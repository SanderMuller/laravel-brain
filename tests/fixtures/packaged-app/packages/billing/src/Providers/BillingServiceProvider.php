<?php

declare(strict_types=1);

namespace Acme\Billing\Providers;

use Acme\Billing\Support\LedgerInterface;
use Acme\Billing\Support\SqlLedger;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LedgerInterface::class, SqlLedger::class);
    }
}
