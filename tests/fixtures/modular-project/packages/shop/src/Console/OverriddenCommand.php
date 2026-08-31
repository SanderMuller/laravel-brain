<?php

namespace Acme\Shop\Console;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shop:from-attribute')]
class OverriddenCommand extends Command
{
    protected $signature = 'shop:from-property';
}
