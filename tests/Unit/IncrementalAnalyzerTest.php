<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\Incremental\IncrementalAnalyzer;
use LaraMint\LaravelBrain\Analysis\Incremental\IncrementalMerge;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

/** Two-controller project so a one-file change makes scoped != full. $aBody is ControllerA::index's body. */
function ia_writeProject(string $dir, string $aBody): void
{
    foreach (['/app/Http/Controllers', '/app/Services', '/app/Jobs', '/routes', '/vendor/composer'] as $s) {
        is_dir($dir.$s) || mkdir($dir.$s, 0o755, true);
    }
    file_put_contents($dir.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    file_put_contents($dir.'/vendor/composer/autoload_psr4.php', "<?php\n\nreturn array('App\\\\' => array(\$baseDir . '/app'));\n");
    file_put_contents($dir.'/routes/api.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/a', [\\App\\Http\\Controllers\\ControllerA::class, 'index']);\nRoute::get('/b', [\\App\\Http\\Controllers\\ControllerB::class, 'index']);\n");
    file_put_contents($dir.'/app/Services/DemoService.php', "<?php\n\nnamespace App\\Services;\n\nclass DemoService\n{\n    public function run(): void {}\n}\n");
    file_put_contents($dir.'/app/Jobs/DemoJob.php', "<?php\n\nnamespace App\\Jobs;\n\nclass DemoJob\n{\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/app/Http/Controllers/ControllerB.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\DemoService;\n\nclass ControllerB\n{\n    public function __construct(private DemoService \$svc) {}\n\n    public function index()\n    {\n        \$this->svc->run();\n    }\n}\n");
    file_put_contents($dir.'/app/Http/Controllers/ControllerA.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\DemoService;\n\nclass ControllerA\n{\n    public function __construct(private DemoService \$svc) {}\n\n    public function index()\n    {\n$aBody\n    }\n}\n");
}

/** @param string[] $onlyControllerFiles  if non-empty, restrict routes to controllers in these files */
function ia_build(string $dir, array $onlyControllerFiles = []): Graph
{
    PhpFileParser::clearSharedCache();
    $routes = (new RouteAnalyzer(['routes/*.php']))->analyze($dir);
    if ($onlyControllerFiles !== []) {
        $want = array_map(fn ($f) => basename($f, '.php'), $onlyControllerFiles);
        $routes = array_values(array_filter($routes, function ($r) use ($want) {
            $short = str_contains((string) $r->controller, '\\') ? substr((string) $r->controller, strrpos((string) $r->controller, '\\') + 1) : (string) $r->controller;

            return in_array($short, $want, true);
        }));
    }
    $controllers = (new ControllerAnalyzer)->analyze($dir, $routes);
    $traces = (new MethodTracer)->trace($controllers, ['App' => [$dir.'/app']], $dir);
    $modelFqcns = array_map(fn ($t) => $t->calleeFqcn, array_filter($traces, fn ($t) => $t->type === 'model'));
    $models = (new ModelAnalyzer)->analyze($dir, $modelFqcns);

    return (new GraphBuilder)->build('t', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, $models, $dir);
}

function ia_analyzer(): IncrementalAnalyzer
{
    return new IncrementalAnalyzer(
        fn (string $root) => ia_build($root),
        fn (string $root, array $changed) => ia_build($root, $changed),
    );
}

function ia_rmrf(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

it('does a full build first, then an incremental body-edit rebuild identical to a full rebuild', function () {
    $dir = sys_get_temp_dir().'/brain-ia-'.bin2hex(random_bytes(6));
    $aFile = $dir.'/app/Http/Controllers/ControllerA.php';
    $ia = ia_analyzer();

    ia_writeProject($dir, '        $this->svc->run();');
    $first = $ia->analyze($dir);
    expect($first['mode'])->toBe('full');

    usleep(1_100_000); // let mtime settle past the current second (unsettled-file guard)

    // Body-only edit to ControllerA: same calls, extra local work -> action node data changes, call graph intact.
    ia_writeProject($dir, "        \$x = 41 + 1;\n        \$this->svc->run();");
    $inc = $ia->analyze($dir);

    expect($inc['mode'])->toBe('incremental');
    expect(IncrementalMerge::signature($inc['graph']))->toEqual(IncrementalMerge::signature(ia_build($dir)));

    ia_rmrf($dir);
});

it('falls back to a full rebuild when the call graph changes, still identical to full', function () {
    $dir = sys_get_temp_dir().'/brain-ia-'.bin2hex(random_bytes(6));
    $ia = ia_analyzer();

    ia_writeProject($dir, '        $this->svc->run();');
    $ia->analyze($dir);

    usleep(1_100_000);

    // Structural edit: add a cross-file job dispatch -> new edge -> must NOT take the fast path.
    ia_writeProject($dir, "        \$this->svc->run();\n        \\App\\Jobs\\DemoJob::dispatch();");
    $out = $ia->analyze($dir);

    expect($out['mode'])->toBe('full');
    expect(IncrementalMerge::signature($out['graph']))->toEqual(IncrementalMerge::signature(ia_build($dir)));

    ia_rmrf($dir);
});

it('reports unchanged when nothing changed', function () {
    $dir = sys_get_temp_dir().'/brain-ia-'.bin2hex(random_bytes(6));
    $ia = ia_analyzer();

    ia_writeProject($dir, '        $this->svc->run();');
    $ia->analyze($dir);
    usleep(1_100_000);
    $again = $ia->analyze($dir);

    expect($again['mode'])->toBe('unchanged');

    ia_rmrf($dir);
});

// ── Configurable roots ────────────────────────────────────────────────────────

/** A project that keeps its code in packages rather than in app/. */
function ia_writePackagedProject(string $dir): void
{
    foreach (['/packages/shop/src', '/config'] as $s) {
        is_dir($dir.$s) || mkdir($dir.$s, 0o755, true);
    }
    file_put_contents($dir.'/packages/shop/src/Ledger.php', "<?php\n\nnamespace Shop;\n\nclass Ledger {}\n");
    file_put_contents($dir.'/config/app.php', "<?php\n\nreturn ['name' => 'Shop'];\n");
}

function ia_packagedAnalyzer(int &$fullBuilds): IncrementalAnalyzer
{
    return new IncrementalAnalyzer(
        function () use (&$fullBuilds): Graph {
            $fullBuilds++;

            return new Graph;
        },
        fn (): Graph => new Graph,
        roots: ['packages', 'config'],
        sourcePaths: ['packages/*/src'],
    );
}

it('takes the scoped path for a change inside the configured source paths', function () {
    $dir = sys_get_temp_dir().'/lb_ia_pkg_'.bin2hex(random_bytes(6));
    ia_writePackagedProject($dir);

    $fullBuilds = 0;
    $analyzer = ia_packagedAnalyzer($fullBuilds);

    expect($analyzer->analyze($dir)['mode'])->toBe('full');

    file_put_contents($dir.'/packages/shop/src/Ledger.php', "<?php\n\nnamespace Shop;\n\nclass Ledger\n{\n    public function post(): void {}\n}\n");

    expect($analyzer->analyze($dir)['mode'])->toBe('incremental')
        ->and($fullBuilds)->toBe(1);
});

it('forces a full rebuild for a change outside the configured source paths', function () {
    $dir = sys_get_temp_dir().'/lb_ia_pkg_'.bin2hex(random_bytes(6));
    ia_writePackagedProject($dir);

    $fullBuilds = 0;
    $analyzer = ia_packagedAnalyzer($fullBuilds);
    $analyzer->analyze($dir);

    // Config is watched but is not a source path: it can rewrite the graph from the top.
    file_put_contents($dir.'/config/app.php', "<?php\n\nreturn ['name' => 'Shop', 'env' => 'testing'];\n");

    expect($analyzer->analyze($dir)['mode'])->toBe('full')
        ->and($fullBuilds)->toBe(2);
});
