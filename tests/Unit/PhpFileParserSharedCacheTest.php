<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Parser\PhpFileParser;

/**
 * The shared parse cache has to satisfy three properties: it must actually deduplicate work
 * across analyzers, it must never serve a stale AST for a file that has changed, and it must
 * survive the one-second resolution of filemtime().
 */
beforeEach(function () {
    PhpFileParser::clearSharedCache();
    PhpFileParser::$parseCount = 0;

    $this->dir = sys_get_temp_dir().'/brain-shared-cache-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
    PhpFileParser::clearSharedCache();
});

/** Write $code to $name and pin its mtime, so tests do not race the wall clock. */
function writeSource(string $dir, string $name, string $code, int $mtime): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $code);
    touch($path, $mtime);
    clearstatcache(true, $path);

    return $path;
}

it('parses a settled file once across parser instances', function () {
    $file = writeSource($this->dir, 'Settled.php', "<?php class Settled {}\n", time() - 10);

    (new PhpFileParser)->parse($file);
    (new PhpFileParser)->parse($file);

    // Separate instances: this is the deduplication every analyzer benefits from.
    expect(PhpFileParser::$parseCount)->toBe(1);
});

it('re-parses a settled file after it is edited', function () {
    $file = writeSource($this->dir, 'Edited.php', "<?php\nuse Old\\Alias;\nclass Edited {}\n", time() - 10);

    $first = (new PhpFileParser)->parse($file);

    writeSource($this->dir, 'Edited.php', "<?php\nuse New\\Thing;\nclass Edited {}\n", time() - 5);
    $second = (new PhpFileParser)->parse($file);

    expect($first['useMap'])->toBe(['Alias' => 'Old\Alias'])
        ->and($second['useMap'])->toBe(['Thing' => 'New\Thing'])
        ->and(PhpFileParser::$parseCount)->toBe(2);
});

it('does not cache a file edited within the current second', function () {
    // Two versions of the same length, so an edit inside one second changes neither the mtime
    // nor the size the cache key is built from — the collision this guard exists for. The mtime
    // is pinned slightly ahead so the file counts as unsettled at both parses no matter where
    // in the second the test runs.
    $v1 = "<?php\nuse X\\Aaa;\nclass W {}\n";
    $v2 = "<?php\nuse X\\Bbb;\nclass W {}\n";
    expect(strlen($v2))->toBe(strlen($v1));

    $unsettled = time() + 2;

    $file = writeSource($this->dir, 'W.php', $v1, $unsettled);
    $first = (new PhpFileParser)->parse($file);

    writeSource($this->dir, 'W.php', $v2, $unsettled);
    $second = (new PhpFileParser)->parse($file);

    // Without the guard the second parse is answered by the first version's cached AST.
    expect($first['useMap'])->toBe(['Aaa' => 'X\Aaa'])
        ->and($second['useMap'])->toBe(['Bbb' => 'X\Bbb']);
});
