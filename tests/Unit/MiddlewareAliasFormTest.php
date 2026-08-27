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

// =============================================================================
// Group declarations in a Laravel 11+ bootstrap/app.php. `web` and `api` are
// modified through their own methods; every other group an application has is
// declared with group(), and added to with appendToGroup()/prependToGroup().
// =============================================================================

it('reads a group declared with $middleware->group()', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-group-declarations'));

    expect($registry->resolveGroup('admin'))->toContain('App\\Http\\Middleware\\AuthenticateCustomer')
        ->and($registry->resolveGroup('admin'))->toContain('can:administer');
});

it('appends and prepends to a declared group in the right order', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-group-declarations'));

    expect($registry->resolveGroup('admin'))->toBe([
        'App\\Http\\Middleware\\EnsureTenantMatches',
        'App\\Http\\Middleware\\AuthenticateCustomer',
        'can:administer',
        'App\\Http\\Middleware\\ThrottleAdmin',
    ]);
});

it('accepts a bare class string, which Laravel wraps for the caller', function () {
    // `prependToGroup('admin', EnsureTenantMatches::class)` takes array|string.
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-group-declarations'));

    expect($registry->resolveGroup('admin')[0])->toBe('App\\Http\\Middleware\\EnsureTenantMatches');
});

it('records a group that only ever had members added to it', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-group-declarations'));

    expect($registry->resolveGroup('tenant'))->toBe(['App\\Http\\Middleware\\LogRequest']);
});
