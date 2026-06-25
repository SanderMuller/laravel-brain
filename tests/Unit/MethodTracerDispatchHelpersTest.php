<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;

it('traces $this->dispatch(), $this->dispatchSync() and dispatch_sync() job dispatches', function () {
    $root = fixture('laravel-project');
    $psr4 = ['App\\' => [$root.'/app']];

    $edges = (new MethodTracer)->traceMethod('App\Services\QueueDispatcher', 'run', $psr4, $root);

    $jobTargets = array_map(
        fn ($edge) => $edge->calleeFqcn,
        array_filter($edges, fn ($edge) => $edge->type === 'job'),
    );

    // QueueDispatcher::run() dispatches these via the DispatchesJobs trait and the dispatch_sync() helper.
    expect($jobTargets)
        ->toContain('App\Jobs\ProcessReport')    // $this->dispatch(new ProcessReport)
        ->toContain('App\Jobs\SyncInventory')     // $this->dispatchSync(new SyncInventory)
        ->toContain('App\Jobs\RebuildCache');     // dispatch_sync(new RebuildCache)
});
