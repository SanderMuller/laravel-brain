<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ProjectFileIndex;

beforeEach(function () {
    ProjectFileIndex::clear();

    $this->root = sys_get_temp_dir().'/brain-file-index-'.uniqid();
    mkdir($this->root.'/app/Nested', 0o777, true);
    mkdir($this->root.'/src', 0o777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
    ProjectFileIndex::clear();
});

it('honours the caller\'s directory precedence', function () {
    file_put_contents($this->root.'/app/Thing.php', '<?php');
    file_put_contents($this->root.'/src/Thing.php', '<?php');

    expect(ProjectFileIndex::findFile($this->root, ['app', 'src'], 'Thing.php'))
        ->toBe($this->root.'/app/Thing.php')
        ->and(ProjectFileIndex::findFile($this->root, ['src', 'app'], 'Thing.php'))
        ->toBe($this->root.'/src/Thing.php');
});

it('finds files in nested directories and reports misses as null', function () {
    file_put_contents($this->root.'/app/Nested/Deep.php', '<?php');

    expect(ProjectFileIndex::findFile($this->root, ['app'], 'Deep.php'))
        ->toBe($this->root.'/app/Nested/Deep.php')
        ->and(ProjectFileIndex::findFile($this->root, ['app'], 'Absent.php'))->toBeNull()
        ->and(ProjectFileIndex::findFile($this->root, ['does-not-exist'], 'Deep.php'))->toBeNull();
});

it('sees files added after a previous build once cleared', function () {
    expect(ProjectFileIndex::findFile($this->root, ['app'], 'Late.php'))->toBeNull();

    file_put_contents($this->root.'/app/Late.php', '<?php');

    // Within a build the index is deliberately stable; ProjectAnalyzer::analyze() clears it.
    expect(ProjectFileIndex::findFile($this->root, ['app'], 'Late.php'))->toBeNull();

    ProjectFileIndex::clear();

    expect(ProjectFileIndex::findFile($this->root, ['app'], 'Late.php'))
        ->toBe($this->root.'/app/Late.php');
});
