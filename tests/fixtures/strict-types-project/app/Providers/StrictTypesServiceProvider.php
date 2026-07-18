<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ClockInterface;
use App\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

class StrictTypesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, SystemClock::class);
    }
}
