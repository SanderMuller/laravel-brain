<?php

use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\ObserverAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

it('discovers an observer registered via a single #[ObservedBy] attribute', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map)->toHaveKey('App\\Models\\Comment');
    expect($map['App\\Models\\Comment'])->toBe(['App\\Observers\\CommentObserver']);
});

it('discovers every observer in an #[ObservedBy([...])] array attribute', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map)->toHaveKey('App\\Models\\Product');
    expect($map['App\\Models\\Product'])
        ->toContain('App\\Observers\\ProductObserver')
        ->toContain('App\\Observers\\ProductAuditObserver');
});

it('discovers an observer registered via Model::observe() in a provider', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['App\\Models\\Order'] ?? [])->toBe(['App\\Observers\\OrderObserver']);
});

it('discovers an observer from the array form of Model::observe()', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['App\\Models\\User'] ?? [])->toBe(['App\\Observers\\UserObserver']);
});

it('discovers a self/static::observe() call inside the model itself', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['App\\Models\\Invoice'] ?? [])->toBe(['App\\Observers\\InvoiceObserver']);
});

it('de-duplicates an observer registered by both #[ObservedBy] and observe()', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    $occurrences = array_filter(
        $map['App\\Models\\Product'],
        fn ($o) => $o === 'App\\Observers\\ProductObserver'
    );

    expect($occurrences)->toHaveCount(1);
});

it('does not invent observers for a model that registers none', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    // Order is observed only by OrderObserver — nothing leaks in from other models.
    expect($map['App\\Models\\Order'])->toBe(['App\\Observers\\OrderObserver']);
    expect($map)->not->toHaveKey('App\\Models\\NonExistentModel');
});

it('resolves an aliased #[ObservedBy] import to the real observer FQCN', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['App\\Models\\Tag'] ?? [])->toContain('App\\Observers\\TagObserver');
});

it('resolves a string-literal observer reference in observe()', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['App\\Models\\Tag'] ?? [])->toContain('App\\Observers\\TagAuditObserver');
});

it('wires a model → observer edge into the graph', function () {
    $map = (new ObserverAnalyzer)->analyze(fixture('laravel-project'));

    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [], []);
    $builder->addObservers($map);

    $observerNode = array_values(array_filter(
        $graph->nodes(),
        fn ($n) => $n->type === 'observer' && $n->data['fqcn'] === 'App\\Observers\\OrderObserver'
    ));
    expect($observerNode)->toHaveCount(1);
    expect($observerNode[0]->id)->toBe('observer::App\\Observers\\OrderObserver');

    $edge = array_values(array_filter(
        $graph->edges(),
        fn ($e) => $e->source === 'model::App\\Models\\Order' && $e->target === $observerNode[0]->id
    ));
    expect($edge)->toHaveCount(1);
    expect($edge[0])
        ->label->toBe('observed by')
        ->type->toBe('model-to-observer');
});

it('hangs the observer edge off the same model node as the fired-event edge', function () {
    $project = fixture('laravel-project');

    // Order both fires OrderPlaced (via $dispatchesEvents) and is observed by OrderObserver.
    $models = (new ModelAnalyzer)->analyze($project, ['App\\Models\\Order']);
    $map = (new ObserverAnalyzer)->analyze($project);

    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [], $models, $project);
    $builder->addObservers($map);

    $modelNodes = array_filter(
        $graph->nodes(),
        fn ($n) => $n->id === 'model::App\\Models\\Order'
    );
    expect($modelNodes)->toHaveCount(1);

    $outgoing = array_filter(
        $graph->edges(),
        fn ($e) => $e->source === 'model::App\\Models\\Order'
    );
    $types = array_map(fn ($e) => $e->type, array_values($outgoing));

    expect($types)
        ->toContain('model-to-event')
        ->toContain('model-to-observer');
});
