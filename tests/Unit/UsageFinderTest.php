<?php

use LaraMint\LaravelBrain\Ai\UsageFinder;
use LaraMint\LaravelBrain\Storage\FileGraphStore;

function usageFinderTmpDir(): string
{
    $dir = sys_get_temp_dir().'/lb-usages-'.uniqid('', true);
    mkdir($dir, 0777, true);

    return $dir;
}

function usageFinderRmTree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($dir.'/'.$entry);
        }
    }
    @rmdir($dir);
}

/**
 * @param  list<array<string, mixed>>  $edges
 * @return array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
 */
function usageFinderSampleGraph(array $edges): array
{
    return [
        'meta' => ['project' => 'demo'],
        'nodes' => [
            ['id' => 'target', 'type' => 'service', 'label' => 'Target@run', 'data' => ['file' => '/app/Services/Target.php']],
            ['id' => 'caller-a', 'type' => 'action', 'label' => 'A@handle', 'data' => ['file' => '/app/Http/Controllers/A.php']],
            ['id' => 'caller-b', 'type' => 'action', 'label' => 'B@handle', 'data' => ['file' => '/app/Http/Controllers/B.php']],
        ],
        'edges' => $edges,
    ];
}

it('finds usages of a shared node across multiple route subgraphs', function () {
    $dir = usageFinderTmpDir();
    try {
        file_put_contents($dir.'/.graph-manifest.json', json_encode(['tabs' => []]));
        file_put_contents($dir.'/.graph-a.json', json_encode([
            'meta' => ['project' => 'demo', 'analyzedAt' => '2026-05-16', 'nodeCount' => 2, 'edgeCount' => 1],
            'nodes' => [
                ['id' => 'action::OrderController::store', 'type' => 'action', 'label' => 'OrderController@store', 'data' => ['file' => '/app/Http/Controllers/OrderController.php']],
                ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place', 'data' => ['file' => '/app/Services/OrderService.php']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'action::OrderController::store', 'target' => 'service::OrderService::place', 'label' => 'calls', 'type' => 'action-to-service'],
            ],
        ]));
        file_put_contents($dir.'/.graph-b.json', json_encode([
            'meta' => ['project' => 'demo', 'analyzedAt' => '2026-05-16', 'nodeCount' => 2, 'edgeCount' => 1],
            'nodes' => [
                ['id' => 'action::InvoiceController::create', 'type' => 'action', 'label' => 'InvoiceController@create', 'data' => ['file' => '/app/Http/Controllers/InvoiceController.php']],
                // Shared node, same id as in graph-a — MergedGraph must de-dupe it.
                ['id' => 'service::OrderService::place', 'type' => 'service', 'label' => 'OrderService@place', 'data' => ['file' => '/app/Services/OrderService.php']],
            ],
            'edges' => [
                ['id' => 'e2', 'source' => 'action::InvoiceController::create', 'target' => 'service::OrderService::place', 'label' => 'calls', 'type' => 'action-to-service'],
            ],
        ]));

        $result = UsageFinder::find(new FileGraphStore($dir), 'service::OrderService::place');

        expect($result)->not->toBeNull()
            ->and($result['usageCount'])->toBe(2)
            ->and($result['fileCount'])->toBe(2);

        $files = array_column($result['files'], 'file');
        sort($files);
        expect($files)->toBe([
            '/app/Http/Controllers/InvoiceController.php',
            '/app/Http/Controllers/OrderController.php',
        ]);
    } finally {
        usageFinderRmTree($dir);
    }
});

it('throws when no scan data is present', function () {
    $dir = usageFinderTmpDir();
    try {
        UsageFinder::find(new FileGraphStore($dir), 'anything');
    } finally {
        usageFinderRmTree($dir);
    }
})->throws(RuntimeException::class, 'No scan data found');

it('collapses duplicate edges from the same caller into a single usage', function () {
    // The graph keeps duplicate edges on purpose (e.g. a method calling the
    // same dependency twice) — usage count must dedupe by source node, not
    // count raw edges.
    $graph = usageFinderSampleGraph([
        ['id' => 'e1', 'source' => 'caller-a', 'target' => 'target', 'label' => 'calls', 'type' => 'x'],
        ['id' => 'e1_1', 'source' => 'caller-a', 'target' => 'target', 'label' => 'calls', 'type' => 'x'],
    ]);

    $result = UsageFinder::findInGraph($graph, 'target');

    expect($result['usageCount'])->toBe(1)
        ->and($result['fileCount'])->toBe(1)
        ->and($result['files'][0]['count'])->toBe(1)
        ->and($result['files'][0]['usages'])->toHaveCount(1);
});

it('excludes self-loops from the usage count', function () {
    $graph = usageFinderSampleGraph([
        ['id' => 'e1', 'source' => 'target', 'target' => 'target', 'label' => 'recurses', 'type' => 'x'],
        ['id' => 'e2', 'source' => 'caller-a', 'target' => 'target', 'label' => 'calls', 'type' => 'x'],
    ]);

    $result = UsageFinder::findInGraph($graph, 'target');

    expect($result['usageCount'])->toBe(1)
        ->and($result['files'][0]['file'])->toBe('/app/Http/Controllers/A.php');
});

it('returns null for an unknown node id', function () {
    expect(UsageFinder::findInGraph(usageFinderSampleGraph([]), 'does-not-exist'))->toBeNull();
});

it('reports zero usages for a node nothing references', function () {
    $result = UsageFinder::findInGraph(usageFinderSampleGraph([]), 'target');

    expect($result['usageCount'])->toBe(0)
        ->and($result['fileCount'])->toBe(0)
        ->and($result['files'])->toBe([]);
});

it('groups usages from two different callers by their distinct files', function () {
    $graph = usageFinderSampleGraph([
        ['id' => 'e1', 'source' => 'caller-a', 'target' => 'target', 'label' => 'calls', 'type' => 'x'],
        ['id' => 'e2', 'source' => 'caller-b', 'target' => 'target', 'label' => 'calls', 'type' => 'x'],
    ]);

    $result = UsageFinder::findInGraph($graph, 'target');

    expect($result['usageCount'])->toBe(2)
        ->and($result['fileCount'])->toBe(2)
        ->and($result['files'])->toHaveCount(2);
});

it('keeps fileless callers in separate groups instead of merging them', function () {
    $graph = [
        'meta' => ['project' => 'demo'],
        'nodes' => [
            ['id' => 'target', 'type' => 'service', 'label' => 'Target@run', 'data' => ['file' => '/app/Services/Target.php']],
            ['id' => 'caller-a', 'type' => 'interface', 'label' => 'A', 'data' => []],
            ['id' => 'caller-b', 'type' => 'interface', 'label' => 'B', 'data' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'caller-a', 'target' => 'target', 'label' => 'implements', 'type' => 'x'],
            ['id' => 'e2', 'source' => 'caller-b', 'target' => 'target', 'label' => 'implements', 'type' => 'x'],
        ],
    ];

    $result = UsageFinder::findInGraph($graph, 'target');

    expect($result['usageCount'])->toBe(2)
        ->and($result['fileCount'])->toBe(0)
        ->and($result['files'])->toHaveCount(2);
});
