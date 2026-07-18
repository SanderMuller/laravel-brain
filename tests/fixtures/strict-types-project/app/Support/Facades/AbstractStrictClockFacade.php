<?php

declare(strict_types=1);

namespace App\Support\Facades;

use App\Support\SystemClock;
use Illuminate\Support\Facades\Facade;

abstract class AbstractStrictClockFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SystemClock::class;
    }
}
