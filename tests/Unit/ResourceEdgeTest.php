<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function resourceEdges(string $fqcn, string $method): array
{
    $project = fixture('laravel-project');

    return (new MethodTracer)->traceMethod($fqcn, $method, ['App\\' => [$project.'/app']], $project);
}

$UserApi = 'App\\Http\\Controllers\\Api\\UserApiController';
$UserResource = 'App\\Http\\Resources\\UserResource';
$OrderResource = 'App\\Http\\Resources\\OrderResource';

it('emits a resource edge for Resource::make()', function () use ($UserApi, $UserResource) {
    $edges = resourceEdges($UserApi, 'show');

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->type === 'resource' && $e->calleeFqcn === $UserResource
    ));

    expect($match)->not->toBeEmpty();
    expect($match[0])->calleeMethod->toBe('toArray');
});

it('emits a resource edge for Resource::collection()', function () use ($UserApi, $UserResource) {
    $edges = resourceEdges($UserApi, 'index');

    expect(array_filter($edges, fn ($e) => $e->type === 'resource' && $e->calleeFqcn === $UserResource))
        ->not->toBeEmpty();
});

it('emits a resource edge for new Resource()', function () use ($UserApi, $UserResource) {
    $edges = resourceEdges($UserApi, 'latest');

    expect(array_filter($edges, fn ($e) => $e->type === 'resource' && $e->calleeFqcn === $UserResource))
        ->not->toBeEmpty();
});

it('descends into a resource to link its nested resource composition', function () use ($UserApi, $UserResource, $OrderResource) {
    // Tracing the controller recurses into UserResource::toArray(), which
    // composes OrderResource via both ::collection() and new.
    $edges = resourceEdges($UserApi, 'show');

    $nested = array_values(array_filter(
        $edges,
        fn ($e) => $e->type === 'resource' && $e->callerFqcn === $UserResource && $e->calleeFqcn === $OrderResource
    ));

    expect($nested)->not->toBeEmpty();
});

it('still traces the model queried alongside the resource', function () use ($UserApi) {
    $edges = resourceEdges($UserApi, 'show');

    // The resource recognition must not swallow the User::findOrFail() model hop.
    expect(array_filter($edges, fn ($e) => $e->type === 'model' && $e->calleeFqcn === 'App\\Models\\User'))
        ->not->toBeEmpty();
});

it('does not emit a self-loop edge for a recursively composed resource', function () use ($OrderResource) {
    // OrderResource::toArray composes OrderResource::collection (a tree) — no self-edge.
    $edges = resourceEdges($OrderResource, 'toArray');

    expect(array_filter($edges, fn ($e) => $e->callerFqcn === $OrderResource && $e->calleeFqcn === $OrderResource))
        ->toBe([]);
});

it('does not emit an edge for the framework JsonResource base class', function () use ($UserApi) {
    foreach (['framework', 'frameworkNew'] as $method) {
        $edges = resourceEdges($UserApi, $method);
        expect(array_filter($edges, fn ($e) => $e->type === 'resource'))->toBe([]);
    }
});

it('does not resolve a new self()/static() to a phantom resource', function () use ($OrderResource) {
    // OrderResource::toArray composes itself via OrderResource::collection and new static() —
    // both are self-references, so no edge (and certainly no \Http\Resources\static phantom).
    $callees = array_map(fn ($e) => $e->calleeFqcn, resourceEdges($OrderResource, 'toArray'));

    expect($callees)
        ->not->toContain('App\\Http\\Resources\\static')
        ->not->toContain('App\\Http\\Resources\\self')
        ->not->toContain($OrderResource);
});

it('does not treat a Filament resource as an API resource', function () {
    $tracer = new MethodTracer;

    expect($tracer->looksLikeResource('App\\Http\\Resources\\UserResource'))->toBeTrue();
    expect($tracer->looksLikeResource('App\\Filament\\Resources\\UserResource'))->toBeFalse();
    expect($tracer->looksLikeResource('App\\Models\\User'))->toBeFalse();
});

it('wires action → resource and resource → resource edges into the graph', function () use ($UserApi, $UserResource, $OrderResource) {
    $edges = resourceEdges($UserApi, 'show');

    $graph = (new GraphBuilder)->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);

    $resourceNodes = array_filter($graph->nodes(), fn ($n) => $n->type === 'resource');
    expect($resourceNodes)->not->toBeEmpty();

    $labels = array_map(
        fn ($e) => $e->label,
        array_filter($graph->edges(), fn ($e) => $e->type === 'action-to-resource')
    );
    expect($labels)->toContain('transforms');

    $fqcns = array_map(fn ($n) => $n->data['fqcn'], $resourceNodes);
    expect($fqcns)->toContain($UserResource)->toContain($OrderResource);
});
