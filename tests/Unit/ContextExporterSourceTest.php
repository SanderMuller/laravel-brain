<?php

use LaraMint\LaravelBrain\Ai\ContextExporter;
use LaraMint\LaravelBrain\Storage\FileGraphStore;

/**
 * A project with one node whose `file` points wherever the caller says.
 *
 * `$projectPath` is what the exporter is confined to. Passing `''` turns the containment check
 * off, which is the only way to reach the guard behind it — with a root set, `realpath()` refuses
 * an empty or missing path before the guard is ever asked about it.
 */
function exporterFixture(string $nodeFile, ?string $projectPath = null): array
{
    $project = sys_get_temp_dir().'/lb_ctx_'.bin2hex(random_bytes(6));
    mkdir($project.'/storage', 0o777, true);

    $store = new FileGraphStore($project.'/storage');
    $store->ensureSchema();
    $store->putManifest(json_encode(['project' => 'demo', 'analyzedAt' => '2026-01-01T00:00:00+00:00', 'tabs' => []]));
    $store->putSubgraph('tab-a', json_encode([
        'nodes' => [[
            'id' => 'service::Demo',
            'label' => 'Demo',
            'type' => 'service',
            'data' => ['id' => 'service::Demo', 'label' => 'Demo', 'type' => 'service', 'file' => $nodeFile],
        ]],
        'edges' => [],
    ]));

    return [$project, new ContextExporter($store, $projectPath ?? $project)];
}

it('includes the source of the file a node names', function () {
    $project = sys_get_temp_dir().'/lb_ctx_'.bin2hex(random_bytes(6));
    mkdir($project.'/app', 0o777, true);
    $file = $project.'/app/Demo.php';
    file_put_contents($file, "<?php\n\nclass Demo { public function run(): void {} }\n");

    mkdir($project.'/storage', 0o777, true);
    $store = new FileGraphStore($project.'/storage');
    $store->ensureSchema();
    $store->putManifest(json_encode(['project' => 'demo', 'analyzedAt' => '2026-01-01T00:00:00+00:00', 'tabs' => []]));
    $store->putSubgraph('tab-a', json_encode([
        'nodes' => [[
            'id' => 'service::Demo',
            'label' => 'Demo',
            'type' => 'service',
            'data' => ['id' => 'service::Demo', 'label' => 'Demo', 'type' => 'service', 'file' => $file],
        ]],
        'edges' => [],
    ]));

    $out = (new ContextExporter($store, $project))->export(nodeId: 'service::Demo');

    expect($out)->toContain('## Source: Demo (focal)')
        ->toContain('class Demo { public function run(): void {} }');
});

it('reads nothing for a node whose file is missing', function () {
    // Unconfined on purpose: with a project root set, containment refuses this path first and
    // the guard under test never runs.
    [$project, $exporter] = exporterFixture('/does/not/exist/Demo.php', projectPath: '');

    // An empty export is not enough to prove the guard: `file_get_contents()` on a missing file
    // returns false, which reads as "no source" either way. What separates them is the warning it
    // raises on the way — and Laravel's `HandleExceptions` turns that into an `ErrorException`,
    // so in an application the unguarded path does not read as empty, it throws.
    set_error_handler(function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        expect($exporter->export(nodeId: 'service::Demo'))->not->toContain('## Source:');
    } finally {
        restore_error_handler();
    }
});

it('refuses a file outside the project being analysed', function () {
    // Node paths come from the scan, but an export is something a person hands to a model;
    // reading outside the tree is not a mistake worth being able to make.
    $outside = sys_get_temp_dir().'/lb_outside_'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($outside, "<?php\n// secret\n");

    [$project, $exporter] = exporterFixture($outside);

    expect($exporter->export(nodeId: 'service::Demo'))
        ->not->toContain('## Source:')
        ->not->toContain('secret');

    @unlink($outside);
});

it('reads nothing when the node names no file', function () {
    [$project, $exporter] = exporterFixture('', projectPath: '');

    expect($exporter->export(nodeId: 'service::Demo'))->not->toContain('## Source:');
});

// ── Dependency tables must fit the budget, not consume it ─────────────────────

function exporterWithManyPackages(): array
{
    $project = sys_get_temp_dir().'/lb_ctx_'.bin2hex(random_bytes(6));
    mkdir($project.'/app', 0o777, true);
    mkdir($project.'/storage', 0o777, true);

    $require = [];
    $dependencies = [];
    for ($i = 0; $i < 120; $i++) {
        $require["vendor/package-with-a-longish-name-{$i}"] = '^1.0';
        $dependencies["@scope/frontend-package-with-a-longish-name-{$i}"] = '^1.0';
    }
    file_put_contents($project.'/composer.json', json_encode(['require' => $require]));
    file_put_contents($project.'/package.json', json_encode(['dependencies' => $dependencies]));

    // Long enough that the snippet is truncated at every budget these tests use — the budget
    // invariant below is only worth asserting on an export that actually hits the marker.
    $file = $project.'/app/Demo.php';
    file_put_contents($file, "<?php\n\nclass Demo\n{\n".str_repeat("    // filler\n", 500)."    public function run(): void {}\n}\n");

    $store = new FileGraphStore($project.'/storage');
    $store->ensureSchema();
    $store->putManifest(json_encode(['project' => 'demo', 'analyzedAt' => '2026-01-01T00:00:00+00:00', 'tabs' => []]));
    $store->putSubgraph('tab-a', json_encode([
        'nodes' => [[
            'id' => 'service::Demo',
            'label' => 'Demo',
            'type' => 'service',
            'data' => ['id' => 'service::Demo', 'label' => 'Demo', 'type' => 'service', 'file' => $file],
        ]],
        'edges' => [],
    ]));

    return [new ContextExporter($store, $project), $file];
}

it('does not let the dependency list eat the whole budget', function () {
    [$exporter] = exporterWithManyPackages();

    $out = $exporter->export(nodeId: 'service::Demo', budget: 500);

    // The point of the export is the code the focal node runs; 120 package rows are not it.
    expect($out)->toContain('## Source: Demo (focal)')
        ->toContain('class Demo');
});

it('says how many packages it left out rather than implying the list is whole', function () {
    [$exporter] = exporterWithManyPackages();

    $out = $exporter->export(nodeId: 'service::Demo', budget: 500);

    expect($out)->toMatch('/\|\s*…and \d+ more\s*\|/');
});

it('keeps the export inside the budget it was given', function () {
    [$exporter] = exporterWithManyPackages();

    $out = $exporter->export(nodeId: 'service::Demo', budget: 500);

    // 4 characters per token is the ratio the exporter itself budgets with.
    expect(strlen($out))->toBeLessThanOrEqual(500 * 4);
});

// ── Neither table may vanish without a trace ─────────────────────────────────

it('keeps the frontend table from being crowded out by the backend one', function () {
    // The backend table used to take the whole share, and an absent table says the same thing
    // as "this project has no package.json" — which is the distinction the truncation row exists
    // to preserve.
    [$exporter] = exporterWithManyPackages();

    $out = $exporter->export(nodeId: 'service::Demo', budget: 2000);

    // Rows, not the heading: a crowded-out table still prints its heading and its omission
    // count, so asserting the heading alone would pass with the share unsplit.
    expect($out)->toContain('| vendor/package-with-a-longish-name-0 |')
        ->toContain('| @scope/frontend-package-with-a-longish-name-0 |');
});

it('names a table it could not fit at all rather than omitting it silently', function () {
    [$exporter] = exporterWithManyPackages();

    $out = $exporter->export(nodeId: 'service::Demo', budget: 100);

    expect($out)->toContain('## Frontend Packages (package.json)')
        ->toMatch('/…all 120 omitted/');
});

// ── One file, many nodes ─────────────────────────────────────────────────────

it('emits a file once however many nodes name it', function () {
    // A class and its methods, a resource and its pages: nodes name files far more often than
    // files exist. Emitting per node spends the budget on copies of a body already in the export.
    $project = sys_get_temp_dir().'/lb_ctx_'.bin2hex(random_bytes(6));
    mkdir($project.'/app', 0o777, true);
    mkdir($project.'/storage', 0o777, true);

    $file = $project.'/app/Demo.php';
    file_put_contents($file, "<?php\n\nclass Demo { public function run(): void {} }\n");

    $node = fn (string $id, string $label): array => [
        'id' => $id,
        'label' => $label,
        'type' => 'service',
        'data' => ['id' => $id, 'label' => $label, 'type' => 'service', 'file' => $file],
    ];

    $store = new FileGraphStore($project.'/storage');
    $store->ensureSchema();
    $store->putManifest(json_encode(['project' => 'demo', 'analyzedAt' => '2026-01-01T00:00:00+00:00', 'tabs' => []]));
    $store->putSubgraph('tab-a', json_encode([
        'nodes' => [$node('service::Demo', 'Demo'), $node('method::Demo@run', 'Demo@run')],
        'edges' => [['source' => 'service::Demo', 'target' => 'method::Demo@run', 'type' => 'calls']],
    ]));

    $out = (new ContextExporter($store, $project))->export(nodeId: 'service::Demo');

    expect(substr_count($out, 'class Demo { public function run(): void {} }'))->toBe(1);
});
