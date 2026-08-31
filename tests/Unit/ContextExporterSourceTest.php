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
