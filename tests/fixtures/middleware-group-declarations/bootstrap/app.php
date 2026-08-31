<?php

use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\EnsureTenantMatches;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\ThrottleAdmin;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function ($middleware) {
        // A group of the application's own: the framework's web and api are modified
        // through their own methods, every other group is declared here.
        $middleware->group('admin', [
            AuthenticateCustomer::class,
            'can:administer',
        ]);

        // Added to afterwards, in both directions.
        $middleware->appendToGroup('admin', [ThrottleAdmin::class]);
        $middleware->prependToGroup('admin', EnsureTenantMatches::class);

        // A group nobody declared, added to all the same.
        $middleware->appendToGroup('tenant', [LogRequest::class]);
    })
    ->create();
