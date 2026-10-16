<?php

use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;
use LaraMint\LaravelBrain\Storage\FileGraphStore;
use LaraMint\LaravelBrain\Storage\ScanPublisher;

function publisherStore(): FileGraphStore
{
    $dir = sys_get_temp_dir().'/lb-publish-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return new FileGraphStore($dir);
}

/** @param array<string, array{int, int}> $tabs tabId => [nodeCount, edgeCount] */
function publisherManifest(string $analyzedAt, array $tabs): string
{
    $entries = [];
    foreach ($tabs as $id => [$nodes, $edges]) {
        $entries[] = ['id' => $id, 'label' => '/'.$id, 'nodeCount' => $nodes, 'edgeCount' => $edges, 'riskLevel' => 'none'];
    }

    return (string) json_encode(['project' => 'App', 'analyzedAt' => $analyzedAt, 'tabs' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/** @param string[] $tabIds */
function publisherSubgraphs(array $tabIds): array
{
    $subgraphs = [];
    foreach ($tabIds as $id) {
        $graph = new Graph;
        $graph->addNode(new Node("route::GET::/{$id}", 'route', "GET /{$id}"));
        $subgraphs[$id] = $graph;
    }

    return $subgraphs;
}

function publishedTabs(FileGraphStore $store): array
{
    $statuses = [];
    foreach (json_decode((string) $store->getManifest(), true)['tabs'] as $tab) {
        $statuses[$tab['id']] = $tab['changeStatus'] ?? null;
    }

    return $statuses;
}

it('marks every tab unchanged on the first scan', function () {
    $store = publisherStore();

    ScanPublisher::publish($store, publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2]]), publisherSubgraphs(['orders']));

    expect(publishedTabs($store))->toBe(['orders' => 'unchanged'])
        ->and(json_decode((string) $store->getManifest(), true))->not->toHaveKey('previousAnalyzedAt');
});

it('compares a scan with the one the store already holds', function () {
    // The viewer's "Recent" list shows exactly the tabs marked new or changed, measured from
    // previousAnalyzedAt. Without this, a scan that is not the viewer's own button left it empty.
    $store = publisherStore();
    ScanPublisher::publish(
        $store,
        publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2], 'users' => [3, 2], 'legacy' => [2, 1]]),
        publisherSubgraphs(['orders', 'users', 'legacy']),
    );

    ScanPublisher::publish(
        $store,
        publisherManifest('2026-01-02T00:00:00Z', ['orders' => [4, 3], 'users' => [3, 2], 'invoices' => [2, 1]]),
        publisherSubgraphs(['orders', 'users', 'invoices']),
    );

    $manifest = json_decode((string) $store->getManifest(), true);

    expect(publishedTabs($store))->toBe(['orders' => 'changed', 'users' => 'unchanged', 'invoices' => 'new'])
        ->and($manifest['previousAnalyzedAt'])->toBe('2026-01-01T00:00:00Z')
        ->and($store->subgraphIds())->toEqualCanonicalizing(['orders', 'users', 'invoices']);
});

it('stores the decorated manifest in the format the splitter writes', function () {
    $store = publisherStore();

    ScanPublisher::publish($store, publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2]]), publisherSubgraphs(['orders']));

    expect($store->getManifest())->toContain('"label": "/orders"');
});

it('leaves the manifest to the publisher alone', function () {
    // Each entry point used to write the manifest itself, and only one of the three decorated
    // it. Any other caller of putManifest() would bring that back.
    $root = dirname(__DIR__, 2).'/src';
    $writers = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = (string) $file;
        if (str_ends_with($path, '.php') && ! str_starts_with($path, $root.'/Storage/')
            && str_contains((string) file_get_contents($path), '->putManifest(')) {
            $writers[] = substr($path, strlen($root) + 1);
        }
    }

    expect($writers)->toBe([]);
});
