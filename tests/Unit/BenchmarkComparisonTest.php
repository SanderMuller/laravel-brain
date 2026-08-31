<?php

/**
 * The benchmark comparison decides what a pull request comment claims, so the
 * claims are tested: a count difference must be reported, an identical run must
 * not invent one, a timing delta inside the machine's own spread must be labelled
 * as such, and an arm whose counts did not reproduce must be flagged loudly.
 *
 * compare.php is run as its own process, the way CI runs it.
 */
$compare = dirname(__DIR__, 2).'/benchmark/compare.php';

/**
 * One round of one arm, in the shape benchmark.php writes.
 *
 * @param  list<float>  $times
 * @param  array<string, int>  $counts
 */
function benchmarkRound(array $times, array $counts, bool $stable = true, ?array $phases = null): array
{
    return [
        'meta' => ['php' => '8.4.0', 'reps' => count($times), 'warmups' => 1],
        'scenarios' => [
            'scan-small' => [
                'label' => 'Full scan — 395 files',
                'times_ms' => $times,
                'median_ms' => $times[0],
                'counts' => $counts,
                'counts_stable' => $stable,
                'phases_ms' => $phases ?? ['graph' => 40.0, 'facades' => 20.0],
            ],
        ],
    ];
}

/** The phase split's row for one phase. */
function benchmarkPhaseRow(string $markdown, string $phase): string
{
    $split = substr($markdown, (int) strpos($markdown, 'Phase split'));

    foreach (explode("\n", $split) as $line) {
        if (str_starts_with($line, '| '.$phase.' |')) {
            return $line;
        }
    }

    throw new RuntimeException("no phase row for {$phase} in:\n".$markdown);
}

/** Remove a directory tree written by one of these tests. */
function removeBenchmarkFixture(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
}

/** Run compare.php the way CI runs it, and return stdout plus stderr. */
function runCompare(string $baseDir, string $headDir, array $extra = []): array
{
    $cmd = sprintf(
        '%s %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2).'/benchmark/compare.php'),
        escapeshellarg($baseDir),
        escapeshellarg($headDir),
    );

    foreach ($extra as $arg) {
        $cmd .= ' '.escapeshellarg($arg);
    }

    exec($cmd.' 2>&1', $output, $status);

    return [implode("\n", $output), $status];
}

/**
 * Write both arms to disk and return what compare.php prints.
 *
 * @param  list<array>  $baseRounds
 * @param  list<array>  $headRounds
 */
function runBenchmarkComparison(array $baseRounds, array $headRounds): string
{
    $root = sys_get_temp_dir().'/brain-compare-'.bin2hex(random_bytes(6));

    foreach (['base' => $baseRounds, 'head' => $headRounds] as $arm => $rounds) {
        mkdir($root.'/'.$arm, 0755, true);

        foreach ($rounds as $i => $round) {
            file_put_contents(sprintf('%s/%s/round-%d.json', $root, $arm, $i + 1), json_encode($round));
        }
    }

    [$markdown, $status] = runCompare($root.'/base', $root.'/head');

    removeBenchmarkFixture($root);

    expect($status)->toBe(0);

    return $markdown;
}

/**
 * The scenario's row from the timing table — not from the counts table above it,
 * and not from the phase split below, both of which mention the same scenario.
 */
function benchmarkTimingRow(string $markdown, string $label = 'Full scan — 395 files'): string
{
    $timing = substr($markdown, (int) strpos($markdown, '### Timing'));

    foreach (explode("\n", $timing) as $line) {
        if (str_starts_with($line, '| '.$label.' |')) {
            return $line;
        }
    }

    throw new RuntimeException("no timing row for {$label} in:\n".$markdown);
}

/**
 * Four rounds of the same numbers, which is what a quiet machine looks like.
 *
 * @param  array<string, int>  $counts
 */
function benchmarkArm(float $ms, array $counts, bool $stable = true): array
{
    return array_fill(0, 4, benchmarkRound([$ms, $ms], $counts, $stable));
}

it('reports a count difference and says which counts moved', function () {
    $markdown = runBenchmarkComparison(
        benchmarkArm(100.0, ['nodes' => 700, 'edges' => 1900, 'parse_calls' => 300]),
        benchmarkArm(100.0, ['nodes' => 712, 'edges' => 1900, 'parse_calls' => 300]),
    );

    expect($markdown)
        ->toContain('712 **+12**')
        ->toContain('`scan-small` / `nodes`: 700 → **712**')
        ->not->toContain('No count changed');
});

it('does not invent a count difference when nothing moved', function () {
    $counts = ['nodes' => 700, 'edges' => 1900, 'parse_calls' => 300];

    $markdown = runBenchmarkComparison(benchmarkArm(100.0, $counts), benchmarkArm(100.0, $counts));

    expect($markdown)->toContain('No count changed on either corpus');
});

it('reports a count that only one arm has', function () {
    $markdown = runBenchmarkComparison(
        benchmarkArm(100.0, ['nodes' => 700]),
        benchmarkArm(100.0, ['nodes' => 700, 'node_type:listener' => 22]),
    );

    expect($markdown)->toContain('`scan-small` / `node_type:listener`: (absent) → **22**');
});

it('labels a timing delta inside the machine\'s own spread as noise', function () {
    // The base arm's repetitions themselves range over ±20%, so a 10% delta is
    // not something this run can resolve.
    $noisy = [
        benchmarkRound([80.0, 80.0], ['nodes' => 700]),
        benchmarkRound([100.0, 100.0], ['nodes' => 700]),
        benchmarkRound([120.0, 120.0], ['nodes' => 700]),
        benchmarkRound([100.0, 100.0], ['nodes' => 700]),
    ];

    $markdown = runBenchmarkComparison($noisy, benchmarkArm(110.0, ['nodes' => 700]));

    expect(benchmarkTimingRow($markdown))->toContain('+10.0% _(within noise)_');
});

it('calls out a timing delta that is bigger than the noise', function () {
    $markdown = runBenchmarkComparison(
        benchmarkArm(100.0, ['nodes' => 700]),
        benchmarkArm(140.0, ['nodes' => 700]),
    );

    expect(benchmarkTimingRow($markdown))
        ->toContain('**+40.0%**')
        ->not->toContain('within noise');
});

it('refuses to judge a timing delta measured over too few repetitions', function () {
    $markdown = runBenchmarkComparison(
        [benchmarkRound([100.0], ['nodes' => 700])],
        [benchmarkRound([140.0], ['nodes' => 700])],
    );

    expect(benchmarkTimingRow($markdown))->toContain('too few repetitions to judge');
});

it('holds a phase to its own noise floor, not to the scan\'s', function () {
    // The facade phase is the volatile one: base repetitions ranging over 2x
    // make a halved head median unreadable, however steady the total looks.
    $volatile = [
        benchmarkRound([100.0, 100.0], ['nodes' => 700], phases: ['graph' => 40.0, 'facades' => 70.0]),
        benchmarkRound([100.0, 100.0], ['nodes' => 700], phases: ['graph' => 40.0, 'facades' => 33.0]),
        benchmarkRound([100.0, 100.0], ['nodes' => 700], phases: ['graph' => 40.0, 'facades' => 71.0]),
        benchmarkRound([100.0, 100.0], ['nodes' => 700], phases: ['graph' => 40.0, 'facades' => 130.0]),
    ];

    $steadyHead = array_fill(0, 4, benchmarkRound(
        [100.0, 100.0],
        ['nodes' => 700],
        phases: ['graph' => 40.0, 'facades' => 34.0],
    ));

    $markdown = runBenchmarkComparison($volatile, $steadyHead);

    expect(benchmarkPhaseRow($markdown, 'facades'))->toContain('within noise');
    // The steady phase in the same run still reads as steady.
    expect(benchmarkPhaseRow($markdown, 'graph'))->toContain('±0.0%');
});

it('warns when an arm did not reproduce its own counts', function () {
    $markdown = runBenchmarkComparison(
        benchmarkArm(100.0, ['nodes' => 700]),
        benchmarkArm(100.0, ['nodes' => 700], stable: false),
    );

    expect($markdown)
        ->toContain('[!WARNING]')
        ->toContain('not reproducible for `scan-small`');
});

it('does not treat process memory as a count that failed to reproduce', function () {
    // Peak memory is the process's, so it climbs from round to round while the
    // build is perfectly reproducible. Reading that as instability would put a
    // warning on every comparison.
    $markdown = runBenchmarkComparison(
        benchmarkArm(100.0, ['nodes' => 700, 'process_peak_mem_mb' => 40]),
        [
            benchmarkRound([100.0, 100.0], ['nodes' => 700, 'process_peak_mem_mb' => 40]),
            benchmarkRound([100.0, 100.0], ['nodes' => 700, 'process_peak_mem_mb' => 58]),
            benchmarkRound([100.0, 100.0], ['nodes' => 700, 'process_peak_mem_mb' => 61]),
            benchmarkRound([100.0, 100.0], ['nodes' => 700, 'process_peak_mem_mb' => 61]),
        ],
    );

    expect($markdown)
        ->not->toContain('[!WARNING]')
        ->not->toContain('process_peak_mem_mb')
        ->toContain('No count changed on either corpus');
});

it('warns when an arm reported different counts in different rounds', function () {
    $markdown = runBenchmarkComparison(
        benchmarkArm(100.0, ['nodes' => 700]),
        [
            benchmarkRound([100.0, 100.0], ['nodes' => 700]),
            benchmarkRound([100.0, 100.0], ['nodes' => 701]),
            benchmarkRound([100.0, 100.0], ['nodes' => 700]),
            benchmarkRound([100.0, 100.0], ['nodes' => 700]),
        ],
    );

    expect($markdown)->toContain('not reproducible for `scan-small`');
});

it('carries the shas it was given, and a marker the comment job can find', function () {
    $root = sys_get_temp_dir().'/brain-compare-'.bin2hex(random_bytes(6));

    foreach (['base', 'head'] as $arm) {
        mkdir($root.'/'.$arm, 0755, true);
        file_put_contents($root.'/'.$arm.'/round-1.json', json_encode(benchmarkRound([100.0, 100.0], ['nodes' => 700])));
    }

    [$markdown, $status] = runCompare($root.'/base', $root.'/head', [
        '--base-sha=1234567890abcdef',
        '--head-sha=fedcba0987654321',
    ]);

    removeBenchmarkFixture($root);

    expect($status)->toBe(0);

    expect($markdown)
        ->toStartWith('<!-- laravel-brain-benchmark -->')
        ->toContain('base `1234567` → head `fedcba0`');
});

it('fails instead of rendering half a comparison when an arm is missing', function () {
    $root = sys_get_temp_dir().'/brain-compare-'.bin2hex(random_bytes(6));
    mkdir($root.'/head', 0755, true);
    file_put_contents($root.'/head/round-1.json', json_encode(benchmarkRound([100.0], ['nodes' => 700])));

    [$output, $status] = runCompare($root.'/missing', $root.'/head');

    removeBenchmarkFixture($root);

    expect($status)->toBe(1);
    expect($output)->toContain('no benchmark result files');
});
