<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

/**
 * A build runs with PHP's cycle collector off, which is a global setting on someone else's
 * process. What is worth pinning is not the speed but the borrowing: whatever the caller had, it
 * gets back.
 */
beforeEach(function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['app' => ['name' => 'GcTest']]));

    $this->root = sys_get_temp_dir().'/brain-gc-'.uniqid();
    mkdir($this->root.'/app', 0o777, true);
    mkdir($this->root.'/routes', 0o777, true);
    file_put_contents($this->root.'/routes/web.php', "<?php\n");

    $this->collectorWasEnabled = gc_enabled();
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
    Container::setInstance(null);
    $this->collectorWasEnabled ? gc_enable() : gc_disable();
});

it('gives the cycle collector back to the caller it borrowed it from', function () {
    gc_enable();

    (new ProjectAnalyzer)->analyze($this->root, function () {});

    expect(gc_enabled())->toBeTrue();
});

it('gives it back even when the analysis throws', function () {
    // A scoped run raises ScopedRebuildNotApplicable from the middle of a build, so the restore
    // cannot sit at the end of the happy path. Thrown from the progress callback here, which
    // needs no fixture and reaches the same finally.
    gc_enable();

    expect(fn () => (new ProjectAnalyzer)->analyze($this->root, function (): void {
        throw new RuntimeException('from the progress callback');
    }))->toThrow(RuntimeException::class);

    expect(gc_enabled())->toBeTrue();
});

it('leaves it off for a caller that had already turned it off', function () {
    gc_disable();

    (new ProjectAnalyzer)->analyze($this->root, function () {});

    expect(gc_enabled())->toBeFalse();
});
