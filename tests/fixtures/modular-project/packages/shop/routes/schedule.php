<?php

use Acme\Shop\Jobs\ReconcilePayouts;
use Illuminate\Support\Facades\Schedule;

Schedule::command('shop:sync-orders')->dailyAt('05:30')->withoutOverlapping();

Schedule::job(ReconcilePayouts::class)->hourly();
