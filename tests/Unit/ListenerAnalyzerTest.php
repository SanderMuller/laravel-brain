<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\ListenerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

it('discovers an event → listener edge by convention', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\HandleUserLoggedIn'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\UserLoggedIn')
        ->callerMethod->toBe('__construct')
        ->calleeMethod->toBe('handle')
        ->type->toBe('listener');
});

it('discovers an invokable listener', function () {
    $edges = (new ListenerAnalyzer)->analyze(fixture('laravel-project'));

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Listeners\\LogUserLoggedIn'
    ));

    expect($match)->toHaveCount(1);
    expect($match[0])
        ->callerFqcn->toBe('App\\Events\\UserLoggedIn')
        ->type->toBe('listener');
});

it('connects a dispatched event to its listener in the graph', function () {
    $project = fixture('laravel-project');
    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = (new ControllerAnalyzer)->analyze($project, $routes);
    $traces = (new MethodTracer)->trace($controllers);
    $traces = array_merge($traces, (new ListenerAnalyzer)->analyze($project));

    $graph = (new GraphBuilder)->build('test', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, []);

    $listenerNodes = array_filter($graph->nodes(), fn ($n) => $n->type === 'listener');
    expect($listenerNodes)->not->toBeEmpty();

    $listenerNode = array_values($listenerNodes)[0];
    $edge = array_values(array_filter(
        $graph->edges(),
        fn ($e) => $e->target === $listenerNode->id
    ));

    expect($edge)->not->toBeEmpty();
    expect($edge[0]->label)->toBe('handled by');
});
