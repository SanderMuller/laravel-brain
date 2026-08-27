<?php

use LaraMint\LaravelBrain\Analysis\BladeViewAnalyzer;
use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

it('maps @include and <x-...> references that resolve to a real Blade file', function () {
    $map = (new BladeViewAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['dashboard'] ?? [])
        ->toContain('partials.header')      // @include
        ->toContain('components.card')      // <x-card>
        ->toContain('components.alert.error'); // <x-alert.error>
});

it('resolves a <x-...> tag to its folder-index view when that is the real file', function () {
    $map = (new BladeViewAnalyzer)->analyze(fixture('laravel-project'));

    // components/menu/index.blade.php exists; components/menu.blade.php does not.
    expect($map['dashboard'] ?? [])->toContain('components.menu.index');
});

it('does not link a reference with no matching Blade file, nor a namespaced tag', function () {
    $map = (new BladeViewAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['dashboard'] ?? [])
        ->not->toContain('missing.partial')   // @include of a missing view
        ->not->toContain('layouts.app')       // @extends of a missing layout
        ->not->toContain('components.livewire::modal'); // namespaced <x-livewire::modal>
});

it('parses referenced view names purely from source, offering both component forms', function () {
    $names = (new BladeViewAnalyzer)->referencedViewNames(
        "@include('a.b')\n<x-foo.bar/>\n<x-slot/>\n<x-pkg::widget/>\n@include(\$dynamic)"
    );

    expect($names)
        ->toContain('a.b')
        ->toContain('components.foo.bar')
        ->toContain('components.foo.bar.index') // folder-index candidate offered
        ->not->toContain('components.slot')     // non-view tag skipped
        ->not->toContain('components.pkg::widget'); // namespaced skipped
});

it('wires transitive view → view "renders" edges from a rendered view', function () {
    $project = fixture('laravel-project');
    $map = (new BladeViewAnalyzer)->analyze($project);

    // An action renders `dashboard`; that seeds the composition walk.
    $viewEdge = new CallChainEdge('App\\Http\\Controllers\\PageController', 'index', 'blade::dashboard', '', 'view');

    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [$viewEdge], [], $project);
    $builder->addViewComposition($map);

    $viewOf = [];
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'view') {
            $viewOf[$node->id] = $node->data['view'];
        }
    }
    $renders = [];
    foreach ($graph->edges() as $edge) {
        if ($edge->type === 'view-to-view') {
            expect($edge->label)->toBe('renders');
            $renders[] = ($viewOf[$edge->source] ?? '?').' => '.($viewOf[$edge->target] ?? '?');
        }
    }

    expect($renders)
        ->toContain('dashboard => components.card')       // direct
        ->toContain('components.card => components.button') // transitive descent
        ->toContain('partials.header => components.logo');  // transitive via @include
});

it('links @each, @includeWhen and @includeFirst targets', function () {
    $map = (new BladeViewAnalyzer)->analyze(fixture('laravel-project'));

    expect($map['report'] ?? [])
        ->toContain('partials.header')    // @each first argument
        ->toContain('components.button')  // @includeWhen second argument
        ->toContain('components.logo')    // @includeFirst list member
        ->not->toContain('missing.one');  // @includeFirst member with no file
});

it('does not duplicate a child referenced by both @include and <x-...>', function () {
    $map = (new BladeViewAnalyzer)->analyze(fixture('laravel-project'));

    // report.blade.php has both @include('components.card') and <x-card/>.
    $cards = array_filter($map['report'] ?? [], fn ($v) => $v === 'components.card');
    expect($cards)->toHaveCount(1);
});

it('terminates and links both edges on a view cycle', function () {
    $project = fixture('laravel-project');
    $map = (new BladeViewAnalyzer)->analyze($project);

    $seed = new CallChainEdge('App\\Http\\Controllers\\PageController', 'index', 'blade::cycle.loop-a', '', 'view');
    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [$seed], [], $project);
    $builder->addViewComposition($map); // must not loop forever

    $viewOf = [];
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'view') {
            $viewOf[$node->id] = $node->data['view'];
        }
    }
    $renders = [];
    foreach ($graph->edges() as $edge) {
        if ($edge->type === 'view-to-view') {
            $renders[] = ($viewOf[$edge->source] ?? '?').' => '.($viewOf[$edge->target] ?? '?');
        }
    }
    expect($renders)
        ->toContain('cycle.loop-a => cycle.loop-b')
        ->toContain('cycle.loop-b => cycle.loop-a');
});

it('reaches a shared child from more than one seed view without duplicating it', function () {
    $project = fixture('laravel-project');
    $map = (new BladeViewAnalyzer)->analyze($project);

    // Both `dashboard` (via components.card) and `sidebar` (directly) render components.button.
    $seeds = [
        new CallChainEdge('App\\Http\\Controllers\\PageController', 'index', 'blade::dashboard', '', 'view'),
        new CallChainEdge('App\\Http\\Controllers\\PageController', 'aside', 'blade::sidebar', '', 'view'),
    ];
    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], $seeds, [], $project);
    $builder->addViewComposition($map);

    $buttonNodes = array_filter($graph->nodes(), fn ($n) => $n->type === 'view' && $n->data['view'] === 'components.button');
    expect($buttonNodes)->toHaveCount(1);

    $viewOf = [];
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'view') {
            $viewOf[$node->id] = $node->data['view'];
        }
    }
    $intoButton = array_filter(
        $graph->edges(),
        fn ($e) => $e->type === 'view-to-view' && ($viewOf[$e->target] ?? '') === 'components.button'
    );
    $sources = array_map(fn ($e) => $viewOf[$e->source], $intoButton);
    expect($sources)->toContain('components.card')->toContain('sidebar');
});

it('does not create nodes for views that no rendered view reaches', function () {
    $project = fixture('laravel-project');
    $map = (new BladeViewAnalyzer)->analyze($project);

    $viewEdge = new CallChainEdge('App\\Http\\Controllers\\PageController', 'index', 'blade::dashboard', '', 'view');
    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [$viewEdge], [], $project);
    $builder->addViewComposition($map);

    $views = array_map(fn ($n) => $n->data['view'], array_filter($graph->nodes(), fn ($n) => $n->type === 'view'));

    // `orphan` renders partials.header but is itself rendered by nothing — it must not enter the graph.
    expect($views)->not->toContain('orphan');
    // `dashboard` and a transitively-reached component are present.
    expect($views)->toContain('dashboard')->toContain('components.button');
});

// ── Configurable view roots ───────────────────────────────────────────────────

it('finds nothing in a packaged application while the default resources/views path is used', function () {
    expect((new BladeViewAnalyzer)->analyze(fixture('packaged-app')))->toBe([]);
});

it('scans every view root a glob pattern matches', function () {
    $map = (new BladeViewAnalyzer(['packages/*/resources/views']))->analyze(fixture('packaged-app'));

    expect($map)->toHaveKey('orders.index')
        ->and($map['orders.index'])->toContain('orders.row');
});

it('links an include that resolves under a different view root', function () {
    // One package rendering another's partial: the template exists, just not below the
    // root the including file came from.
    $map = (new BladeViewAnalyzer(['packages/*/resources/views']))->analyze(fixture('packaged-app'));

    expect($map['orders.index'])->toContain('invoices.line');
});

it('still refuses an include with no matching Blade file under any root', function () {
    $map = (new BladeViewAnalyzer(['packages/*/resources/views']))->analyze(fixture('packaged-app'));

    expect($map['orders.index'])->not->toContain('orders.missing');
});
