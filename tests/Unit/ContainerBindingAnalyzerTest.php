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

// ── Configurable provider paths ───────────────────────────────────────────────

it('finds nothing in a packaged application while the default app/Providers path is used', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Support\LedgerInterface'))->toBeNull();
});

it('reads bindings from providers that live in packages', function () {
    $registry = (new ContainerBindingAnalyzer(null, ['packages/*/src/Providers']))
        ->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Support\LedgerInterface'))
        ->concreteFqcn->toBe('Acme\Billing\Support\SqlLedger')
        ->providerFqcn->toBe('Acme\Billing\Providers\BillingServiceProvider')
        ->kind->toBe('singleton');
});

it('reaches a provider nested more than one directory deep', function () {
    // The scan used to be `*.php` plus `**/*.php`, and PHP's glob does not cross
    // directory separators — so exactly one level down was as far as it went.
    $registry = (new ContainerBindingAnalyzer(null, ['packages/*/src/Providers']))
        ->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Support\InvoiceNumberer'))
        ->concreteFqcn->toBe('Acme\Billing\Support\SequentialInvoiceNumberer')
        ->providerFqcn->toBe('Acme\Billing\Providers\Nested\InvoiceServiceProvider')
        ->kind->toBe('bind');
});

it('still reads app/Providers when it is among the configured paths', function () {
    $registry = (new ContainerBindingAnalyzer(null, ['app/Providers', 'packages/*/src/Providers']))
        ->analyze(fixture('laravel-project'));

    expect($registry->get('App\Contracts\ThingRepositoryInterface'))
        ->concreteFqcn->toBe('App\Repositories\SqlThingRepository');
});
