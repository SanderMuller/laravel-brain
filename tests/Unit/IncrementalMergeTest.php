<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\Incremental\IncrementalMerge;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

/** Write a minimal but real Laravel-shaped project (with the vendor psr4 map GraphBuilder needs). */
function writeMergeProject(string $dir, string $controllerIndexBody): void
{
    foreach (['/app/Http/Controllers', '/app/Services', '/app/Jobs', '/routes', '/vendor/composer'] as $sub) {
        is_dir($dir.$sub) || mkdir($dir.$sub, 0o755, true);
    }

    file_put_contents($dir.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    file_put_contents($dir.'/vendor/composer/autoload_psr4.php', "<?php\n\nreturn array('App\\\\' => array(\$baseDir . '/app'));\n");
    file_put_contents($dir.'/routes/api.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/demo', [\\App\\Http\\Controllers\\DemoController::class, 'index']);\n");
    file_put_contents($dir.'/app/Services/DemoService.php', "<?php\n\nnamespace App\\Services;\n\nclass DemoService\n{\n    public function run(): void {}\n}\n");
    file_put_contents($dir.'/app/Jobs/DemoJob.php', "<?php\n\nnamespace App\\Jobs;\n\nclass DemoJob\n{\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/app/Http/Controllers/DemoController.php', <<<PHP
<?php

namespace App\Http\Controllers;

use App\Services\DemoService;

class DemoController
{
    public function __construct(private DemoService \$svc) {}

    public function index()
    {
$controllerIndexBody
    }
}
PHP);
}

function buildMergeGraph(string $dir): Graph
{
    PhpFileParser::clearSharedCache();
    $routes = (new RouteAnalyzer(['routes/*.php']))->analyze($dir);
    $controllers = (new ControllerAnalyzer)->analyze($dir, $routes);
    $traces = (new MethodTracer)->trace($controllers, $controllers === [] ? [] : ['App' => [$dir.'/app']], $dir);
    $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
    $models = (new ModelAnalyzer)->analyze($dir, $modelFqcns);

    return (new GraphBuilder)->build('test', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, $models, $dir);
}

function rmrfDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

it('reconstructs a full rebuild from cached graph + one changed file — incl. a cross-file job dispatch (the escaping case)', function () {
    $dir = sys_get_temp_dir().'/brain-merge-'.bin2hex(random_bytes(6));
    $controllerFile = $dir.'/app/Http/Controllers/DemoController.php';

    // OLD: index() only calls the injected service.
    writeMergeProject($dir, '        $this->svc->run();');
    $old = buildMergeGraph($dir);

    // EDIT: add a cross-file job dispatch — creates a controller->job edge whose TARGET node
    // (DemoJob::handle) is owned by app/Jobs/DemoJob.php, NOT the edited controller file.
    writeMergeProject($dir, "        \$this->svc->run();\n        \\App\\Jobs\\DemoJob::dispatch();");
    $new = buildMergeGraph($dir);

    // Sanity: the edit actually grew the graph (new job node + edge exist), else the test is vacuous.
    expect($new->nodeCount())->toBeGreaterThan($old->nodeCount());

    $merged = IncrementalMerge::reconstruct($old, $new, [$controllerFile]);

    // The reconstructed graph is byte-identical (element-wise) to a full rebuild.
    expect(IncrementalMerge::signature($merged))->toEqual(IncrementalMerge::signature($new));

    // And it is genuinely INCREMENTAL: the dirty set is a small fraction of the graph
    // (unchanged elements were reused from the old build, not recomputed).
    $dirty = IncrementalMerge::dirtyIds($old, $new, [$controllerFile]);
    expect(count($dirty['nodes']))->toBeLessThan($new->nodeCount());

    rmrfDir($dir);
});

it('reconstructs correctly for a method-body edit that changes only the caller node data', function () {
    $dir = sys_get_temp_dir().'/brain-merge-'.bin2hex(random_bytes(6));
    $controllerFile = $dir.'/app/Http/Controllers/DemoController.php';

    writeMergeProject($dir, '        $this->svc->run();');
    $old = buildMergeGraph($dir);

    // Body-only edit: same calls, extra local work → the action node's flow/metrics data changes.
    writeMergeProject($dir, "        \$x = 1 + 1;\n        \$this->svc->run();");
    $new = buildMergeGraph($dir);

    $merged = IncrementalMerge::reconstruct($old, $new, [$controllerFile]);

    expect(IncrementalMerge::signature($merged))->toEqual(IncrementalMerge::signature($new));

    rmrfDir($dir);
});

it('keeps the node order a full rebuild would produce, not just the same nodes', function () {
    // signature() normalises order, so it cannot see this: applyPartial used to add the
    // untouched nodes first and the rebuilt ones afterwards, which moved every rebuilt node to
    // the end. The graph held the right nodes in the wrong sequence, and anything diffing or
    // caching the output saw churn on every incremental tick.
    $dir = sys_get_temp_dir().'/brain-merge-'.bin2hex(random_bytes(6));
    $controllerFile = $dir.'/app/Http/Controllers/DemoController.php';

    writeMergeProject($dir, '        $this->svc->run();');
    $old = buildMergeGraph($dir);

    writeMergeProject($dir, "        \$x = 1 + 1;\n        \$this->svc->run();");
    $rebuilt = buildMergeGraph($dir);

    $merged = IncrementalMerge::applyPartial($old, $rebuilt, [$controllerFile]);

    $ids = fn ($graph) => array_map(fn ($n) => $n->id, $graph->nodes());

    expect($ids($merged))->toBe($ids($rebuilt));

    rmrfDir($dir);
});
