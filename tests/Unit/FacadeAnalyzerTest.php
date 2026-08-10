<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ContainerBindingRecord;
use LaraMint\LaravelBrain\Analysis\ContainerBindingRegistry;
use LaraMint\LaravelBrain\Analysis\FacadeAnalyzer;
use LaraMint\LaravelBrain\Analysis\FacadeRecord;

it('discovers a facade via multi-level inheritance (class → abstract parent → Facade)', function () {
    $registry = (new FacadeAnalyzer)->analyze(fixture('laravel-project'));

    // ShortUrlV3Facade extends AbstractVersionedShortUrlFacade extends Facade.
    // getFacadeAccessor() is defined on the abstract parent, not on the concrete class.
    $record = $registry->get('App\Services\V3\ShortUrlV3Facade');

    expect($record)
        ->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('App\Services\V3\ShortUrlV3Service')
        ->concreteFqcn->toBe('App\Services\V3\ShortUrlV3Service');
});

it('does not register abstract intermediate facade classes', function () {
    $registry = (new FacadeAnalyzer)->analyze(fixture('laravel-project'));

    // AbstractVersionedShortUrlFacade is abstract and must not appear in the registry.
    expect($registry)
        ->get('App\Services\V3\AbstractVersionedShortUrlFacade')
        ->toBeNull();
});

it('discovers a facade that returns a string key accessor', function () {
    $registry = (new FacadeAnalyzer)->analyze(fixture('laravel-project'));

    $record = $registry->get('App\Services\V3\ShortUrlV3KeyFacade');

    expect($record)->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('short-url-v3')
        ->concreteFqcn->toBeNull();
});

it('resolves string-key accessor via container binding registry', function () {
    $facadeRegistry = (new FacadeAnalyzer)->analyze(fixture('laravel-project'));

    $bindings = new ContainerBindingRegistry;
    $bindings->add(new ContainerBindingRecord(
        abstractFqcn: 'short-url-v3',
        concreteFqcn: 'App\Services\V3\ShortUrlV3Service',
        providerFqcn: 'App\Providers\AppServiceProvider',
        kind: 'singleton',
    ));

    $facadeRegistry->resolveWith($bindings);

    $record = $facadeRegistry->get('App\Services\V3\ShortUrlV3KeyFacade');
    expect($record?->concreteFqcn)->toBe('App\Services\V3\ShortUrlV3Service');
});

it('returns an empty registry for a project without an app/ directory', function () {
    $registry = (new FacadeAnalyzer)->analyze('/nonexistent/path');
    expect($registry->all())->toBeEmpty();
});

it('does not register non-facade classes', function () {
    $registry = (new FacadeAnalyzer)->analyze(fixture('laravel-project'));

    expect($registry->get('App\Services\V3\ShortUrlV3Service'))->toBeNull();
});

it('resolveWith does not overwrite an already-resolved concreteFqcn', function () {
    $facadeRegistry = (new FacadeAnalyzer)->analyze(fixture('laravel-project'));

    $bindings = new ContainerBindingRegistry;
    $bindings->add(new ContainerBindingRecord(
        abstractFqcn: 'App\Services\V3\ShortUrlV3Service',
        concreteFqcn: 'App\Services\V3\SomeOtherService',
        providerFqcn: 'App\Providers\AppServiceProvider',
        kind: 'singleton',
    ));

    $facadeRegistry->resolveWith($bindings);

    // The ::class accessor was already resolved — must not be overwritten.
    $record = $facadeRegistry->get('App\Services\V3\ShortUrlV3Facade');
    expect($record?->concreteFqcn)->toBe('App\Services\V3\ShortUrlV3Service');
});

it('discovers a facade whose files open with declare(strict_types=1)', function () {
    $registry = (new FacadeAnalyzer)->analyze(fixture('strict-types-project'));

    // Both the concrete facade and the abstract parent carrying getFacadeAccessor() open with
    // declare(strict_types=1), so the Namespace_ node is never at index 0 — this exercises the
    // unwrap in scanFile(), isInFacadeChain() and findAccessorInChain() in one chain.
    $record = $registry->get('App\Support\Facades\StrictClockFacade');

    expect($record)
        ->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('App\Support\SystemClock')
        ->concreteFqcn->toBe('App\Support\SystemClock');
});

it('still discovers a facade whose source spells the keyword EXTENDS', function () {
    // The `extends` keyword no longer decides which files are read, but this class is still the
    // ordinary shape a facade takes, and PHP keywords are case-insensitive.
    $root = sys_get_temp_dir().'/brain-facade-case-'.uniqid();
    mkdir($root.'/app/Support', 0o777, true);

    file_put_contents($root.'/app/Support/ShoutFacade.php', <<<'PHP'
        <?php

        namespace App\Support;

        use Illuminate\Support\Facades\Facade;

        class ShoutFacade EXTENDS Facade
        {
            protected static function getFacadeAccessor()
            {
                return 'shout';
            }
        }
        PHP);

    $registry = (new FacadeAnalyzer)->analyze($root);

    expect($registry->get('App\Support\ShoutFacade'))
        ->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('shout');

    exec('rm -rf '.escapeshellarg($root));
});

it('discovers a facade whose own file never mentions Facade', function () {
    // The narrow case the prefilter cannot see and the second pass exists for. The child extends
    // an app-level base that is *not* named `…Facade`, and imports it from a namespace segment
    // the filter discounts — so the child's source, read as text, offers nothing at all. Only the
    // base is admitted on the first pass; the child is reached because the base turned out to sit
    // in the chain and the child names it.
    $root = sys_get_temp_dir().'/brain-facade-indirect-'.uniqid();
    mkdir($root.'/app/Support/Facades', 0o777, true);

    file_put_contents($root.'/app/Support/Facades/Base.php', <<<'PHP'
        <?php

        namespace App\Support\Facades;

        use Illuminate\Support\Facades\Facade;

        abstract class Base extends Facade
        {
            protected static function getFacadeAccessor()
            {
                return 'reporting';
            }
        }
        PHP);

    file_put_contents($root.'/app/Support/Reporting.php', <<<'PHP'
        <?php

        namespace App\Support;

        use App\Support\Facades\Base;

        class Reporting extends Base
        {
        }
        PHP);

    // Guard the premise: if this file ever gains a bare `Facade` the test proves nothing.
    $source = (string) file_get_contents($root.'/app/Support/Reporting.php');
    expect(str_contains(str_replace('Facades\\', '', $source), 'Facade'))->toBeFalse();

    $registry = (new FacadeAnalyzer)->analyze($root);

    expect($registry->get('App\Support\Reporting'))
        ->toBeInstanceOf(FacadeRecord::class)
        ->accessor->toBe('reporting');

    exec('rm -rf '.escapeshellarg($root));
});
