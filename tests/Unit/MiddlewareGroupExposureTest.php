<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;

// =============================================================================
// Middleware groups. `->middleware('api')` is one name standing for a list, and
// a group is exactly where an application puts the guard it wants on a set of
// routes — so reading the name as a guard of its own classified every route
// behind it as public.
// =============================================================================

it('classifies a route guarded only by a group member as authed', function () {
    $route = makeSecurityRoute('POST', '/records', ['api']);
    $registry = new MiddlewareRegistry([], ['api' => ['throttle:60,1', 'auth:sanctum']], []);

    expect(exposure($route, $registry))->toBe('authed');
});

it('expands a group that lists another group', function () {
    // Laravel supports it and applications use it, so a member that is itself a
    // group yields the inner group's members rather than its own name.
    $route = makeSecurityRoute('POST', '/records', ['api']);
    $registry = new MiddlewareRegistry([], [
        'api' => ['throttle:60,1', 'protected'],
        'protected' => ['auth'],
    ], []);

    expect(exposure($route, $registry))->toBe('authed');
});

it('resolves an alias inside a group through the alias map', function () {
    $route = makeSecurityRoute('POST', '/records', ['api']);
    $registry = new MiddlewareRegistry([], ['api' => ['auth.customer']], [
        'auth.customer' => 'App\\Http\\Middleware\\AuthenticateCustomer',
    ]);

    $analysis = (new SecurityAnalyzer)->analyze([$route], $registry, [], customAuthFixtureRoot());

    // Resolved to the class, which the extends walk then recognises: two steps that only
    // compose when the group is expanded first.
    expect($analysis['route::POST::/records']['exposure'])->toBe('authed');
});

it('keeps a member parameter when it expands a group', function () {
    // `throttle:60,1` inside a group must arrive with its limit, or the throttle
    // check reads a route as unrated that is rate-limited.
    $route = makeSecurityRoute('POST', '/login', ['api']);
    $registry = new MiddlewareRegistry([], ['api' => ['throttle:60,1']], []);

    $analysis = (new SecurityAnalyzer)->analyze([$route], $registry, [], '');

    expect(issueTypes($analysis, 'route::POST::/login'))->not->toContain('MISSING_THROTTLE');
});

it('does not treat a parameterised name as a group', function () {
    // The group lookup is on the whole name, as Laravel's own resolver does it, so
    // `web:something` is an alias with a parameter and not the `web` group.
    $route = makeSecurityRoute('POST', '/records', ['web:tenant']);
    $registry = new MiddlewareRegistry([], ['web' => ['auth']], []);

    expect(exposure($route, $registry))->toBe('public');
});

it('prefers the group when a name is both a group and an alias', function () {
    // Laravel's resolver checks groups before the alias map, so this follows it
    // rather than deciding for itself which of the two an application meant.
    $route = makeSecurityRoute('POST', '/records', ['api']);
    $registry = new MiddlewareRegistry([], ['api' => ['auth']], ['api' => 'App\\Http\\Middleware\\EnsureTokenIsValid']);

    expect(exposure($route, $registry))->toBe('authed');
});

it('terminates on a group that lists itself', function () {
    // Laravel throws on this; a scan reads whatever is on disk, including source
    // nobody has run, so it ends with what it found instead of recursing forever.
    $route = makeSecurityRoute('POST', '/records', ['api']);
    $registry = new MiddlewareRegistry([], ['api' => ['auth', 'api']], []);

    expect(exposure($route, $registry))->toBe('authed');
});

it('terminates on two groups that list each other', function () {
    $route = makeSecurityRoute('POST', '/records', ['api']);
    $registry = new MiddlewareRegistry([], ['api' => ['web'], 'web' => ['api']], []);

    expect(exposure($route, $registry))->toBe('public');
});

it('still matches a pattern against the group name itself', function () {
    // `admin` is one of the admin patterns, and a group is a plausible thing to call `admin`.
    // Expanding it must add what it contains, not take its own name away.
    $route = makeSecurityRoute('POST', '/records', ['admin']);
    $registry = new MiddlewareRegistry([], ['admin' => ['App\\Http\\Middleware\\LogRequest']], []);

    expect(exposure($route, $registry))->toBe('admin');
});

it('still honours a group named in security.auth_middleware', function () {
    // The configured escape hatch for a guard Brain cannot recognise on its own. It is matched by
    // name, so the name has to survive expansion.
    $route = makeSecurityRoute('POST', '/records', ['tenant']);
    $registry = new MiddlewareRegistry([], ['tenant' => ['App\\Http\\Middleware\\LogRequest']], []);

    expect(exposure($route, $registry, ['tenant']))->toBe('authed');
});

it('leaves a route behind an empty group public', function () {
    // An empty group guards nothing, and its name matches no pattern.
    $route = makeSecurityRoute('POST', '/records', ['api']);

    expect(exposure($route, new MiddlewareRegistry([], ['api' => []], [])))->toBe('public');
});
