<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Expands configured source paths into the directories that actually exist.
 *
 * An entry is taken verbatim when it names a directory and treated as a glob
 * pattern otherwise, which is what lets an application whose code lives in
 * packages rather than in `app/` point an analyzer at its own layout —
 * `app-modules/*` + `/src`, `packages/*` + `/src/Providers`, and so on.
 *
 * Paths stay relative to the project root on the way out: that is the form
 * {@see ProjectFileIndex} takes, and prefixing the root is a concatenation.
 */
final class SourceDirectories
{
    /**
     * Where application classes live in a default Laravel skeleton. `src/` is here too
     * because a package checked out as the project itself keeps its classes there.
     *
     * @var string[]
     */
    public const DEFAULT_SOURCE_PATHS = ['app', 'src'];

    /**
     * @param  string[]  $patterns  paths or glob patterns, relative to the project root
     * @return string[] existing directories, relative to the project root
     */
    public static function resolve(string $projectRoot, array $patterns): array
    {
        $root = rtrim($projectRoot, '/');
        $directories = [];

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            $absolute = $root.'/'.ltrim($pattern, '/');

            if (is_dir($absolute)) {
                $directories[] = ltrim($pattern, '/');

                continue;
            }

            foreach (glob($absolute, GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $match) {
                $directories[] = ltrim(substr($match, strlen($root)), '/');
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * Prefixes for the relative-path lookup that precedes the by-file-name search: a
     * class whose namespace mirrors a directory layout is found by joining the two.
     *
     * `app/Http/Controllers/` is kept while `app` is a source path, since that is where
     * Laravel's own controllers sit and the prefix predates this being configurable.
     *
     * @param  string[]  $sourcePaths
     * @return string[] each with a trailing slash
     */
    public static function classFilePrefixes(string $projectRoot, array $sourcePaths): array
    {
        $prefixes = [];

        foreach (self::resolve($projectRoot, $sourcePaths) as $directory) {
            if ($directory === 'app') {
                $prefixes[] = 'app/Http/Controllers/';
            }
            $prefixes[] = trim($directory, '/').'/';
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * Whether an absolute path sits inside one of the given directories.
     *
     * Anchored at the project root rather than a substring test: `str_contains($p, '/app/')`
     * calls every file "in app/" when the project itself lives under a directory of that
     * name, and would let a change outside the source tree take a scoped rebuild.
     *
     * @param  string[]  $directories  relative to the project root
     */
    public static function contains(string $projectRoot, array $directories, string $path): bool
    {
        $root = rtrim($projectRoot, '/');

        foreach ($directories as $directory) {
            if (str_starts_with($path, $root.'/'.trim($directory, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every PHP file below the given directories, each file yielded once even when
     * the directories overlap.
     *
     * @param  string[]  $directories  relative to the project root
     * @return iterable<string> absolute file paths
     */
    public static function phpFiles(string $projectRoot, array $directories): iterable
    {
        $root = rtrim($projectRoot, '/');
        $seen = [];

        foreach ($directories as $directory) {
            $absolute = $root.'/'.ltrim($directory, '/');
            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                $path = $entry->getRealPath() ?: $entry->getPathname();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;

                yield $path;
            }
        }
    }
}
