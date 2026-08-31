<?php

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use LaraMint\LaravelBrain\Storage\DatabaseGraphStore;

/**
 * The database driver needs a connection but not an application: a Capsule over
 * in-memory sqlite is enough for the DB and Schema facades the store reaches for.
 */
function databaseGraphStore(): DatabaseGraphStore
{
    $container = new Container;
    Container::setInstance($container);

    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);

    $store = new DatabaseGraphStore;
    $store->ensureSchema();

    return $store;
}

/** @param string[] $tabIds */
function seedDatabaseStore(DatabaseGraphStore $store, array $tabIds): void
{
    $store->putManifest('{"tabs":[]}');

    foreach ($tabIds as $tabId) {
        $store->putSubgraph($tabId, '{"nodes":[],"edges":[]}');
    }
}

afterEach(function () {
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
});

it('drops the rows the scan no longer wrote', function () {
    $store = databaseGraphStore();
    seedDatabaseStore($store, ['tab-a', 'tab-b', 'tab-c']);

    $store->pruneSubgraphsExcept(['tab-a', 'tab-c']);

    expect($store->subgraphIds())->toBe(['tab-a', 'tab-c'])
        ->and($store->getSubgraph('tab-b'))->toBeNull();
});

it('keeps the manifest when the keep list is empty', function () {
    $store = databaseGraphStore();
    seedDatabaseStore($store, ['tab-a', 'tab-b']);

    // `whereNotIn('tab', [])` compiles to `1 = 1`, so this statement deletes every row
    // it is not otherwise constrained away from — the `tab != __manifest__` clause is
    // the only thing standing between an empty scan and losing the manifest with it.
    $store->pruneSubgraphsExcept([]);

    expect($store->subgraphIds())->toBe([])
        ->and($store->hasManifest())->toBeTrue()
        ->and($store->getManifest())->toBe('{"tabs":[]}');
});

it('keeps every row the scan wrote', function () {
    $store = databaseGraphStore();
    seedDatabaseStore($store, ['tab-a', 'tab-b']);

    $store->pruneSubgraphsExcept(['tab-a', 'tab-b']);

    expect($store->subgraphIds())->toBe(['tab-a', 'tab-b']);
});

it('is a no-op before the table exists', function () {
    $container = new Container;
    Container::setInstance($container);

    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);

    $store = new DatabaseGraphStore;

    $store->pruneSubgraphsExcept(['tab-a']);

    expect($store->subgraphIds())->toBe([]);
});
