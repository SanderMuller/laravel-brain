<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

/**
 * The cache is only useful if turning it on in config actually turns it on during a scan, and
 * only safe if leaving it alone leaves the filesystem alone.
 */
function bindBrainConfig(array $astCache): void
{
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'app' => ['name' => 'CacheWiringApp'],
        'laravel-brain' => ['ast_cache' => $astCache],
    ]));
}

function analyzeFixture(): void
{
    (new ProjectAnalyzer)->analyze(fixture('laravel-project'), static function (): void {});
}

beforeEach(function () {
    if (! function_exists('igbinary_serialize')) {
        $this->markTestSkipped('igbinary is not installed');
    }

    $this->cacheDir = sys_get_temp_dir().'/brain-ast-wiring-'.uniqid();
    PhpFileParser::useDiskCache(null);
    PhpFileParser::clearSharedCache();
    PhpFileParser::$parseCount = 0;
});

afterEach(function () {
    PhpFileParser::useDiskCache(null);
    PhpFileParser::clearSharedCache();
    Container::setInstance(null);
    exec('rm -rf '.escapeshellarg($this->cacheDir));
});

it('populates the cache on the first scan and reads it back on the next', function () {
    bindBrainConfig(['enabled' => true, 'path' => $this->cacheDir]);

    analyzeFixture();

    $written = glob($this->cacheDir.'/*.shard') ?: [];
    expect($written)->not->toBeEmpty()
        ->and(PhpFileParser::$parseCount)->toBeGreaterThan(0);

    // A second scan in a fresh process: every in-memory layer is gone, the directory is not.
    PhpFileParser::clearSharedCache();
    PhpFileParser::useDiskCache($this->cacheDir);
    PhpFileParser::$parseCount = 0;

    analyzeFixture();

    expect(PhpFileParser::$parseCount)->toBe(0)
        ->and(glob($this->cacheDir.'/*.shard') ?: [])->toHaveCount(count($written));
});

it('leaves the filesystem alone when the config does not ask for a cache', function () {
    bindBrainConfig([]);

    analyzeFixture();

    expect(PhpFileParser::diskCacheEnabled())->toBeFalse()
        ->and(is_dir($this->cacheDir))->toBeFalse();
});

it('accepts the env-style string values a config file produces', function () {
    // env('LARAVEL_BRAIN_AST_CACHE') hands through whatever is in .env, so "true" arrives as a
    // string rather than a boolean.
    bindBrainConfig(['enabled' => 'true', 'path' => $this->cacheDir]);

    analyzeFixture();

    expect(PhpFileParser::diskCacheEnabled())->toBeTrue()
        ->and(glob($this->cacheDir.'/*.shard') ?: [])->not->toBeEmpty();
});

it('produces the same graph with the cache on as with it off', function () {
    bindBrainConfig([]);
    $withoutCache = (new ProjectAnalyzer)->analyze(fixture('laravel-project'), static function (): void {});

    bindBrainConfig(['enabled' => true, 'path' => $this->cacheDir]);
    PhpFileParser::clearSharedCache();
    (new ProjectAnalyzer)->analyze(fixture('laravel-project'), static function (): void {});

    // Third scan: everything now comes back from disk rather than from source.
    PhpFileParser::clearSharedCache();
    PhpFileParser::useDiskCache($this->cacheDir);
    PhpFileParser::$parseCount = 0;
    $fromCache = (new ProjectAnalyzer)->analyze(fixture('laravel-project'), static function (): void {});

    expect(PhpFileParser::$parseCount)->toBe(0)
        ->and($fromCache->fullGraph->toJson())->toBe($withoutCache->fullGraph->toJson());
});
