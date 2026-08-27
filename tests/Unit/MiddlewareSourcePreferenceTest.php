<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MiddlewareAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;

// =============================================================================
// Which file MiddlewareAnalyzer::analyze() reads is decided by which one
// configures middleware, not by which one exists. An application upgraded to
// Laravel 11+ commonly keeps app/Http/Kernel.php behind; the framework stops
// reading it, and so must this.
// =============================================================================

it('reads bootstrap/app.php when a Kernel is left behind by an upgrade', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-upgraded-kernel-stub'));

    expect($registry->aliases)
        ->toHaveKey('auth.customer')
        ->and($registry->aliases['auth.customer'])->toBe('App\\Http\\Middleware\\AuthenticateCustomer');
});

it('does not resurrect an alias from a Kernel the framework no longer reads', function () {
    // The stub declares `auth.retired`, naming a guard the application dropped.
    // Reading it would resolve that alias to a class no route runs.
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-upgraded-kernel-stub'));

    expect($registry->aliases)->not->toHaveKey('auth.retired');
});

it('reads the groups bootstrap/app.php appends when a Kernel is left behind', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-upgraded-kernel-stub'));

    expect($registry->resolveGroup('api'))->toBe(['App\\Http\\Middleware\\ThrottleApi']);
});

it('still reads the Kernel of a Laravel 10 application', function () {
    // Its bootstrap/app.php exists too — every application has one — but it
    // configures no middleware, so it declines and the Kernel answers.
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-legacy-kernel'));

    expect($registry->aliases)
        ->toHaveKey('auth.customer')
        ->and($registry->aliases['auth.customer'])->toBe('App\\Http\\Middleware\\AuthenticateCustomer')
        ->and($registry->resolveGroup('api'))->toBe(['App\\Http\\Middleware\\ThrottleApi']);
});

it('returns an empty registry when neither file configures middleware', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('custom-auth-project'));

    expect($registry->aliases)->toBe([])
        ->and($registry->groups)->toBe([])
        ->and($registry->global)->toBe([]);
});

it('classifies a route behind the live alias as authed rather than drawing a false PUBLIC_WRITE', function () {
    // What reading the wrong file costs, end to end. Unresolved, `auth.customer`
    // matches no auth pattern — `auth` matches `auth`, `auth:…` and `auth\…`,
    // never a dotted sibling — so a guarded mutating route was reported as one
    // anyone can call.
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-upgraded-kernel-stub'));

    $route = new RouteDefinition(
        method: 'DELETE',
        uri: '/records/{record}',
        controller: '',
        action: 'destroy',
        middlewares: ['auth.customer'],
        name: '',
        file: '',
        line: 1,
    );

    $analysis = (new SecurityAnalyzer)->analyze([$route], $registry, [], fixture('middleware-upgraded-kernel-stub'));

    $routeId = 'route::DELETE::/records/{record}';
    expect($analysis[$routeId]['exposure'])->toBe('authed')
        ->and(array_column($analysis[$routeId]['issues'], 'type'))->not->toContain('PUBLIC_WRITE');
});
