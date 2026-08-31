<?php

declare(strict_types=1);

/**
 * Compare two arms of the benchmark and render the pull-request comment.
 *
 * Each arm is a directory of per-repetition JSON files written by
 * benchmark.php --out=<file>. The repetitions are expected to have been run
 * interleaved (base, head, base, head, …) in one session, so that machine load
 * lands on both arms rather than on one of them.
 *
 * Two kinds of number come out, and they are reported separately on purpose:
 *
 *   Counts    deterministic. A difference is a real change in what the scan
 *             detects, and is reported whether it looks like an improvement or
 *             not — an added analyzer legitimately changes them.
 *
 *   Timing    noisy on shared runners. The spread of the base arm's own
 *             repetitions is reported as a noise floor, and any delta inside it
 *             is labelled as such instead of being presented as a result.
 *
 * Usage:
 *   php benchmark/compare.php <baseDir> <headDir> [--base-sha=X] [--head-sha=Y]
 */
$baseDir = $argv[1] ?? null;
$headDir = $argv[2] ?? null;

if ($baseDir === null || $headDir === null) {
    fwrite(STDERR, "usage: compare.php <baseDir> <headDir> [--base-sha=X] [--head-sha=Y]\n");
    exit(1);
}

function opt(string $name): ?string
{
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
}

/**
 * Load every repetition in an arm.
 *
 * @return array{
 *     php: string,
 *     reps: int,
 *     samples: array<string, list<float>>,
 *     counts: array<string, array<string, int>>,
 *     labels: array<string, string>,
 *     phases: array<string, array<string, list<float>>>,
 *     unstable: list<string>,
 * }
 */
function load_arm(string $dir): array
{
    $files = glob(rtrim($dir, '/').'/*.json') ?: [];

    if ($files === []) {
        fwrite(STDERR, "no benchmark result files in {$dir}\n");
        exit(1);
    }

    sort($files);

    $php = '?';
    $samples = [];
    $counts = [];
    $labels = [];
    $phases = [];
    $unstable = [];
    $reps = 0;

    foreach ($files as $file) {
        $payload = json_decode((string) file_get_contents($file), true);

        if (! is_array($payload) || ! isset($payload['scenarios']) || ! is_array($payload['scenarios'])) {
            fwrite(STDERR, "unreadable benchmark result: {$file}\n");
            exit(1);
        }

        $php = (string) ($payload['meta']['php'] ?? $php);

        foreach ($payload['scenarios'] as $name => $scenario) {
            foreach ($scenario['times_ms'] ?? [] as $ms) {
                $samples[$name][] = (float) $ms;
                $reps++;
            }

            // Counts are deterministic, so they must agree in every repetition
            // and every round. If they do not, the arm is not reproducible and
            // no count delta from it means anything — say so instead of letting
            // whichever file was read last decide.
            if (($scenario['counts_stable'] ?? true) === false) {
                $unstable[$name] = true;
            }

            if (isset($counts[$name]) && deterministic_counts($counts[$name]) !== deterministic_counts($scenario['counts'] ?? [])) {
                $unstable[$name] = true;
            }

            $counts[$name] = $scenario['counts'] ?? [];
            $labels[$name] = (string) ($scenario['label'] ?? $name);

            foreach ($scenario['phases_ms'] ?? [] as $phase => $ms) {
                $phases[$name][$phase][] = (float) $ms;
            }
        }
    }

    return [
        'php' => $php,
        'reps' => $samples === [] ? 0 : (int) round($reps / count($samples)),
        'samples' => $samples,
        'counts' => $counts,
        'labels' => $labels,
        'phases' => $phases,
        'unstable' => array_keys($unstable),
    ];
}

/**
 * The counts that must reproduce, with the ones that cannot stripped out.
 *
 * Peak memory belongs to the process rather than to the scenario, so it moves
 * with scenario order and would make every round look like a different build.
 *
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function deterministic_counts(array $counts): array
{
    unset($counts['process_peak_mem_mb']);

    return $counts;
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
 * The machine's noise, as a percentage of the median: how far the base arm's
 * tenth and ninetieth percentiles sit from its own median. An effect smaller
 * than this is not measurable here.
 *
 * Percentiles rather than the extremes, because a shared runner stalls: one
 * repetition landing 50% off would otherwise set a floor that hides every real
 * regression behind it. Wide ones rather than the quartiles, because a runner
 * that is genuinely unsteady must still be allowed to say so — at ten
 * repetitions per arm this discounts the single worst on each side and no more.
 * The full spread is reported next to it either way.
 *
 * @param  list<float>  $xs
 * @return array{noise_pct: float, min: float, max: float}
 */
function spread(array $xs): array
{
    sort($xs);
    $m = median($xs);
    $n = count($xs);

    if ($m <= 0.0 || $n < 2) {
        return ['noise_pct' => 0.0, 'min' => $xs[0] ?? 0.0, 'max' => $xs[$n - 1] ?? 0.0];
    }

    $q = static function (float $p) use ($xs, $n): float {
        $i = (int) round($p * ($n - 1));

        return $xs[max(0, min($n - 1, $i))];
    };

    $worst = max(abs($q(0.10) - $m), abs($q(0.90) - $m));

    return ['noise_pct' => $worst / $m * 100, 'min' => $xs[0], 'max' => $xs[$n - 1]];
}

/**
 * How a timing delta should be reported, given the base arm's own repetitions.
 *
 * One rule for the totals and for the phase split, because a phase is exactly
 * as noisy as the scan it is part of — more so, being smaller.
 *
 * @param  list<float>  $baseSamples
 * @return array{delta: string, noise: string}
 */
function delta_verdict(array $baseSamples, float $baseMedian, float $headMedian): array
{
    $deltaPct = $baseMedian > 0 ? ($headMedian - $baseMedian) / $baseMedian * 100 : 0.0;
    $noise = spread($baseSamples)['noise_pct'];
    $delta = sprintf('%+.1f%%', $deltaPct);

    if (count($baseSamples) < 4) {
        // Too few repetitions to know what the machine was doing, so the noise
        // floor is not a floor yet and nothing here is worth emphasising.
        $delta = sprintf('%+.1f%% _(too few repetitions to judge)_', $deltaPct);
    } elseif (abs($deltaPct) <= $noise) {
        $delta = sprintf('%+.1f%% _(within noise)_', $deltaPct);
    } elseif (abs($deltaPct) >= 10.0) {
        $delta = sprintf('**%+.1f%%**', $deltaPct);
    }

    return ['delta' => $delta, 'noise' => sprintf('±%.1f%%', $noise)];
}

function delta_cell(int $base, int $head): string
{
    if ($base === $head) {
        return '';
    }

    return sprintf(' **%+d**', $head - $base);
}

$base = load_arm($baseDir);
$head = load_arm($headDir);

$scenarios = array_keys($head['samples']);
$baseSha = opt('base-sha');
$headSha = opt('head-sha');

// ─── header ─────────────────────────────────────────────────────────────────

// A stable marker, so the comment job can find and replace its own comment
// without matching on prose that may change.
$lines = ['<!-- laravel-brain-benchmark -->'];
$lines[] = '## Benchmark';
$lines[] = '';

$provenance = sprintf('PHP %s · %d repetitions per arm, interleaved in one job', $head['php'], $head['reps']);

if ($baseSha !== null && $headSha !== null) {
    $provenance = sprintf('base `%s` → head `%s` · %s', substr($baseSha, 0, 7), substr($headSha, 0, 7), $provenance);
}

$lines[] = $provenance;
$lines[] = '';

$unstable = array_values(array_unique(array_merge($base['unstable'], $head['unstable'])));

if ($unstable !== []) {
    $lines[] = '> [!WARNING]';
    $lines[] = sprintf(
        '> The counts below are not reproducible for %s: repetitions of the same code over the same generated application disagreed. Read no count delta from this run until that is explained.',
        implode(', ', array_map(static fn (string $n): string => '`'.$n.'`', $unstable)),
    );
    $lines[] = '';
}

// ─── what the scan detected ─────────────────────────────────────────────────

$lines[] = '### What the scan detects';
$lines[] = '';
$lines[] = 'Deterministic: both arms scan the same generated application on the same PHP version, and every repetition is checked to produce the same figures. A difference here is a change in what Brain detects, not machine noise — which is worth looking at rather than automatically worth fixing, since detecting more legitimately moves these.';
$lines[] = '';
$lines[] = '| Scenario | Nodes | Edges | Tabs | Routes | Security issues | parse() calls |';
$lines[] = '|---|---:|---:|---:|---:|---:|---:|';

$changedCounts = [];

foreach ($scenarios as $name) {
    $b = $base['counts'][$name] ?? [];
    $h = $head['counts'][$name] ?? [];

    $cell = static function (string $key) use ($b, $h): string {
        if (! array_key_exists($key, $h)) {
            return '—';
        }

        $headValue = (int) $h[$key];

        if (! array_key_exists($key, $b)) {
            return sprintf('%s **new**', number_format($headValue));
        }

        return number_format($headValue).delta_cell((int) $b[$key], $headValue);
    };

    $lines[] = sprintf(
        '| %s | %s | %s | %s | %s | %s | %s |',
        $head['labels'][$name] ?? $name,
        $cell('nodes'),
        array_key_exists('edges', $h) ? $cell('edges') : $cell('traced_edges'),
        $cell('tabs'),
        $cell('routes'),
        $cell('security_issues'),
        $cell('parse_calls'),
    );

    // Every count, not just the ones the table has room for — including the
    // per-node-type breakdown, where a single analyzer change shows up first.
    foreach ($h as $key => $value) {
        // Process-cumulative, so it depends on scenario order rather than on
        // the change: reported in the JSON, never diffed here.
        if ($key === 'process_peak_mem_mb') {
            continue;
        }

        $before = $b[$key] ?? null;

        if ($before !== (int) $value) {
            $changedCounts[] = sprintf(
                '- `%s` / `%s`: %s → **%s**',
                $name,
                $key,
                $before === null ? '(absent)' : number_format((int) $before),
                number_format((int) $value),
            );
        }
    }

    foreach ($b as $key => $value) {
        if ($key !== 'process_peak_mem_mb' && ! array_key_exists($key, $h)) {
            $changedCounts[] = sprintf('- `%s` / `%s`: %s → **(absent)**', $name, $key, number_format((int) $value));
        }
    }
}

$lines[] = '';

if ($changedCounts === []) {
    $lines[] = 'No count changed on either corpus, including the per-node-type breakdown.';
} else {
    $lines[] = '<details><summary>'.count($changedCounts).' count(s) changed — full list</summary>';
    $lines[] = '';
    foreach ($changedCounts as $line) {
        $lines[] = $line;
    }
    $lines[] = '';
    $lines[] = '</details>';
}

$lines[] = '';

// ─── timing ─────────────────────────────────────────────────────────────────

$lines[] = '### Timing';
$lines[] = '';
$lines[] = 'Median wall clock on a shared CI runner. Noise is how far the base arm\'s own repetitions sat from its median, discounting the single worst on each side, with their full spread beside it; a delta inside the noise is not a measurable effect.';
$lines[] = '';
$lines[] = '| Scenario | Base | Head | Δ | Noise | Base spread |';
$lines[] = '|---|---:|---:|---:|---:|---:|';

foreach ($scenarios as $name) {
    $baseSamples = $base['samples'][$name] ?? [];
    $headSamples = $head['samples'][$name] ?? [];

    if ($baseSamples === []) {
        $lines[] = sprintf('| %s | — | %.0f ms | new scenario | — | — |', $head['labels'][$name] ?? $name, median($headSamples));

        continue;
    }

    $bm = median($baseSamples);
    $hm = median($headSamples);
    $baseSpread = spread($baseSamples);
    $verdict = delta_verdict($baseSamples, $bm, $hm);

    $lines[] = sprintf(
        '| %s | %.0f ms | %.0f ms | %s | %s | %.0f–%.0f ms |',
        $head['labels'][$name] ?? $name,
        $bm,
        $hm,
        $verdict['delta'],
        $verdict['noise'],
        $baseSpread['min'],
        $baseSpread['max'],
    );
}

$lines[] = '';

// ─── phase split ────────────────────────────────────────────────────────────

$phaseScenario = null;

foreach ($scenarios as $name) {
    if (($head['phases'][$name] ?? []) !== []) {
        $phaseScenario = $name;
    }
}

if ($phaseScenario !== null) {
    $lines[] = '<details><summary>Phase split — '.($head['labels'][$phaseScenario] ?? $phaseScenario).'</summary>';
    $lines[] = '';
    $lines[] = 'A phase carries at least as much runner noise as the scan it is part of, so each one gets its own noise floor from the base arm\'s repetitions of that phase. Useful for locating a large move; a phase that is a few percent of the scan cannot be read at a few percent of accuracy.';
    $lines[] = '';
    $lines[] = '| Phase | Base | Head | Δ | Noise |';
    $lines[] = '|---|---:|---:|---:|---:|';

    $headPhases = $head['phases'][$phaseScenario];
    $basePhases = $base['phases'][$phaseScenario] ?? [];

    $medians = [];

    foreach ($headPhases as $phase => $samples) {
        $medians[$phase] = median($samples);
    }

    arsort($medians);

    foreach ($medians as $phase => $hm) {
        $baseSamples = $basePhases[$phase] ?? null;
        $bm = $baseSamples === null ? null : median($baseSamples);

        // Phases that cost nothing on either arm are noise columns, not news.
        if ($hm < 1.0 && ($bm === null || $bm < 1.0)) {
            continue;
        }

        if ($bm === null || $bm <= 0.0) {
            $lines[] = sprintf('| %s | — | %.1f ms | — | — |', $phase, $hm);

            continue;
        }

        $verdict = delta_verdict($baseSamples, $bm, $hm);

        $lines[] = sprintf(
            '| %s | %.1f ms | %.1f ms | %s | %s |',
            $phase,
            $bm,
            $hm,
            $verdict['delta'],
            $verdict['noise'],
        );
    }

    $lines[] = '';
    $lines[] = '</details>';
    $lines[] = '';
}

$lines[] = '<sub>Scans a synthetic Laravel application generated by `benchmark/generate-corpus.php`, not a real one — the shapes are chosen to stress the scan, so treat the absolute times as a workload, not as a user\'s scan. Reproduce locally with `composer benchmark`.</sub>';

echo implode("\n", $lines)."\n";
