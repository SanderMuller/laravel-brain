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
