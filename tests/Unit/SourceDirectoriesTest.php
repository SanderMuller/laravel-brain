<?php

use LaraMint\LaravelBrain\Analysis\SourceDirectories;

it('keeps a path that names an existing directory', function () {
    expect(SourceDirectories::resolve(fixture('packaged-app'), ['packages/billing/src']))
        ->toBe(['packages/billing/src']);
});

it('expands a glob pattern into every directory it matches', function () {
    expect(SourceDirectories::resolve(fixture('packaged-app'), ['packages/*/src']))
        ->toBe(['packages/billing/src', 'packages/shipping/src']);
});

it('drops a path that does not exist', function () {
    expect(SourceDirectories::resolve(fixture('packaged-app'), ['app', 'packages/billing/src']))
        ->toBe(['packages/billing/src']);
});

it('yields each directory once when patterns overlap', function () {
    expect(SourceDirectories::resolve(fixture('packaged-app'), ['packages/*/src', 'packages/billing/src']))
        ->toBe(['packages/billing/src', 'packages/shipping/src']);
});

it('answers whether a path sits inside one of the directories', function () {
    $root = fixture('packaged-app');

    expect(SourceDirectories::contains($root, ['packages/billing/src'], $root.'/packages/billing/src/Support/SqlLedger.php'))
        ->toBeTrue()
        ->and(SourceDirectories::contains($root, ['packages/billing/src'], $root.'/config/app.php'))
        ->toBeFalse();
});

it('anchors containment at the project root rather than matching a substring', function () {
    // A project that itself lives under a directory called `app` would otherwise have
    // every one of its files answer "yes, inside app/".
    expect(SourceDirectories::contains('/srv/app/project', ['app'], '/srv/app/project/config/app.php'))
        ->toBeFalse();
});

it('keeps the controller prefix while app is a source path', function () {
    expect(SourceDirectories::classFilePrefixes(fixture('laravel-project'), ['app']))
        ->toBe(['app/Http/Controllers/', 'app/']);
});

it('builds prefixes from the configured directories', function () {
    expect(SourceDirectories::classFilePrefixes(fixture('packaged-app'), ['packages/*/src']))
        ->toBe(['packages/billing/src/', 'packages/shipping/src/']);
});
