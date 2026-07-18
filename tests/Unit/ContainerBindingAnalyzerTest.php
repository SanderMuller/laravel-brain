<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;

it('extracts singleton bindings from fixture AppServiceProvider', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('laravel-project'));
    $rec = $registry->get('App\Contracts\ThingRepositoryInterface');

    expect($rec)
        ->concreteFqcn->toBe('App\Repositories\SqlThingRepository')
        ->providerFqcn->toBe('App\Providers\AppServiceProvider')
        ->kind->toBe('singleton');
});

it('extracts bindings from a provider that opens with declare(strict_types=1)', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('strict-types-project'));
    $rec = $registry->get('App\Contracts\ClockInterface');

    expect($rec)
        ->concreteFqcn->toBe('App\Support\SystemClock')
        ->providerFqcn->toBe('App\Providers\StrictTypesServiceProvider')
        ->kind->toBe('singleton');
});
