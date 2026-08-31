<?php

namespace Acme\Shop\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shop:import-products', description: 'Import the product feed')]
class ImportProductsCommand extends Command
{
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
