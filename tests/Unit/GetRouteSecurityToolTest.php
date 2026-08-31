<?php

use LaraMint\LaravelBrain\Mcp\Tools\GetRouteSecurityTool;

/**
 * @param  list<array<string, mixed>>  $routeNodes
 * @return array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
 */
function routeSecuritySampleGraph(array $routeNodes): array
{
    return [
        'meta' => ['project' => 'demo'],
        'nodes' => $routeNodes,
        'edges' => [],
    ];
}

function routeSecurityNode(string $id, string $uri, string $exposure, string $riskLevel, array $issues = []): array
{
    return [
        'id' => $id,
        'type' => 'route',
        'label' => $uri,
        'data' => [
            'method' => 'GET',
            'uri' => $uri,
            'name' => null,
            'security' => [
                'exposure' => $exposure,
                'riskLevel' => $riskLevel,
                'issues' => $issues,
            ],
        ],
    ];
}

it('returns every route when no filter is given', function () {
    $graph = routeSecuritySampleGraph([
        routeSecurityNode('route::GET::/orders', '/orders', 'authed', 'low'),
        routeSecurityNode('route::GET::/health', '/health', 'public', 'none'),
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, null, null, null);

    expect($routes)->toHaveCount(2);
});

it('filters by exposure', function () {
    $graph = routeSecuritySampleGraph([
        routeSecurityNode('route::GET::/orders', '/orders', 'authed', 'low'),
        routeSecurityNode('route::GET::/health', '/health', 'public', 'none'),
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, 'public', null, null);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['uri'])->toBe('/health');
});

it('filters by risk level', function () {
    $graph = routeSecuritySampleGraph([
        routeSecurityNode('route::POST::/orders', '/orders', 'public', 'critical', [
            ['type' => 'MASS_ASSIGNMENT', 'severity' => 'critical', 'file' => null, 'line' => null, 'message' => 'x'],
        ]),
        routeSecurityNode('route::GET::/health', '/health', 'public', 'none'),
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, null, 'critical', null);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['issues'])->toHaveCount(1);
});

it('filters by a uri substring', function () {
    $graph = routeSecuritySampleGraph([
        routeSecurityNode('route::GET::/api/orders', '/api/orders', 'authed', 'low'),
        routeSecurityNode('route::GET::/web/dashboard', '/web/dashboard', 'authed', 'low'),
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, null, null, 'orders');

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['uri'])->toBe('/api/orders');
});

it('combines filters with AND semantics', function () {
    $graph = routeSecuritySampleGraph([
        routeSecurityNode('route::GET::/api/orders', '/api/orders', 'public', 'high'),
        routeSecurityNode('route::GET::/api/health', '/api/health', 'public', 'none'),
        routeSecurityNode('route::GET::/web/orders', '/web/orders', 'authed', 'high'),
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, 'public', 'high', 'orders');

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['routeId'])->toBe('route::GET::/api/orders');
});

it('ignores non-route nodes entirely', function () {
    $graph = routeSecuritySampleGraph([
        routeSecurityNode('route::GET::/orders', '/orders', 'public', 'none'),
        ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place', 'data' => []],
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, null, null, null);

    expect($routes)->toHaveCount(1);
});

it('defaults exposure and risk level when a route has no security data', function () {
    $graph = routeSecuritySampleGraph([
        ['id' => 'route::GET::/legacy', 'type' => 'route', 'label' => '/legacy', 'data' => ['method' => 'GET', 'uri' => '/legacy']],
    ]);

    $routes = GetRouteSecurityTool::filterRoutes($graph, null, null, null);

    expect($routes[0]['exposure'])->toBe('unknown')
        ->and($routes[0]['riskLevel'])->toBe('none')
        ->and($routes[0]['issues'])->toBe([]);
});

it('returns an empty list from an empty graph', function () {
    expect(GetRouteSecurityTool::filterRoutes(routeSecuritySampleGraph([]), null, null, null))->toBe([]);
});
