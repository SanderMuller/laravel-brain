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

// =============================================================================
// False-positive fixes for UNVALIDATED_INPUT, PUBLIC_WRITE and MISSING_THROTTLE
// =============================================================================

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\ControllerDefinition;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

// RouteDefinition and ControllerDefinition are declared as secondary classes
// inside RouteAnalyzer.php and ControllerAnalyzer.php; touching the parent
// classes forces PSR-4 to include those files so the helpers below can
// instantiate the definitions directly.
class_exists(RouteAnalyzer::class);
class_exists(ControllerAnalyzer::class);

/**
 * Build a ControllerDefinition from a fixture file so we can hand the
 * analyzer real AST plus the file path it would normally compute itself.
 */
function securityFixtureController(string $relativePath, string $fqcn): ControllerDefinition
{
    $file = fixture('security-project/'.$relativePath);
    $parsed = (new PhpFileParser)->parse($file);

    return new ControllerDefinition(
        fqcn: $fqcn,
        file: $file,
        constructorDeps: [],
        methods: [],
        useMap: $parsed['useMap'] ?? [],
    );
}

function securityProjectRoot(): string
{
    return fixture('security-project');
}

function emptyMiddlewareRegistry(): MiddlewareRegistry
{
    return new MiddlewareRegistry([], [], []);
}

/**
 * @param  array<string, array>  $analysis
 * @return list<string>
 */
function issueTypes(array $analysis, string $routeId): array
{
    return array_values(array_map(
        fn (array $i) => $i['type'],
        $analysis[$routeId]['issues'] ?? [],
    ));
}

// ─── Bug 1 — UNVALIDATED_INPUT must require a Request receiver ───────────────

it('does not flag Collection->values()->all() as UNVALIDATED_INPUT', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'GET',
        uri: '/admin/developers',
        controller: 'App\\Http\\Controllers\\CollectionAllController',
        action: 'indexCollectionOnly',
        middlewares: ['auth'],
        name: 'developers.index',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\CollectionAllController' => securityFixtureController(
            'app/Http/Controllers/CollectionAllController.php',
            'App\\Http\\Controllers\\CollectionAllController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::GET::/admin/developers'))
        ->not->toContain('UNVALIDATED_INPUT');
});

it('does not flag Eloquent Collection ->all() as UNVALIDATED_INPUT', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'GET',
        uri: '/users',
        controller: 'App\\Http\\Controllers\\CollectionAllController',
        action: 'indexEloquentCollection',
        middlewares: ['auth'],
        name: 'users.index',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\CollectionAllController' => securityFixtureController(
            'app/Http/Controllers/CollectionAllController.php',
            'App\\Http\\Controllers\\CollectionAllController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::GET::/users'))
        ->not->toContain('UNVALIDATED_INPUT');
});

it('still flags request()->all() (helper) as UNVALIDATED_INPUT', function () {
    // Action takes no FormRequest type-hint, so the existing
    // methodHasFormRequest heuristic does not short-circuit Pass 2.
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/store-helper',
        controller: 'App\\Http\\Controllers\\CollectionAllController',
        action: 'storeWithRequestHelper',
        middlewares: ['auth'],
        name: 'store.helper',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\CollectionAllController' => securityFixtureController(
            'app/Http/Controllers/CollectionAllController.php',
            'App\\Http\\Controllers\\CollectionAllController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/store-helper'))
        ->toContain('UNVALIDATED_INPUT');
});

// ─── Bug 2 — signed / trusted routes ─────────────────────────────────────────

it('treats `signed` middleware as authentication, not public', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/downloads/{file}',
        controller: 'App\\Http\\Controllers\\SignedDownloadController',
        action: 'download',
        middlewares: ['signed'],
        name: 'downloads.show',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\SignedDownloadController' => securityFixtureController(
            'app/Http/Controllers/SignedDownloadController.php',
            'App\\Http\\Controllers\\SignedDownloadController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect($analysis['route::POST::/downloads/{file}']['exposure'])->toBe('authed')
        ->and(issueTypes($analysis, 'route::POST::/downloads/{file}'))->not->toContain('PUBLIC_WRITE');
});

it('treats `ValidateSignature` FQCN middleware as authentication', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/downloads/{file}',
        controller: 'App\\Http\\Controllers\\SignedDownloadController',
        action: 'download',
        middlewares: ['Illuminate\\Routing\\Middleware\\ValidateSignature'],
        name: 'downloads.show',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\SignedDownloadController' => securityFixtureController(
            'app/Http/Controllers/SignedDownloadController.php',
            'App\\Http\\Controllers\\SignedDownloadController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect($analysis['route::POST::/downloads/{file}']['exposure'])->toBe('authed');
});

it('suppresses PUBLIC_WRITE on routes matching trusted_route_names glob', function () {
    $analyzer = new SecurityAnalyzer(trustedRouteNames: ['webhooks.*']);

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/webhooks/stripe',
        controller: 'App\\Http\\Controllers\\WebhookController',
        action: 'stripe',
        middlewares: [],
        name: 'webhooks.stripe',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\WebhookController' => securityFixtureController(
            'app/Http/Controllers/WebhookController.php',
            'App\\Http\\Controllers\\WebhookController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/webhooks/stripe'))
        ->not->toContain('PUBLIC_WRITE');
});

it('suppresses PUBLIC_WRITE on routes matching trusted_route_uris glob', function () {
    $analyzer = new SecurityAnalyzer(trustedRouteUris: ['webhooks/*']);

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/webhooks/stripe/tenant-1',
        controller: 'App\\Http\\Controllers\\WebhookController',
        action: 'stripe',
        middlewares: [],
        name: '', // no name → trusted_route_names cannot match
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\WebhookController' => securityFixtureController(
            'app/Http/Controllers/WebhookController.php',
            'App\\Http\\Controllers\\WebhookController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/webhooks/stripe/tenant-1'))
        ->not->toContain('PUBLIC_WRITE');
});

it('still flags public mutating routes that are NOT trusted', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/articles',
        controller: 'App\\Http\\Controllers\\WebhookController',
        action: 'stripe',
        middlewares: [],
        name: 'articles.store',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\WebhookController' => securityFixtureController(
            'app/Http/Controllers/WebhookController.php',
            'App\\Http\\Controllers\\WebhookController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/articles'))
        ->toContain('PUBLIC_WRITE');
});

// ─── Bug 3 — in-controller / in-FormRequest throttle detection ───────────────

it('does not flag MISSING_THROTTLE when controller uses RateLimiter::tooManyAttempts', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/login',
        controller: 'App\\Http\\Controllers\\LoginController',
        action: 'store',
        middlewares: [], // no throttle middleware
        name: 'login.store',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\LoginController' => securityFixtureController(
            'app/Http/Controllers/LoginController.php',
            'App\\Http\\Controllers\\LoginController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/login'))
        ->not->toContain('MISSING_THROTTLE');
});

it('does not flag MISSING_THROTTLE when the action FormRequest throttles', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/login',
        controller: 'App\\Http\\Controllers\\LoginController',
        action: 'loginViaRequest',
        middlewares: [],
        name: 'login.via-request',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\LoginController' => securityFixtureController(
            'app/Http/Controllers/LoginController.php',
            'App\\Http\\Controllers\\LoginController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/login'))
        ->not->toContain('MISSING_THROTTLE');
});

it('still flags MISSING_THROTTLE on sensitive routes with no rate-limiting', function () {
    $analyzer = new SecurityAnalyzer;

    $route = new RouteDefinition(
        method: 'POST',
        uri: '/login-noop',
        controller: 'App\\Http\\Controllers\\WebhookController',
        action: 'stripe', // does not call RateLimiter / has no FormRequest
        middlewares: [],
        name: 'login.noop',
        file: '',
        line: 0,
    );

    $controllers = [
        'App\\Http\\Controllers\\WebhookController' => securityFixtureController(
            'app/Http/Controllers/WebhookController.php',
            'App\\Http\\Controllers\\WebhookController',
        ),
    ];

    $analysis = $analyzer->analyze([$route], emptyMiddlewareRegistry(), $controllers, securityProjectRoot());

    expect(issueTypes($analysis, 'route::POST::/login-noop'))
        ->toContain('MISSING_THROTTLE');
});
