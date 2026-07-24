<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\Incremental\GraphProvenance;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function buildFixtureGraph(): Graph
{
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $middlewareRegistry = new MiddlewareRegistry([], [], []);
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
    $traces = (new MethodTracer)->trace($controllers);
    $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
    $models = (new ModelAnalyzer)->analyze(fixture('laravel-project'), $modelFqcns);

    return (new GraphBuilder)->build('test', $routes, $middlewareRegistry, $controllers, $traces, $models);
}

it('partitions every node and edge into exactly one owning file (lossless round-trip)', function () {
    $graph = buildFixtureGraph();
    $prov = GraphProvenance::of($graph);

    $allNodeIds = array_map(fn ($n) => $n->id, $graph->nodes());
    $allEdgeIds = array_map(fn ($e) => $e->id, $graph->edges());

    // Every element is assigned to exactly one file, and the partition covers the whole graph.
    expect(count($prov->nodeFile))->toBe(count($allNodeIds));
    expect(count($prov->edgeFile))->toBe(count($allEdgeIds));

    $partitionNodeIds = [];
    $partitionEdgeIds = [];
    foreach ($prov->byFile as $bucket) {
        $partitionNodeIds = array_merge($partitionNodeIds, $bucket['nodes'] ?? []);
        $partitionEdgeIds = array_merge($partitionEdgeIds, $bucket['edges'] ?? []);
    }

    sort($allNodeIds);
    sort($allEdgeIds);
    sort($partitionNodeIds);
    sort($partitionEdgeIds);

    expect($partitionNodeIds)->toBe($allNodeIds);   // no element lost or duplicated
    expect($partitionEdgeIds)->toBe($allEdgeIds);
});

it('resolves the elements owned by a specific file', function () {
    $graph = buildFixtureGraph();
    $prov = GraphProvenance::of($graph);

    // A file that actually owns nodes should return a non-empty, in-graph set.
    $ownedFiles = array_values(array_filter(array_keys($prov->byFile), fn ($f) => $f !== ''));
    expect($ownedFiles)->not->toBeEmpty();

    $someFile = $ownedFiles[0];
    $nodeIds = $prov->nodeIdsForFiles($someFile);
    expect($nodeIds)->not->toBeEmpty();
    foreach ($nodeIds as $id) {
        expect($graph->hasNode($id))->toBeTrue();
        expect($prov->nodeFile[$id])->toBe($someFile);
    }
});
