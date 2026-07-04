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
