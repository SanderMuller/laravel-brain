<?php

declare(strict_types=1);

/**
 * Benchmark suite for laravel-brain.
 *
 * Measures a scan of a generated Laravel application and reports two kinds of
 * number, which must be read differently:
 *
 *   Graph output   nodes, edges, tabs, routes, security issues, parse() calls,
 *                  and a per-node-type breakdown. Deterministic: the same code
 *                  over the same generated tree gives the same figures however
 *                  often it is scanned and wherever the tree sits, and every
 *                  repetition is checked against the last to make sure. A change
 *                  here is a change in what Brain detects.
 *
 *   Timing         median wall clock over N repetitions. Useful, but noisy on
 *                  shared hardware — compare it against the spread of the
 *                  baseline's own repetitions before calling it a regression.
 *
 * Scenarios:
 *   scan-small     full ProjectAnalyzer scan of the scale-1 corpus (~400 files)
 *   scan-large     full ProjectAnalyzer scan of the scale-3 corpus (~1,200 files)
 *   trace-methods  MethodTracer over every entry-point method in the scale-1
 *                  corpus — the call-tracing half of a build, isolated
 *
 * Usage:
 *   php benchmark/benchmark.php                      run and print a table
 *   php benchmark/benchmark.php --json               machine-readable, to stdout
 *   php benchmark/benchmark.php --out=result.json    machine-readable, to a file
 *   php benchmark/benchmark.php --markdown           markdown table
 *
 * Options:
 *   --reps=N          timed repetitions per scenario (default 5)
 *   --warmups=N       untimed repetitions first (default 1)
 *   --scenario=NAME   run one scenario only
 *   --corpus-dir=DIR  where corpora are generated and cached
 *                     (default: <tmp>/laravel-brain-benchmark)
 *
 * The corpora are generated on first use and reused afterwards. Both arms of a
 * comparison must scan the same generated tree — pass the same --corpus-dir.
 */

use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Analysis\ProjectFileIndex;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

require __DIR__.'/bootstrap.php';

brain_bench_bootstrap();

// ─── options ────────────────────────────────────────────────────────────────

function opt(string $name): ?string
{
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
}

function flag(string $name): bool
{
    return in_array("--{$name}", $GLOBALS['argv'], true);
}

$reps = max(1, (int) (opt('reps') ?? 5));
$warmups = max(0, (int) (opt('warmups') ?? 1));
$only = opt('scenario');
$out = opt('out');
$asJson = flag('json') || $out !== null;
$asMarkdown = flag('markdown') || flag('ci');
$corpusDir = opt('corpus-dir') ?? sys_get_temp_dir().'/laravel-brain-benchmark';

// ─── corpora ────────────────────────────────────────────────────────────────

/**
 * Generate the corpus for a scale if it is not already there, and return its path.
 *
 * The marker file records the scale and the generator's own hash: change the
 * generator and every cached corpus is rebuilt, so a stale tree can never be
 * silently reused.
 */
function corpus(string $baseDir, float $scale): string
{
    $generator = __DIR__.'/generate-corpus.php';
    $hash = is_file($generator) ? hash_file('sha256', $generator) : false;

    if ($hash === false) {
        fwrite(STDERR, "generator not found at {$generator}\n");
        exit(1);
    }

    $stamp = sprintf('%s-%s', $scale, substr($hash, 0, 12));
    $dir = sprintf('%s/corpus-%s', $baseDir, $stamp);

    if (is_file($dir.'/.generated')) {
        return $dir;
    }

    if (! is_dir($baseDir) && ! mkdir($baseDir, 0755, true) && ! is_dir($baseDir)) {
        fwrite(STDERR, "cannot create corpus dir {$baseDir}\n");
        exit(1);
    }

    if (! function_exists('exec')) {
        fwrite(STDERR, sprintf(
            "exec() is disabled, so the corpus cannot be generated here. Run it by hand:\n  php %s %s %s\n",
            $generator,
            $dir,
            $scale,
        ));
        exit(1);
    }

    $cmd = sprintf(
        '%s %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($generator),
        escapeshellarg($dir),
        escapeshellarg((string) $scale),
    );

    // The generator reports what it produced on stderr; keep it out of stdout,
    // which carries the JSON, but show it if generation fails.
    $output = [];
    exec($cmd.' 2>&1', $output, $status);

    if ($status !== 0 || ! is_dir($dir.'/app')) {
        fwrite(STDERR, "corpus generation failed for scale {$scale}:\n".implode("\n", $output)."\n");
        exit(1);
    }

    file_put_contents($dir.'/.generated', $stamp."\n");

    return $dir;
}

function php_file_count(string $dir): int
{
    if (! is_dir($dir)) {
        return 0;
    }

    $n = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $n++;
        }
    }

    return $n;
}

// ─── measurement helpers ────────────────────────────────────────────────────

/**
 * Clear every build-scoped static cache, so each repetition is a cold build.
 *
 * Guarded with method_exists/class_exists: this file is also run against other
 * checkouts of src/ (the base branch in CI), where a cache may not exist yet.
 */
function reset_build_state(): void
{
    if (method_exists(PhpFileParser::class, 'clearSharedCache')) {
        PhpFileParser::clearSharedCache();
    }

    if (property_exists(PhpFileParser::class, 'parseCount')) {
        PhpFileParser::$parseCount = 0;
    }

    if (class_exists(ProjectFileIndex::class) && method_exists(ProjectFileIndex::class, 'clear')) {
        ProjectFileIndex::clear();
    }

    gc_collect_cycles();
}

/**
 * The counts that must reproduce, with the ones that cannot stripped out.
 *
 * Peak memory is the process's, not the scenario's, so it grows as the process
 * runs and would make every repetition look like a different build.
 *
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function deterministic_counts(array $counts): array
{
    unset($counts['process_peak_mem_mb']);

    return $counts;
}

/** parse() call count, or null when this checkout does not instrument it. */
function parse_count(): ?int
{
    return property_exists(PhpFileParser::class, 'parseCount') ? PhpFileParser::$parseCount : null;
}

/** @param  list<float>  $xs */
function median(array $xs): float
{
    sort($xs);
    $n = count($xs);

    if ($n === 0) {
        return 0.0;
    }

    return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[intdiv($n, 2) - 1] + $xs[intdiv($n, 2)]) / 2;
}

/**
 * Run one scenario: warmups, then $reps timed repetitions.
 *
 * The scenario times itself and reports 'ms', so only the work under
 * measurement is timed — not the harness counting up the result afterwards.
 *
 * Counts are taken from the last repetition. They are expected to be identical
 * in every repetition; when they are not, 'counts_stable' says so rather than
 * letting one repetition's figures stand in silently for a build that is not
 * reproducible.
 *
 * @param  callable(): array{ms: float, counts: array<string, int>, phases_ms: array<string, float>}  $run
 * @return array{times_ms: list<float>, median_ms: float, counts: array<string, int>, counts_stable: bool, phases_ms: array<string, float>}
 */
function measure(callable $run, int $reps, int $warmups): array
{
    $times = [];
    $counts = null;
    $stable = true;
    $phaseSamples = [];

    for ($i = 0; $i < $warmups + $reps; $i++) {
        reset_build_state();
        $result = $run();

        if ($i < $warmups) {
            continue;
        }

        $times[] = $result['ms'];

        if ($counts !== null && deterministic_counts($result['counts']) !== deterministic_counts($counts)) {
            $stable = false;
        }

        $counts = $result['counts'];

        foreach ($result['phases_ms'] as $phase => $phaseMs) {
            $phaseSamples[$phase][] = $phaseMs;
        }
    }

    $phases = [];

    foreach ($phaseSamples as $phase => $samples) {
        $phases[$phase] = round(median($samples), 2);
    }

    arsort($phases);

    return [
        'times_ms' => array_map(static fn (float $ms): float => round($ms, 2), $times),
        'median_ms' => round(median($times), 2),
        'counts' => $counts ?? [],
        'counts_stable' => $stable,
        'phases_ms' => $phases,
    ];
}

// ─── scenario bodies ────────────────────────────────────────────────────────

/**
 * A full scan, and everything a consumer can count in its result.
 *
 * Times the scan itself; the counting afterwards is harness work and is left
 * out of the measurement.
 *
 * @return array{ms: float, counts: array<string, int>, phases_ms: array<string, float>}
 */
function scan(string $corpus): array
{
    // The default progress callback echoes to stdout, which would corrupt the
    // JSON and markdown output; this one also times each phase.
    $open = [];
    $phases = [];

    $t0 = hrtime(true);

    $result = (new ProjectAnalyzer)->analyze($corpus, static function (string $event, array $data) use (&$open, &$phases): void {
        $step = $data['step'] ?? null;

        if (! is_string($step)) {
            return;
        }

        if ($event === 'step:start') {
            $open[$step] = hrtime(true);
        } elseif ($event === 'step:done' && isset($open[$step])) {
            $phases[$step] = ($phases[$step] ?? 0) + (hrtime(true) - $open[$step]) / 1e6;
            unset($open[$step]);
        }
    });

    $ms = (hrtime(true) - $t0) / 1e6;

    $issues = 0;
    $routesWithIssues = 0;
    $byType = [];

    foreach ($result->fullGraph->nodes() as $node) {
        $byType[$node->type] = ($byType[$node->type] ?? 0) + 1;
        $nodeIssues = $node->data['security']['issues'] ?? null;

        if (is_array($nodeIssues) && $nodeIssues !== []) {
            $issues += count($nodeIssues);
            $routesWithIssues++;
        }
    }

    ksort($byType);

    $counts = [
        'nodes' => $result->fullGraph->nodeCount(),
        'edges' => $result->fullGraph->edgeCount(),
        'tabs' => count($result->subgraphs),
        'routes' => $result->totalRoutes,
        'commands' => $result->totalCommands,
        'channels' => $result->totalChannels,
        'filament_resources' => $result->totalFilamentResources,
        'security_issues' => $issues,
        'nodes_with_security_issues' => $routesWithIssues,
        'unresolved_dispatchers' => count($result->unresolvedDispatchers),
        // Cumulative for the whole process, so it depends on which scenarios ran
        // before this one. Reported, never diffed.
        'process_peak_mem_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
    ];

    foreach ($byType as $type => $n) {
        $counts['node_type:'.$type] = $n;
    }

    $parses = parse_count();

    if ($parses !== null) {
        $counts['parse_calls'] = $parses;
    }

    return ['ms' => $ms, 'counts' => $counts, 'phases_ms' => $phases];
}

/**
 * Every non-abstract, non-static instance method on the entry-point classes —
 * the workload a consumer that traces entry points drives into MethodTracer.
 *
 * Enumerated once, outside the timed loop.
 *
 * @return list<array{0: string, 1: string}>
 */
function entry_point_methods(string $corpus): array
{
    $roots = [
        'App\\Jobs' => 'app/Jobs',
        'App\\Listeners' => 'app/Listeners',
        'App\\Console\\Commands' => 'app/Console/Commands',
        'App\\Http\\Middleware' => 'app/Http/Middleware',
        'App\\Livewire' => 'app/Livewire',
        'App\\Observers' => 'app/Observers',
    ];

    $parser = new PhpFileParser;
    $pairs = [];

    foreach ($roots as $namespace => $relative) {
        $dir = $corpus.'/'.$relative;

        if (! is_dir($dir)) {
            continue;
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        // Directory iteration order is filesystem-dependent; the tracer's
        // shared class cache makes the order matter for timing.
        sort($files);

        foreach ($files as $path) {
            $parsed = $parser->parse($path);

            if (! $parsed['ast']) {
                continue;
            }

            $visitor = new class extends NodeVisitorAbstract
            {
                /** @var list<string> */
                public array $methods = [];

                public bool $abstract = false;

                public function enterNode(Node $node): ?int
                {
                    if ($node instanceof Node\Stmt\Class_ && $node->isAbstract()) {
                        $this->abstract = true;
                    }

                    if ($node instanceof Node\Stmt\ClassMethod
                        && $node->name->toString() !== '__construct'
                        && ! $node->isAbstract()
                        && ! $node->isStatic()) {
                        $this->methods[] = $node->name->toString();
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($parsed['ast']);

            if ($visitor->abstract) {
                continue;
            }

            foreach ($visitor->methods as $method) {
                $pairs[] = [$namespace.'\\'.basename($path, '.php'), $method];
            }
        }
    }

    return $pairs;
}

/**
 * The PSR-4 map the tracer resolves class names through.
 *
 * Read the corpus's Composer-generated map when present, as a real application
 * would: a hand-written single-prefix map sends resolveFile() down its
 * recursive-walk fallback, which is a benchmark artifact rather than a workload.
 *
 * @return array<string, list<string>>
 */
function psr4_map(string $corpus): array
{
    $file = $corpus.'/vendor/composer/autoload_psr4.php';

    if (! is_file($file)) {
        return ['App' => [$corpus.'/app']];
    }

    // The generated file references these two variables.
    $baseDir = $corpus;
    $vendorDir = $corpus.'/vendor';

    $raw = require $file;

    if (! is_array($raw)) {
        return ['App' => [$corpus.'/app']];
    }

    $map = [];

    foreach ($raw as $namespace => $paths) {
        $map[rtrim((string) $namespace, '\\')] = array_values((array) $paths);
    }

    return $map;
}

/**
 * @param  list<array{0: string, 1: string}>  $pairs
 * @param  array<string, list<string>>  $psr4
 * @return array{ms: float, counts: array<string, int>, phases_ms: array<string, float>}
 */
function trace_methods(string $corpus, array $pairs, array $psr4): array
{
    $t0 = hrtime(true);

    $tracer = new MethodTracer;
    $edges = 0;

    foreach ($pairs as [$fqcn, $method]) {
        $edges += count($tracer->traceMethod($fqcn, $method, $psr4, $corpus));
    }

    $ms = (hrtime(true) - $t0) / 1e6;

    $counts = [
        'traced_methods' => count($pairs),
        'traced_edges' => $edges,
        'process_peak_mem_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
    ];

    $parses = parse_count();

    if ($parses !== null) {
        $counts['parse_calls'] = $parses;
    }

    return ['ms' => $ms, 'counts' => $counts, 'phases_ms' => []];
}

// ─── run ────────────────────────────────────────────────────────────────────

$small = corpus($corpusDir, 1.0);
$large = corpus($corpusDir, 3.0);

$scenarios = [
    'scan-small' => [
        'label' => sprintf('Full scan — %s files', number_format(php_file_count($small.'/app'))),
        'run' => static fn (array $prepared): array => scan($small),
    ],
    'scan-large' => [
        'label' => sprintf('Full scan — %s files', number_format(php_file_count($large.'/app'))),
        'run' => static fn (array $prepared): array => scan($large),
    ],
    'trace-methods' => [
        'label' => 'Method tracing — every entry-point method',
        // Enumerating the entry points is the consumer's job, not the tracer's,
        // so it happens once, before any repetition is timed.
        'prepare' => static fn (): array => [entry_point_methods($small), psr4_map($small)],
        'run' => static function (array $prepared) use ($small): array {
            [$pairs, $psr4] = $prepared;

            return trace_methods($small, $pairs, $psr4);
        },
    ],
];

if ($only !== null) {
    if (! isset($scenarios[$only])) {
        fwrite(STDERR, "unknown scenario {$only}; have: ".implode(', ', array_keys($scenarios))."\n");
        exit(1);
    }

    $scenarios = [$only => $scenarios[$only]];
}

$results = [];

foreach ($scenarios as $name => $scenario) {
    $prepared = isset($scenario['prepare']) ? ($scenario['prepare'])() : [];
    $run = $scenario['run'];
    $measured = measure(static fn (): array => $run($prepared), $reps, $warmups);
    $results[$name] = ['label' => $scenario['label']] + $measured;

    if (! $measured['counts_stable']) {
        fwrite(STDERR, sprintf("WARNING: %s produced different counts across repetitions\n", $name));
    }
}

$payload = [
    'meta' => [
        'php' => PHP_VERSION,
        'reps' => $reps,
        'warmups' => $warmups,
        'corpus_dir' => $corpusDir,
    ],
    'scenarios' => $results,
];

// ─── output ─────────────────────────────────────────────────────────────────

if ($asJson) {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    if ($out !== null) {
        file_put_contents($out, $json);
        exit(0);
    }

    echo $json;
    exit(0);
}

if ($asMarkdown) {
    printf(
        "## Benchmark results\n\n_PHP %s, median of %d %s._\n\n",
        PHP_VERSION,
        $reps,
        $reps === 1 ? 'repetition' : 'repetitions',
    );
    echo "| Scenario | Median | Nodes | Edges | Routes | parse() |\n";
    echo "|---|---:|---:|---:|---:|---:|\n";

    $count = static fn (?int $value): string => $value === null ? '—' : number_format($value);

    foreach ($results as $r) {
        printf(
            "| %s | %.0f ms | %s | %s | %s | %s |\n",
            $r['label'],
            $r['median_ms'],
            $count($r['counts']['nodes'] ?? null),
            $count($r['counts']['edges'] ?? $r['counts']['traced_edges'] ?? null),
            $count($r['counts']['routes'] ?? null),
            $count($r['counts']['parse_calls'] ?? null),
        );
    }

    exit(0);
}

fprintf(STDERR, "\n  PHP %s · %d warmup + %d timed repetitions · corpora in %s\n\n", PHP_VERSION, $warmups, $reps, $corpusDir);

foreach ($results as $name => $r) {
    fprintf(STDERR, "  %-14s %-44s %8.1f ms\n", $name, $r['label'], $r['median_ms']);

    foreach ($r['counts'] as $key => $value) {
        if (! str_starts_with($key, 'node_type:')) {
            fprintf(STDERR, "  %-14s   %-42s %8d\n", '', $key, $value);
        }
    }

    foreach ($r['phases_ms'] as $phase => $ms) {
        fprintf(STDERR, "  %-14s   %-42s %8.1f ms\n", '', 'phase '.$phase, $ms);
    }

    fprintf(STDERR, "\n");
}
