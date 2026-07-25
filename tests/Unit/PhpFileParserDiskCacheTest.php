<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * The persistent cache is only worth having if it is impossible to serve a stale or foreign
 * result from it. These cover the ways that could happen: an edited file, a corrupt blob, a
 * file being edited right now, and the cache being off.
 */
beforeEach(function () {
    if (! function_exists('igbinary_serialize')) {
        $this->markTestSkipped('igbinary is not installed');
    }

    PhpFileParser::clearSharedCache();
    PhpFileParser::$parseCount = 0;

    $this->dir = sys_get_temp_dir().'/brain-disk-cache-'.uniqid();
    $this->cacheDir = $this->dir.'/cache';
    mkdir($this->dir);
    PhpFileParser::useDiskCache($this->cacheDir);
});

afterEach(function () {
    PhpFileParser::useDiskCache(null);
    PhpFileParser::clearSharedCache();
    exec('rm -rf '.escapeshellarg($this->dir));
});

/** Write $code to $name with a settled mtime, and return its path. */
function diskCacheSource(string $dir, string $name, string $code, int $age = 10): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $code);
    touch($path, time() - $age);
    clearstatcache(true, $path);

    return $path;
}

/**
 * Simulate the next process: buffered entries written, every in-memory layer dropped, bundles
 * left on disk. Pointing useDiskCache() at the directory again is what clears the decoded
 * bundles — without that a "second process" would still be answering from this one's memory,
 * and these tests would prove nothing about what actually reached the filesystem.
 */
function nextProcess(string $cacheDir): void
{
    PhpFileParser::flushDiskCache();
    PhpFileParser::clearSharedCache();
    PhpFileParser::useDiskCache($cacheDir);
    PhpFileParser::$parseCount = 0;
}

/** Entries actually stored across all bundles. */
function storedEntries(string $dir): int
{
    $total = 0;
    foreach (glob($dir.'/*.shard') ?: [] as $shard) {
        $map = igbinary_unserialize((string) file_get_contents($shard));
        $total += is_array($map) ? count($map) : 0;
    }

    return $total;
}

it('serves a second process from disk without parsing', function () {
    $file = diskCacheSource($this->dir, 'Warm.php', "<?php\nuse App\\Thing;\nclass Warm {}\n");

    $first = (new PhpFileParser)->parse($file);
    expect(PhpFileParser::$parseCount)->toBe(1)
        // Entries are buffered and written together, so nothing is on disk until the flush.
        ->and(storedEntries($this->cacheDir))->toBe(0);

    nextProcess($this->cacheDir);

    expect(storedEntries($this->cacheDir))->toBe(1);

    $second = (new PhpFileParser)->parse($file);

    expect(PhpFileParser::$parseCount)->toBe(0)
        ->and($second['useMap'])->toBe($first['useMap'])
        ->and($second['ast'])->toEqual($first['ast']);
});

it('keeps the resolved names the parser attaches to the tree', function () {
    // parseCode() runs NameResolver, which annotates Name nodes with a resolvedName attribute.
    // Those attributes are the reason analyzers can stop guessing FQCNs, and they have to
    // survive the round trip through the cache.
    $file = diskCacheSource($this->dir, 'Resolved.php', <<<'PHP'
        <?php
        namespace App;

        use App\Models\User;

        class Resolved
        {
            public function handle()
            {
                return User::query();
            }
        }
        PHP);

    $fresh = (new PhpFileParser)->parse($file);
    nextProcess($this->cacheDir);
    $cached = (new PhpFileParser)->parse($file);

    $nameOf = static function (array $parsed): ?string {
        $found = null;
        (new NodeTraverser(new class($found) extends NodeVisitorAbstract
        {
            public function __construct(private ?string &$found) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof StaticCall) {
                    $this->found ??= PhpFileParser::resolvedName($node->class);
                }

                return null;
            }
        }))->traverse($parsed['ast']);

        return $found;
    };

    expect($nameOf($fresh))->toBe('App\Models\User')
        ->and($nameOf($cached))->toBe('App\Models\User')
        ->and(PhpFileParser::$parseCount)->toBe(0);
});

it('re-parses a file that changed since it was cached', function () {
    $file = diskCacheSource($this->dir, 'Edited.php', "<?php\nuse Old\\Alias;\nclass Edited {}\n");
    (new PhpFileParser)->parse($file);

    nextProcess($this->cacheDir);
    diskCacheSource($this->dir, 'Edited.php', "<?php\nuse New\\Thing;\nclass Edited {}\n", age: 5);

    $result = (new PhpFileParser)->parse($file);

    expect($result['useMap'])->toBe(['Thing' => 'New\Thing'])
        ->and(PhpFileParser::$parseCount)->toBe(1);
});

it('serves the current bytes of a file that is being edited right now', function () {
    // Two versions of the same length written inside one second share an mtime and a size, so
    // the in-memory key cannot tell them apart and that layer has to skip the file. The stored
    // entries are addressed by content, so they stay correct regardless: each version gets its
    // own, and neither can answer for the other.
    $path = $this->dir.'/Unsettled.php';
    $unsettled = time() + 2;

    file_put_contents($path, "<?php\nuse X\\Aaa;\nclass W {}\n");
    touch($path, $unsettled);
    clearstatcache(true, $path);
    $first = (new PhpFileParser)->parse($path);

    file_put_contents($path, "<?php\nuse X\\Bbb;\nclass W {}\n");
    touch($path, $unsettled);
    clearstatcache(true, $path);
    $second = (new PhpFileParser)->parse($path);

    PhpFileParser::flushDiskCache();

    expect($first['useMap'])->toBe(['Aaa' => 'X\Aaa'])
        ->and($second['useMap'])->toBe(['Bbb' => 'X\Bbb'])
        ->and(storedEntries($this->cacheDir))->toBe(2);
});

it('shares one entry between files whose contents are identical', function () {
    // The stored result depends on the bytes and nothing else, so two copies of the same source
    // are one entry rather than two — and the second copy is never parsed.
    $source = "<?php\nnamespace App;\n\nclass Duplicated {}\n";
    $a = diskCacheSource($this->dir, 'CopyA.php', $source);
    $b = diskCacheSource($this->dir, 'CopyB.php', $source);

    (new PhpFileParser)->parse($a);
    (new PhpFileParser)->parse($b);

    PhpFileParser::flushDiskCache();

    expect(storedEntries($this->cacheDir))->toBe(1)
        ->and(PhpFileParser::$parseCount)->toBe(1);
});

it('falls back to parsing when a stored entry is corrupt', function () {
    $file = diskCacheSource($this->dir, 'Corrupt.php', "<?php\nclass Corrupt {}\n");
    (new PhpFileParser)->parse($file);
    PhpFileParser::flushDiskCache();

    $bundle = glob($this->cacheDir.'/*.shard')[0];
    file_put_contents($bundle, 'not igbinary at all');

    nextProcess($this->cacheDir);
    $result = (new PhpFileParser)->parse($file);

    expect($result['ast'])->not->toBeNull()
        ->and(PhpFileParser::$parseCount)->toBe(1);
});

it('rejects a stored entry holding something other than a parse result', function () {
    // The directory is app-private, but a blob that unserializes into the wrong shape must be
    // treated as a miss rather than handed to the analyzers.
    $file = diskCacheSource($this->dir, 'Foreign.php', "<?php\nclass Foreign {}\n");
    (new PhpFileParser)->parse($file);
    PhpFileParser::flushDiskCache();

    $bundle = glob($this->cacheDir.'/*.shard')[0];
    $entries = igbinary_unserialize((string) file_get_contents($bundle));
    $entries[array_key_first($entries)] = igbinary_serialize(['ast' => [new stdClass], 'useMap' => []]);
    file_put_contents($bundle, igbinary_serialize($entries));

    nextProcess($this->cacheDir);
    $result = (new PhpFileParser)->parse($file);

    expect($result['ast'][0])->toBeInstanceOf(Class_::class)
        ->and(PhpFileParser::$parseCount)->toBe(1);
});

it('writes nothing once the cache is switched off', function () {
    PhpFileParser::useDiskCache(null);
    expect(PhpFileParser::diskCacheEnabled())->toBeFalse();

    $file = diskCacheSource($this->dir, 'Off.php', "<?php\nclass Off {}\n");
    (new PhpFileParser)->parse($file);
    PhpFileParser::flushDiskCache();

    expect(glob($this->cacheDir.'/*.shard') ?: [])->toBeEmpty();
});
