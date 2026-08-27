<?php

declare(strict_types=1);

namespace Acme\Billing\Providers\Nested;

use Acme\Billing\Support\InvoiceNumberer;
use Acme\Billing\Support\SequentialInvoiceNumberer;
use Illuminate\Support\ServiceProvider;

/**
 * Two directory levels below the configured provider path — deeper than a glob
 * can reach without crossing separators.
 */
class InvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvoiceNumberer::class, SequentialInvoiceNumberer::class);
    }
}
