<?php

use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\EloquentModelDiscoverer;
use LaraMint\LaravelBrain\Analysis\FacadeAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\GraphSplitter;

it('emits an ERD tab containing model nodes and relationship edges', function () {
    $project = fixture('laravel-project');

    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = (new ControllerAnalyzer)->analyze($project, $routes);
    $traces = (new MethodTracer)->trace($controllers, (new ControllerAnalyzer)->getPsr4Map(), $project);

    $modelFqcns = (new EloquentModelDiscoverer)->discover($project);
    $models = (new ModelAnalyzer)->analyze($project, $modelFqcns);

    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $bindingRegistry = (new ContainerBindingAnalyzer)->analyze($project);
    $facadeRegistry = (new FacadeAnalyzer)->analyze($project);

    $graph = (new GraphBuilder)->build(
        'test', $routes, $middlewareRegistry, $controllers, $traces, $models,
        $project, [], $bindingRegistry, $facadeRegistry, [],
    );

    $split = (new GraphSplitter)->split($graph, $routes, [], [], [], 'test', date('c'));

    expect($split['subgraphs'])->toHaveKey('erd');
    $erd = $split['subgraphs']['erd'];

    expect($erd->nodeCount())->toBeGreaterThan(0);

    // All nodes in the ERD must be model nodes
    foreach ($erd->nodes() as $node) {
        expect($node->type)->toBe('model');
    }
    // All edges in the ERD must be model-relationship edges
    foreach ($erd->edges() as $edge) {
        expect($edge->type)->toBe('model-relationship');
    }

    $erdManifest = array_values(array_filter($split['manifest'], fn ($m) => $m->id === 'erd'));
    expect($erdManifest)->toHaveCount(1);
    expect($erdManifest[0]->category)->toBe('ERD');
});
