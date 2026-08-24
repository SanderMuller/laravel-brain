<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\LegacyGuard;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function ($middleware) {
        // Form B — array-style alias map. This is the form Laravel's docs and
        // the bootstrap/app.php skeleton use, so most real apps register their
        // custom guards this way.
        $middleware->alias([
            'auth.customer' => AuthenticateCustomer::class,
            'auth.admin' => AuthenticateAdmin::class,
        ]);

        // Form A — two-argument style, still supported.
        $middleware->alias('legacy.guard', LegacyGuard::class);
    })
    ->create();
