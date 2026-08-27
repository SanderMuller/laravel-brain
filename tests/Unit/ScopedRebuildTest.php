<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\Incremental\GraphProvenance;
use LaraMint\LaravelBrain\Analysis\Incremental\ScopedRebuildNotApplicable;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

/**
 * The seam ProjectAnalyzer::scopedTo() exposes, which the classes under Incremental/ compose.
 *
 * These exist because those classes were each covered and the seam was not, and the gap hid a
 * scope that matched nothing passing the soundness check: the check compares what the scope owns
 * in the previous graph against what it owns in the fresh one, two empty sets compare equal, so a
 * scope naming nothing approved itself and the previous graph came back as though it were current.
 *
 * Graphs are compared by their edges' endpoints, type and label rather than by counting them. The
 * stale graph in the reported case has exactly as many nodes and edges as a correct one; what it
 * has is the edge the edit removed and not the one the edit added. A count is blind to that.
 */
beforeEach(function () {
    PhpFileParser::clearSharedCache();

    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['app' => ['name' => 'ScopedRebuildTest']]));

    $this->root = sys_get_temp_dir().'/brain-scoped-'.uniqid();
    mkdir($this->root.'/app/Http/Controllers', 0o777, true);
    mkdir($this->root.'/app/Services', 0o777, true);
    mkdir($this->root.'/routes', 0o777, true);

    writeScopedSource($this->root.'/routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\PublishController;
        use Illuminate\Support\Facades\Route;

        Route::get('/publish', [PublishController::class, 'index']);
        PHP);

    writeScopedSource($this->root.'/app/Services/Publisher.php', <<<'PHP'
        <?php

        namespace App\Services;

        class Publisher
        {
            public function run()
            {
                return 'ran';
            }

            public function log()
            {
                return 'logged';
            }
        }
        PHP);

    $this->controller = $this->root.'/app/Http/Controllers/PublishController.php';
    writeScopedSource($this->controller, controllerCalling('run'));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
    PhpFileParser::clearSharedCache();
    Container::setInstance(null);
});

/**
 * Write source and pin its mtime in the past, clearing PHP's per-process stat cache so the next
 * read sees what was just written. Same discipline as writeSource() in
 * PhpFileParserSharedCacheTest and for the same reason: without it a second build in the same
 * process can be answered from the first version's cached parse.
 */
function writeScopedSource(string $path, string $code): void
{
    static $age = 900;

    file_put_contents($path, $code);
    touch($path, time() - $age);
    $age = max(10, $age - 30);
    clearstatcache(true, $path);
}

/** The controller's action, calling one named method on the service. */
function controllerCalling(string $method, string $extra = ''): string
{
    return <<<PHP
        <?php

        namespace App\Http\Controllers;

        use App\Services\Publisher;

        class PublishController
        {
            public function index(Publisher \$publisher)
            {
                {$extra}

                return \$publisher->{$method}();
            }
        }
        PHP;
}

function buildFull(string $root): Graph
{
    return (new ProjectAnalyzer)->analyze($root, quietly(...))->fullGraph;
}

/** @param string[] $scope */
function buildScoped(string $root, array $scope, Graph $previous): Graph
{
    return (new ProjectAnalyzer)->scopedTo($scope, $previous)->analyze($root, quietly(...))->fullGraph;
}

/** Swallow the progress events, which are otherwise printed straight through the test run. */
function quietly(string $event, array $data): void {}

/**
 * Every edge as endpoints + type + label, sorted. Two graphs agreeing here agree on what they
 * describe, which counting nodes and edges does not establish.
 *
 * @return string[]
 */
function edgeSignature(Graph $graph): array
{
    $edges = [];
    foreach ($graph->edges() as $edge) {
        $edges[] = $edge->source.' -['.$edge->type.':'.($edge->label ?? '').']-> '.$edge->target;
    }
    sort($edges);

    return $edges;
}

it('reproduces a full build when the edit left the call graph alone', function () {
    $previous = buildFull($this->root);

    // Same call, extra statement in front of it: the previous graph's edges still hold.
    writeScopedSource($this->controller, controllerCalling('run', '$noop = 1;'));

    $scoped = buildScoped($this->root, [$this->controller], $previous);

    expect(edgeSignature($scoped))->toBe(edgeSignature(buildFull($this->root)));
});

it('refuses an edit that moved a call', function () {
    $previous = buildFull($this->root);
    writeScopedSource($this->controller, controllerCalling('log'));

    expect(fn () => buildScoped($this->root, [$this->controller], $previous))
        ->toThrow(ScopedRebuildNotApplicable::class);
});

it('refuses that same edit when the caller normalised the path', function () {
    // The reported bug. Provenance keys each file as the build recorded it, and a caller that
    // runs realpath() over its own paths names the same file in a form nothing recognises — so
    // the scope owned nothing, the check compared two empty sets and approved, and the merge
    // substituted nothing. What came back still carried the call that had just moved, with the
    // same node and edge counts as a correct graph.
    //
    // The project is reached through a symlink made here rather than relying on the platform's
    // temp directory being one. It is on macOS, where /var resolves to /private/var, and is not
    // on Linux — so without this the test would quietly assert nothing there.
    $link = sys_get_temp_dir().'/brain-scoped-link-'.uniqid();
    if (! @symlink($this->root, $link)) {
        $this->markTestSkipped('this platform will not make a symlink');
    }

    try {
        $viaLink = $link.'/app/Http/Controllers/PublishController.php';
        $previous = buildFull($link);
        expect(array_keys(GraphProvenance::of($previous)->byFile))->toContain($viaLink);

        $normalised = realpath($viaLink);
        expect($normalised)->not->toBeFalse()
            ->and($normalised)->not->toBe($viaLink);  // the premise: the forms really do differ

        writeScopedSource($this->controller, controllerCalling('log'));

        expect(fn () => buildScoped($link, [(string) $normalised], $previous))
            ->toThrow(ScopedRebuildNotApplicable::class);
    } finally {
        unlink($link);
    }
});

it('refuses a scope naming a file that is not there', function () {
    $previous = buildFull($this->root);
    writeScopedSource($this->controller, controllerCalling('log'));

    expect(fn () => buildScoped($this->root, [$this->root.'/app/Services/Gone.php'], $previous))
        ->toThrow(ScopedRebuildNotApplicable::class);
});

it('refuses a scope naming a file outside the project', function () {
    $previous = buildFull($this->root);
    $outside = sys_get_temp_dir().'/brain-scoped-outside-'.uniqid().'.php';
    writeScopedSource($outside, "<?php\n\nclass Outside {}\n");

    try {
        expect(fn () => buildScoped($this->root, [$outside], $previous))
            ->toThrow(ScopedRebuildNotApplicable::class);
    } finally {
        unlink($outside);
    }
});

it('refuses an empty scope', function () {
    // Nothing to substitute, so the merge would hand back the previous graph whatever had changed.
    $previous = buildFull($this->root);

    expect(fn () => buildScoped($this->root, [], $previous))
        ->toThrow(ScopedRebuildNotApplicable::class);
});

it('still rebuilds scoped for a file the graph attributes nothing to', function () {
    // Most of app/ is in this state — 45% to 86% across three applications — because the graph
    // only holds what an entry point reaches. Refusing every scope that owns nothing would be
    // sound but would send most single-file edits down the full path, so this pins that the fix
    // did not go that far.
    $unreached = $this->root.'/app/Services/Unreached.php';
    writeScopedSource($unreached, "<?php\n\nnamespace App\Services;\n\nclass Unreached\n{\n    public \$name;\n}\n");

    $previous = buildFull($this->root);
    expect(GraphProvenance::of($previous)->byFile)->not->toHaveKey($unreached);

    writeScopedSource($unreached, "<?php\n\nnamespace App\Services;\n\nclass Unreached\n{\n    public \$title;\n}\n");

    $scoped = buildScoped($this->root, [$unreached], $previous);

    expect(edgeSignature($scoped))->toBe(edgeSignature(buildFull($this->root)));
});
