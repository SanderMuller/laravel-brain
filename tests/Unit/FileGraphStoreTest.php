<?php

use LaraMint\LaravelBrain\Storage\FileGraphStore;

function graphStoreTmpDir(): string
{
    $dir = sys_get_temp_dir().'/lb-store-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return $dir;
}

/** @param string[] $tabIds */
function seedStore(string $dir, array $tabIds): FileGraphStore
{
    $store = new FileGraphStore($dir);
    $store->ensureSchema();
    $store->putManifest('{"tabs":[]}');

    foreach ($tabIds as $tabId) {
        $store->putSubgraph($tabId, '{"nodes":[],"edges":[]}');
    }

    return $store;
}

it('drops a stored subgraph the scan no longer wrote', function () {
    $dir = graphStoreTmpDir();
    $store = seedStore($dir, ['get-users', 'get-orders']);

    // `get-orders` is the route that was deleted from the application.
    $store->pruneSubgraphsExcept(['get-users']);

    expect($store->subgraphIds())->toBe(['get-users'])
        ->and($store->getSubgraph('get-orders'))->toBeNull();
});

it('keeps every subgraph the scan wrote', function () {
    $dir = graphStoreTmpDir();
    $store = seedStore($dir, ['get-users', 'get-orders', 'post-orders']);

    $store->pruneSubgraphsExcept(['get-users', 'get-orders', 'post-orders']);

    expect($store->subgraphIds())->toHaveCount(3);
});

it('never prunes the manifest', function () {
    $dir = graphStoreTmpDir();
    $store = seedStore($dir, ['get-users']);

    // The manifest is not a tab, so an empty keep list must not take it with them.
    $store->pruneSubgraphsExcept([]);

    expect($store->subgraphIds())->toBe([])
        ->and($store->hasManifest())->toBeTrue()
        ->and($store->getManifest())->toBe('{"tabs":[]}');
});

it('is a no-op on a store that holds nothing yet', function () {
    $dir = graphStoreTmpDir();
    $store = new FileGraphStore($dir);

    $store->pruneSubgraphsExcept(['get-users']);

    expect($store->subgraphIds())->toBe([]);
});

it('leaves a subgraph whose id was written again under a new scan', function () {
    $dir = graphStoreTmpDir();
    $store = seedStore($dir, ['get-users']);

    $store->putSubgraph('get-users', '{"nodes":[{"id":"n1"}],"edges":[]}');
    $store->pruneSubgraphsExcept(['get-users']);

    expect($store->getSubgraph('get-users'))->toBe('{"nodes":[{"id":"n1"}],"edges":[]}');
});
