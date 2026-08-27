<?php

use App\Http\Middleware\AuthenticateNamed;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function ($middleware) {
        // Named-argument form — args[0] is a Node\Arg whose value is still the
        // array, so Form B handles it.
        $middleware->alias(aliases: [
            'auth.named' => AuthenticateNamed::class,
        ]);

        // First-class callable syntax — args[0] is a VariadicPlaceholder with no
        // `->value`. Must not crash the scan (nothing to register here).
        $middleware->alias(...);
    })
    ->create();
