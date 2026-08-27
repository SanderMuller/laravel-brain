<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MiddlewareAnalyzer;

// =============================================================================
// MiddlewareAnalyzer::extractAliases() must read both alias-registration forms
// from a Laravel 11+ bootstrap/app.php:
//   Form A: $middleware->alias('key', Class::class)
//   Form B: $middleware->alias(['key' => Class::class, ...])   ← Laravel default
// =============================================================================

it('extracts array-form $middleware->alias([...]) registrations', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-array-alias'));

    expect($registry->aliases)
        ->toHaveKey('auth.customer')
        ->and($registry->aliases['auth.customer'])->toBe('App\\Http\\Middleware\\AuthenticateCustomer')
        ->and($registry->aliases)->toHaveKey('auth.admin')
        ->and($registry->aliases['auth.admin'])->toBe('App\\Http\\Middleware\\AuthenticateAdmin');
});

it('still extracts the two-argument $middleware->alias(key, class) form', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-array-alias'));

    expect($registry->aliases)
        ->toHaveKey('legacy.guard')
        ->and($registry->aliases['legacy.guard'])->toBe('App\\Http\\Middleware\\LegacyGuard');
});

it('registers the named-argument $middleware->alias(aliases: [...]) form', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-alias-edge-cases'));

    expect($registry->aliases)
        ->toHaveKey('auth.named')
        ->and($registry->aliases['auth.named'])->toBe('App\\Http\\Middleware\\AuthenticateNamed');
});

it('does not crash on the first-class callable $middleware->alias(...) form', function () {
    // A VariadicPlaceholder in args[0] must not raise a warning (which Laravel's
    // HandleExceptions would turn into an ErrorException and kill the scan).
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-alias-edge-cases'));

    // The callable form registers nothing; the named-arg form above still works.
    expect($registry->aliases)->not->toHaveKey('...')
        ->and($registry->aliases)->toHaveKey('auth.named');
});
