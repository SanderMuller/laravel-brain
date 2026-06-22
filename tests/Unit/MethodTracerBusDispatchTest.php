<?php

use LaraMint\LaravelBrain\Analysis\MethodTracer;

it('traces jobs dispatched through the Bus facade (dispatch, chain, batch)', function () {
    $root = fixture('laravel-project');
    $psr4 = ['App\\' => [$root.'/app']];

    $edges = (new MethodTracer)->traceMethod('App\Services\BusDispatcher', 'dispatchAll', $psr4, $root);

    $jobTargets = array_map(
        fn ($edge) => $edge->calleeFqcn,
        array_filter($edges, fn ($edge) => $edge->type === 'job'),
    );

    // BusDispatcher::dispatchAll() dispatches these via Bus::dispatch / Bus::chain / Bus::batch.
    expect($jobTargets)
        ->toContain('App\Jobs\ShipOrder')        // Bus::dispatch(new ShipOrder())
        ->toContain('App\Jobs\ChargeOrder')       // Bus::chain([new ChargeOrder(), ...])
        ->toContain('App\Jobs\NotifyWarehouse')   // Bus::chain([..., new NotifyWarehouse()])
        ->toContain('App\Jobs\ReindexOrder');     // Bus::batch([new ReindexOrder()])
});
