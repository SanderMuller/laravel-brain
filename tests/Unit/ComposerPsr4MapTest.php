<?php

use LaraMint\LaravelBrain\Analysis\ComposerPsr4Map;

it('maps the namespaces declared in the root composer.json', function () {
    $map = ComposerPsr4Map::build(fixture('modular-project'));

    expect($map)->toHaveKey('App')
        ->and($map['App'])->toBe([fixture('modular-project').'/app']);
});

it('maps a local package installed from a path repository', function () {
    $map = ComposerPsr4Map::build(fixture('modular-project'));

    expect($map)->toHaveKey('Acme\\Shop')
        ->and($map['Acme\\Shop'])->toBe([fixture('modular-project').'/packages/shop/src']);
});

it('leaves regular vendor dependencies out of the map', function () {
    $map = ComposerPsr4Map::build(fixture('modular-project'));

    // Resolving vendor classes would let the call-chain tracer walk into library
    // internals, which is not what the graph is for.
    expect($map)->not->toHaveKey('Acme\\VendorLib');
});

it('reads a path repository package that installed.json does not list', function () {
    $map = ComposerPsr4Map::build(fixture('modular-project'));

    // acme/blog is declared as a path repository but was never installed — the case of
    // a fresh checkout, or a package that is present but not required.
    expect($map)->toHaveKey('Acme\\Blog')
        ->and($map['Acme\\Blog'])->toBe([fixture('modular-project').'/packages/blog/src']);
});

it('keeps the conventional nwidart module mapping', function () {
    $map = ComposerPsr4Map::build(fixture('nwidart-project'));

    expect($map)->toHaveKey('Modules\\Sales')
        ->and($map['Modules\\Sales'])->toBe([fixture('nwidart-project').'/Modules/Sales/app']);
});

it('returns an empty map for a directory with no composer.json', function () {
    expect(ComposerPsr4Map::build(__DIR__.'/does-not-exist'))->toBe([]);
});
