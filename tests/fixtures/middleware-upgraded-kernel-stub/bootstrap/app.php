<?php

use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\ThrottleApi;
use Illuminate\Foundation\Application;

// A Laravel 11+ application: this file is where middleware is configured, and
// the Kernel beside it is the one the upgrade left behind.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function ($middleware) {
        $middleware->alias([
            'auth.customer' => AuthenticateCustomer::class,
        ]);

        $middleware->api(append: [
            ThrottleApi::class,
        ]);
    })
    ->create();
