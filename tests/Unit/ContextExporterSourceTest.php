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

// ── Dependency tables must fit the budget, not consume it ─────────────────────

function exporterWithManyPackages(): array
{
    $project = sys_get_temp_dir().'/lb_ctx_'.bin2hex(random_bytes(6));
    mkdir($project.'/app', 0o777, true);
    mkdir($project.'/storage', 0o777, true);

    $require = [];
    for ($i = 0; $i < 120; $i++) {
        $require["vendor/package-with-a-longish-name-{$i}"] = '^1.0';
    }
    file_put_contents($project.'/composer.json', json_encode(['require' => $require]));

    $file = $project.'/app/Demo.php';
    file_put_contents($file, "<?php\n\nclass Demo\n{\n    public function run(): void {}\n}\n");

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
