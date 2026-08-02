<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Parser;

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
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    public function parse(string $filePath): array
    {
        $stat = @stat($filePath);
        if ($stat === false) {
            // Missing or unreadable — let the real read produce the null result, uncached.
            return $this->parseFile($filePath);
        }

        // filemtime() has one-second resolution, so a file edited twice within the same second
        // can keep both its mtime and its size (any single-character edit does). Such a file is
        // "unsettled": caching it would let the first version's AST answer for the second in a
        // watch-mode rescan or any long-lived process. Serve those files from source and keep
        // them out of the cache entirely until their mtime falls into the past — the cost is
        // re-parsing only the handful of files being edited at this very moment.
        if ($stat['mtime'] >= time()) {
            return $this->parseFile($filePath);
        }

        $key = $filePath.':'.$stat['mtime'].':'.$stat['size'];
        if (isset(self::$sharedCache[$key])) {
            return self::$sharedCache[$key];
        }

        if (count(self::$sharedCache) >= self::SHARED_CACHE_MAX) {
            // Evict a quarter in one pass rather than one entry per insert from here on.
            $evict = (int) (self::SHARED_CACHE_MAX / 4);
            foreach (array_keys(self::$sharedCache) as $evictKey) {
                unset(self::$sharedCache[$evictKey]);
                if (--$evict <= 0) {
                    break;
                }
            }
        }

        return self::$sharedCache[$key] = $this->parseFile($filePath);
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    private function parseFile(string $filePath): array
    {
        self::$parseCount++;

        $code = file_get_contents($filePath);
        if ($code === false) {
            return ['ast' => null, 'useMap' => []];
        }

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
