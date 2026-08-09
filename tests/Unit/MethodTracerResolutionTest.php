<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;

it('resolves a same-namespace sibling so the call chain is not lost', function () {
    $project = fixture('laravel-project');

    // Ledger::record() does `new Reconciler()` — a same-namespace sibling with no
    // import. Without name resolution the bare "Reconciler" is unqualified (and the
    // single-word model heuristic swallows it), so the edge goes missing.
    $edges = (new MethodTracer)->traceMethod(
        'App\\Services\\SameNs\\Ledger',
        'record',
        ['App\\' => [$project.'/app']],
        $project,
    );

    $match = array_values(array_filter(
        $edges,
        fn ($e) => $e->calleeFqcn === 'App\\Services\\SameNs\\Reconciler'
    ));

    expect($match)->not->toBeEmpty();
    // The callee is the fully-qualified sibling, never the bare "Reconciler".
    foreach ($edges as $e) {
        expect($e->calleeFqcn)->not->toBe('Reconciler');
    }
});

it('resolves a same-namespace type stored in a variable (assign then call)', function () {
    $project = fixture('laravel-project');

    // Ledger::recordViaVar() does `$recon = new Reconciler; $recon->run();`.
    // The assigned var's type must resolve so the later call is traced.
    $edges = (new MethodTracer)->traceMethod(
        'App\\Services\\SameNs\\Ledger',
        'recordViaVar',
        ['App\\' => [$project.'/app']],
        $project,
    );

    expect(array_filter($edges, fn ($e) => $e->calleeFqcn === 'App\\Services\\SameNs\\Reconciler'))
        ->not->toBeEmpty();
});

/**
 * Build a throwaway project so these cases cannot shift the counts other suites assert on
 * against the shared fixture project.
 *
 * @param  array<string, string>  $files  path under app/ => file contents
 */
function sameNsProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-samens-'.uniqid();
    foreach ($files as $path => $contents) {
        $full = $root.'/app/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $contents);
    }

    return $root;
}

it('resolves a same-namespace sibling injected as a constructor dependency', function () {
    // The property-type path resolved through the import map alone, so a sibling needing no
    // `use` stayed short. A short name is worse than a missing one: "PlainThing::label" is an
    // edge under an id nothing else in the graph uses, and the bare word also trips the
    // single-word model heuristic, so the hop came back typed 'model'.
    $root = sameNsProject([
        'Registry/PlainThing.php' => '<?php
namespace App\Registry;

class PlainThing
{
    public function label(): string { return "x"; }
}',
        'Registry/Shapes.php' => '<?php
namespace App\Registry;

class Shapes
{
    public function __construct(private ?PlainThing $thing = null) {}

    public function viaProperty(): string { return $this->thing->label(); }
}',
    ]);

    $edges = (new MethodTracer)->traceMethod('App\Registry\Shapes', 'viaProperty', ['App\\' => [$root.'/app']], $root);

    expect(array_map(fn ($e) => $e->calleeFqcn, $edges))->toContain('App\Registry\PlainThing');
    foreach ($edges as $edge) {
        expect($edge->calleeFqcn)->not->toBe('PlainThing');
    }

    exec('rm -rf '.escapeshellarg($root));
});

it('resolves a same-namespace job passed to dispatch()', function () {
    $root = sameNsProject([
        'Jobs/SyncJob.php' => '<?php
namespace App\Jobs;

class SyncJob
{
    public function handle(): void {}
}',
        'Jobs/Kicker.php' => '<?php
namespace App\Jobs;

class Kicker
{
    public function go(): void { dispatch(new SyncJob()); }
}',
    ]);

    $edges = (new MethodTracer)->traceMethod('App\Jobs\Kicker', 'go', ['App\\' => [$root.'/app']], $root);

    expect(array_map(fn ($e) => $e->calleeFqcn, $edges))->toContain('App\Jobs\SyncJob');
    foreach ($edges as $edge) {
        expect($edge->calleeFqcn)->not->toBe('SyncJob');
    }

    exec('rm -rf '.escapeshellarg($root));
});

it('resolves a same-namespace model named in authorize()', function () {
    $root = sameNsProject([
        'Models/Invoice.php' => '<?php
namespace App\Models;

class Invoice {}',
        'Models/Guarded.php' => '<?php
namespace App\Models;

class Guarded
{
    public function check(): void { $this->authorize("view", Invoice::class); }
}',
    ]);

    $edges = (new MethodTracer)->traceMethod('App\Models\Guarded', 'check', ['App\\' => [$root.'/app']], $root);

    expect(array_map(fn ($e) => $e->calleeFqcn, $edges))->toContain('App\Models\Invoice');
    foreach ($edges as $edge) {
        expect($edge->calleeFqcn)->not->toBe('Invoice');
    }

    exec('rm -rf '.escapeshellarg($root));
});

it('traces a same-namespace reference exactly as it traces the same class imported', function () {
    // The invariant worth pinning: how a class is written should not change what is traced.
    // Asserting equivalence rather than a hop count also keeps this test honest about the
    // duplicate the facade paths already emit on main, which this does not change.
    $root = sameNsProject([
        'Events/ThingHappened.php' => '<?php
namespace App\Events;

class ThingHappened {}',
        'Events/Sibling.php' => '<?php
namespace App\Events;

class Sibling
{
    public function go(): void { event(new ThingHappened()); }
}',
        'Outside/Importer.php' => '<?php
namespace App\Outside;

use App\Events\ThingHappened;

class Importer
{
    public function go(): void { event(new ThingHappened()); }
}',
    ]);

    $tracer = new MethodTracer;
    $psr4 = ['App\\' => [$root.'/app']];

    $describe = fn (array $edges) => array_map(
        fn ($e) => $e->calleeFqcn.'::'.$e->calleeMethod.' ('.$e->type.')',
        $edges,
    );

    $sibling = $describe($tracer->traceMethod('App\Events\Sibling', 'go', $psr4, $root));
    $imported = $describe($tracer->traceMethod('App\Outside\Importer', 'go', $psr4, $root));

    expect($sibling)->toBe($imported)
        ->and($sibling)->toContain('App\Events\ThingHappened::__construct (event)');

    exec('rm -rf '.escapeshellarg($root));
});

it('treats a same-namespace authorize() target the same as an imported one', function () {
    // authorize() only records a target that looksLikeModel() accepts, and that helper takes any
    // bare capitalised word. A model outside the conventional namespaces was therefore accepted
    // while it stayed short and is rejected once resolved — so this call records nothing now.
    //
    // That is the invariant applied downwards rather than a new gap: written as an import, the
    // qualified name fails the same check on main today. What is pinned here is that both forms
    // agree; whether looksLikeModel() should recognise the class at all is a separate question.
    $root = sameNsProject([
        'Domain/Billing/Invoice.php' => '<?php
namespace App\Domain\Billing;

class Invoice {}',
        'Domain/Billing/Guarded.php' => '<?php
namespace App\Domain\Billing;

class Guarded
{
    public function check(): void { $this->authorize("view", Invoice::class); }
}',
        'Outside/Importer.php' => '<?php
namespace App\Outside;

use App\Domain\Billing\Invoice;

class Importer
{
    public function check(): void { $this->authorize("view", Invoice::class); }
}',
    ]);

    $tracer = new MethodTracer;
    $psr4 = ['App\\' => [$root.'/app']];
    $describe = fn (array $edges) => array_map(fn ($e) => $e->calleeFqcn.'::'.$e->calleeMethod, $edges);

    expect($describe($tracer->traceMethod('App\Domain\Billing\Guarded', 'check', $psr4, $root)))
        ->toBe($describe($tracer->traceMethod('App\Outside\Importer', 'check', $psr4, $root)));

    exec('rm -rf '.escapeshellarg($root));
});
