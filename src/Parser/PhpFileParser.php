<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Parser;

use Composer\InstalledVersions;
use PhpParser\Error;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class PhpFileParser
{
    private Parser $parser;

    /**
     * Parse results shared by every PhpFileParser instance in the process, so a file is read,
     * tokenized, parsed and name-resolved once per build instead of once per analyzer. Keyed by
     * path + mtime + size, so any edit invalidates its own entry and nothing else.
     *
     * Sharing the AST *objects* is safe because Brain only ever reads the tree: every visitor in
     * src/ returns null or a traversal-control int — none returns a replacement node — and the
     * NameResolver in {@see parseCode()} runs in additive mode (`replaceNodes => false`).
     *
     * @var array<string, array{ast: Node\Stmt[]|null, useMap: array<string, string>}>
     */
    private static array $sharedCache = [];

    /**
     * Entry cap, enforced as insertion-ordered eviction of the oldest quarter. A single build
     * cannot reach it. It bounds long-lived processes, where every edit mints a new key and the
     * superseded entry would otherwise linger forever.
     */
    private const SHARED_CACHE_MAX = 8000;

    /**
     * Source parses performed in this process, i.e. shared-cache misses.
     *
     * @internal Counter for tests and benchmarks; never read by production code.
     */
    public static int $parseCount = 0;

    /**
     * Directory backing the persistent cache, or null when it is off (the default).
     *
     * The in-memory cache above dies with the process, so a CLI scan or a CI job re-parses
     * everything every time even though almost no file changed between runs. Storing parse
     * results here lets the next process read back the files that did not change.
     */
    private static ?string $diskCacheDir = null;

    /**
     * Bump whenever the stored structure changes — a new attribute on the nodes, a different
     * result shape — so a cache written by an older Brain is skipped rather than misread. The
     * PHP and php-parser versions are part of the key separately, since either can change the
     * node classes underneath us.
     */
    private const AST_SCHEMA_VERSION = 1;

    /** Memoized key prefix; see cacheVersion(). */
    private static ?string $cacheVersion = null;

    /** Length of the key prefix that selects a bundle: 2 hex characters, so 256 of them. */
    private const SHARD_PREFIX_LENGTH = 2;

    /**
     * Entries kept per bundle. An edit adds an entry rather than replacing one, so bundles grow
     * until this trims them. 256 bundles x 80 entries is 20,000 results, several times the file
     * count of a large application, and at 15-25 KB each that caps the directory near 400 MB.
     */
    private const MAX_ENTRIES_PER_SHARD = 80;

    /** @var array<string, array<string, string>> shard => entry key => serialized result, unwritten */
    private static array $pendingEntries = [];

    /** @var array<string, array<string, string>|null> shard => its entries, null when there is no bundle */
    private static array $loadedShards = [];

    /** Whether the shutdown flush is already registered; see useDiskCache(). */
    private static bool $flushOnShutdownRegistered = false;

    /** Buffered entries awaiting a flush, and the point at which one is forced. */
    private static int $pendingCount = 0;

    private const MAX_PENDING_ENTRIES = 500;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /** Drop the shared parse cache, so a long-lived process can reclaim memory. */
    public static function clearSharedCache(): void
    {
        self::$sharedCache = [];
    }

    /**
     * Point the persistent cache at $dir, creating it if needed, or pass null to turn it off.
     *
     * Requires igbinary. The cache stores serialized AST objects, and igbinary is both much
     * faster than the alternative and outside the object-injection surface this project's arch
     * tests forbid. Without the extension the cache stays off and Brain simply parses.
     *
     * The directory holds objects Brain will unserialize, so it must be app-private —
     * storage/framework/cache is the default for that reason. Anyone who can write there can
     * already run code in a Laravel application, but it is worth being deliberate about.
     */
    public static function useDiskCache(?string $dir): void
    {
        if ($dir === null || ! function_exists('igbinary_serialize')) {
            self::$diskCacheDir = null;

            return;
        }

        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        self::$diskCacheDir = is_dir($dir) && is_writable($dir) ? rtrim($dir, '/') : null;

        // Bundles already read belong to whatever directory was in use before.
        self::$loadedShards = [];
        self::$pendingEntries = [];
        self::$pendingCount = 0;

        // A scan flushes when it finishes, but code driving the parser directly has no obvious
        // moment to do that, and silently parsing without ever storing anything would be a poor
        // way to fail. Registered once, and a no-op when there is nothing buffered.
        if (self::$diskCacheDir !== null && ! self::$flushOnShutdownRegistered) {
            self::$flushOnShutdownRegistered = true;
            register_shutdown_function(static fn () => self::flushDiskCache());
        }
    }

    /** Whether the persistent cache is currently on. */
    public static function diskCacheEnabled(): bool
    {
        return self::$diskCacheDir !== null;
    }

    /** Key prefix that invalidates every entry when the engine or the stored shape changes. */
    private static function cacheVersion(): string
    {
        if (self::$cacheVersion === null) {
            $phpParser = '';
            if (class_exists(InstalledVersions::class)) {
                try {
                    $phpParser = (string) InstalledVersions::getVersion('nikic/php-parser');
                } catch (\Throwable) {
                    $phpParser = '';
                }
            }
            self::$cacheVersion = 'ast'.self::AST_SCHEMA_VERSION
                .'|php'.PHP_VERSION_ID
                .'|pp'.$phpParser
                .'|ig'.phpversion('igbinary');
        }

        return self::$cacheVersion;
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    public function parse(string $filePath): array
    {
        $stat = @stat($filePath);

        // filemtime() has one-second resolution, so a file edited twice within the same second
        // can keep both its mtime and its size (any single-character edit does). Such a file is
        // "unsettled" and must stay out of the in-memory cache, whose key is built from exactly
        // those two values, until its mtime falls into the past.
        $settled = $stat !== false && $stat['mtime'] < time();

        $memoryKey = $settled ? $filePath.':'.$stat['mtime'].':'.$stat['size'] : null;
        if ($memoryKey !== null && isset(self::$sharedCache[$memoryKey])) {
            return self::$sharedCache[$memoryKey];
        }

        $result = self::$diskCacheDir !== null
            ? $this->parseViaDiskCache($filePath)
            : $this->parseFile($filePath);

        if ($memoryKey === null) {
            return $result;
        }

        if (count(self::$sharedCache) >= self::SHARED_CACHE_MAX) {
            // Evict a quarter in one pass rather than one entry per insert from here on.
            $evict = intdiv(self::SHARED_CACHE_MAX, 4);
            foreach (array_keys(self::$sharedCache) as $evictKey) {
                unset(self::$sharedCache[$evictKey]);
                if (--$evict <= 0) {
                    break;
                }
            }
        }

        return self::$sharedCache[$memoryKey] = $result;
    }

    /**
     * Read the file, and either unserialize a stored result for those exact bytes or parse them.
     *
     * Entries are addressed by a hash of the contents rather than by path and timestamp, which
     * costs a read the in-memory layer does not need — but a parse needs that read anyway, and
     * timestamps do not survive the thing this cache exists for. A CI job checks the repository
     * out fresh, so every file arrives with a new mtime and identical content: keyed by
     * timestamp the cache would miss every entry and then write a second copy of it, which is
     * slower than not caching at all. Keyed by content it hits.
     *
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    private function parseViaDiskCache(string $filePath): array
    {
        $code = @file_get_contents($filePath);
        if ($code === false) {
            return ['ast' => null, 'useMap' => []];
        }

        $key = hash('xxh128', $code);

        $stored = $this->readDisk($key);
        if ($stored !== null) {
            return $stored;
        }

        $result = $this->parseSource($code);

        // A file that failed to parse is not worth storing: it is cheap to re-read, and the
        // failure is usually being actively fixed.
        if ($result['ast'] !== null) {
            $this->writeDisk($key, $result);
        }

        return $result;
    }

    /**
     * Entries live in bundles, grouped by the first byte of their key, rather than one file
     * each. A file per entry means thousands of writes on a first scan and thousands of small
     * files to move around afterwards, and moving them is the expensive part: packing and
     * unpacking a directory costs far more per file than per byte, so a cache small enough to
     * be worth restoring is still slow to restore. Grouping cuts a first scan's write cost by
     * about two thirds and its pack-and-unpack cost by three quarters.
     */
    private static function shardPath(string $shard): string
    {
        return self::$diskCacheDir.'/'.$shard.'.shard';
    }

    /** Entry key within its shard: the content hash, tied to the engine and format versions. */
    private function entryKey(string $contentHash): string
    {
        return hash('xxh128', self::cacheVersion().'|'.$contentHash);
    }

    /**
     * Read back a previously stored parse result, or null to parse from source.
     *
     * Anything unexpected counts as a miss: a truncated write, a bundle from another version, a
     * file that is not ours. The shape is checked before the result is used, so a corrupt cache
     * costs a re-parse rather than a crash.
     *
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}|null
     */
    private function readDisk(string $contentHash): ?array
    {
        $entryKey = $this->entryKey($contentHash);
        $shard = substr($entryKey, 0, self::SHARD_PREFIX_LENGTH);

        $blob = self::$pendingEntries[$shard][$entryKey]
            ?? (self::loadShard($shard)[$entryKey] ?? null);

        if (! is_string($blob)) {
            return null;
        }

        $data = self::decode($blob);

        if (! is_array($data) || ! array_key_exists('ast', $data) || ! is_array($data['useMap'] ?? null)) {
            return null;
        }
        if (! is_array($data['ast']) || ($data['ast'] !== [] && ! reset($data['ast']) instanceof Node\Stmt)) {
            return null;
        }

        return $data;
    }

    /**
     * Buffer an entry for the next flush. Nothing touches the filesystem until then, so a scan
     * pays one write per shard it added to rather than one per file it parsed.
     *
     * @param  array{ast: Node\Stmt[]|null, useMap: array<string, string>}  $result
     */
    private function writeDisk(string $contentHash, array $result): void
    {
        $blob = igbinary_serialize($result);
        if ($blob === null) {
            return;
        }

        $entryKey = $this->entryKey($contentHash);
        self::$pendingEntries[substr($entryKey, 0, self::SHARD_PREFIX_LENGTH)][$entryKey] = $blob;
        self::$pendingCount++;

        // Holding every blob until the scan ends would add the whole cache to peak memory on a
        // large codebase. Flushing in batches keeps the write batched without that.
        if (self::$pendingCount >= self::MAX_PENDING_ENTRIES) {
            self::flushDiskCache();
        }
    }

    /**
     * Merge everything parsed during this scan into its bundles.
     *
     * ProjectAnalyzer calls this once a scan is finished. It is public because a consumer
     * driving the parser itself — the entry-point tracer does, outside analyze() — has to be
     * able to persist what it parsed.
     */
    public static function flushDiskCache(): void
    {
        $pending = self::$pendingEntries;
        self::$pendingEntries = [];
        self::$pendingCount = 0;

        if (self::$diskCacheDir === null || $pending === []) {
            return;
        }

        foreach ($pending as $shard => $entries) {
            // A shard of only digits would otherwise arrive here as an int.
            $shard = (string) $shard;
            $merged = $entries + (self::loadShard($shard) ?? []);

            // Newest first, so trimming to the cap drops what has gone longest untouched. An
            // edit adds an entry rather than replacing one, so bundles only ever grow.
            if (count($merged) > self::MAX_ENTRIES_PER_SHARD) {
                $merged = array_slice($merged, 0, self::MAX_ENTRIES_PER_SHARD, true);
            }

            $blob = igbinary_serialize($merged);
            if ($blob === null) {
                continue;
            }

            // Write then rename, so a reader never sees a half-written bundle.
            $path = self::shardPath($shard);
            $tmp = $path.'.'.getmypid().'.tmp';
            if (@file_put_contents($tmp, $blob) === false || ! @rename($tmp, $path)) {
                @unlink($tmp);

                continue;
            }

            self::$loadedShards[$shard] = $merged;
        }
    }

    /**
     * @return array<string, string>|null entry key => serialized result
     */
    private static function loadShard(string $shard): ?array
    {
        if (array_key_exists($shard, self::$loadedShards)) {
            return self::$loadedShards[$shard];
        }

        $path = self::shardPath($shard);
        $blob = is_file($path) ? @file_get_contents($path) : false;
        if ($blob === false) {
            return self::$loadedShards[$shard] = null;
        }

        $map = self::decode($blob);

        return self::$loadedShards[$shard] = is_array($map) ? $map : null;
    }

    /**
     * A damaged bundle makes igbinary emit a warning, and @ is not enough: an application that
     * promotes warnings to exceptions would turn a corrupt cache into a failed scan. Nothing
     * here is worth failing over, so swallow it locally and treat it as a miss.
     */
    private static function decode(string $blob): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return igbinary_unserialize($blob);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    private function parseFile(string $filePath): array
    {
        $code = @file_get_contents($filePath);
        if ($code === false) {
            return ['ast' => null, 'useMap' => []];
        }

        return $this->parseSource($code);
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    private function parseSource(string $code): array
    {
        self::$parseCount++;

        return $this->parseCode($code);
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    public function parseCode(string $code): array
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            return ['ast' => null, 'useMap' => []];
        }
        if ($ast === null) {
            return ['ast' => null, 'useMap' => []];
        }

        // One traversal: NameResolver annotates every Name with a resolvedName
        // attribute (additively — original nodes untouched), while the useMap
        // visitor collects the file's imports. Collect rather than throw, so a
        // semantically-invalid-but-parseable file (e.g. a duplicate use alias)
        // still scans instead of aborting.
        $useMapVisitor = new class extends NodeVisitorAbstract
        {
            /** @var array<string, string> */
            public array $useMap = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                        $this->useMap[$alias] = $use->name->toString();
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(new ErrorHandler\Collecting, [
            'preserveOriginalNames' => true,
            'replaceNodes' => false,
        ]));
        $traverser->addVisitor($useMapVisitor);
        $traverser->traverse($ast);

        return ['ast' => $ast, 'useMap' => $useMapVisitor->useMap];
    }

    /**
     * The fully-qualified name a `Name` node resolves to, following the file's
     * imports (including group-use), the current namespace, and leading-`\`
     * fully-qualified names.
     *
     * Returns null for a name the resolver leaves unresolved — the reserved
     * `self`/`static`/`parent` keywords (which the caller resolves against the
     * class context it already tracks), and any `Name` from an AST that was not
     * produced by {@see parse()} / {@see parseCode()} (so consumers can safely
     * fall back to their own resolution).
     */
    public static function resolvedName(?Node $name): ?string
    {
        if (! $name instanceof Node\Name) {
            return null;
        }
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            $fqcn = ltrim($resolved->toString(), '\\');

            // self/static/parent resolve to themselves — not a real FQCN, so
            // report them as unresolved and let the caller use its class context.
            return in_array(strtolower($fqcn), ['self', 'static', 'parent'], true) ? null : $fqcn;
        }

        return null;
    }
}
