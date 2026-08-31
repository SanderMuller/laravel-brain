<?php

namespace App\Http;

use App\Http\Middleware\RetiredGuard;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * Left behind by the upgrade to Laravel 11. Nothing loads it any more, and its
 * aliases name a guard the application dropped — reading it in preference to
 * bootstrap/app.php is how a live alias goes missing and a dead one arrives.
 */
class Kernel extends HttpKernel
{
    protected $middlewareAliases = [
        'auth.retired' => RetiredGuard::class,
    ];
}
