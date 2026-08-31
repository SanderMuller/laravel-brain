<?php

use LaraMint\LaravelBrain\Ai\ContextExporter;
use LaraMint\LaravelBrain\Storage\FileGraphStore;

/** A project with one node whose `file` points wherever the caller says. */
function exporterFixture(string $nodeFile): array
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

    return [$project, new ContextExporter($store, $project)];
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
    [$project, $exporter] = exporterFixture('/does/not/exist/Demo.php');

    expect($exporter->export(nodeId: 'service::Demo'))->not->toContain('## Source:');
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
    [$project, $exporter] = exporterFixture('');

    expect($exporter->export(nodeId: 'service::Demo'))->not->toContain('## Source:');
});
