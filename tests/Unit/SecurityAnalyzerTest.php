<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;

function makeSecurityRoute(string $method, string $uri, array $middlewares): RouteDefinition
{
    return new RouteDefinition(
        method: $method,
        uri: $uri,
        controller: '',
        action: 'index',
        middlewares: $middlewares,
        name: '',
        file: '',
        line: 1,
    );
}

function exposure(RouteDefinition $route, MiddlewareRegistry $registry, array $extraAuthPatterns = []): string
{
    $analyzer = new SecurityAnalyzer(extraAuthPatterns: $extraAuthPatterns);
    $results = $analyzer->analyze([$route], $registry, [], '');
    $routeId = "route::{$route->method}::{$route->uri}";

    return $results[$routeId]['exposure'];
}

it('classifies a route with no auth middleware as public', function () {
    $route = makeSecurityRoute('GET', '/open', ['web']);
    $registry = new MiddlewareRegistry([], [], []);

    expect(exposure($route, $registry))->toBe('public');
});

it('classifies a route with auth:sanctum as authed', function () {
    $route = makeSecurityRoute('GET', '/dashboard', ['auth:sanctum']);
    $registry = new MiddlewareRegistry([], [], []);

    expect(exposure($route, $registry))->toBe('authed');
});

it('classifies a route with the Illuminate Authenticate FQCN as authed', function () {
    $route = makeSecurityRoute('GET', '/dashboard', ['auth:api']);
    $registry = new MiddlewareRegistry([], [], [
        'auth' => 'Illuminate\Auth\Middleware\Authenticate',
    ]);

    expect(exposure($route, $registry))->toBe('authed');
});

it('classifies a custom auth alias as public when no extra patterns are configured', function () {
    $route = makeSecurityRoute('GET', '/dashboard', ['auth.custom:api']);
    $registry = new MiddlewareRegistry([], [], [
        'auth.custom' => 'App\Http\Middleware\CustomAuth',
    ]);

    expect(exposure($route, $registry))->toBe('public');
});

it('classifies a custom auth alias as authed when the alias is in extra patterns', function () {
    $route = makeSecurityRoute('GET', '/dashboard', ['auth.custom:api']);
    $registry = new MiddlewareRegistry([], [], []);

    expect(exposure($route, $registry, ['auth.custom']))->toBe('authed');
});

it('classifies a custom auth alias as authed when its resolved FQCN is in extra patterns', function () {
    $route = makeSecurityRoute('GET', '/dashboard', ['auth.custom:api']);
    $registry = new MiddlewareRegistry([], [], [
        'auth.custom' => 'App\Http\Middleware\CustomAuth',
    ]);

    expect(exposure($route, $registry, ['App\Http\Middleware\CustomAuth']))->toBe('authed');
});
