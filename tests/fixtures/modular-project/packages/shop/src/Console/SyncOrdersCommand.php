<?php

namespace Acme\Shop\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shop:sync-orders {--since= : Only orders updated after this date}')]
#[Description('Pull orders from the storefront')]
class SyncOrdersCommand extends Command
{
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
