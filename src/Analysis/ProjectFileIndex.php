<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Filename index for the by-short-name lookups Brain falls back to when PSR-4 resolution cannot
 * place a class, replacing a recursive directory walk per class asked about.
 *
 * Lookup semantics are unchanged: within a searched directory the first file in
 * RecursiveIteratorIterator order whose name matches wins — the iterator yields leaves only,
 * exactly as the replaced loops did — and callers keep their own directory precedence.
 *
 * Process-static, cleared at the start of every ProjectAnalyzer::analyze(), so a rescan or any
 * long-lived process sees files added since the previous build.
 */
class ProjectFileIndex
{
    /** @var array<string, array<string, string>> absolute dir => filename => first matching path */
    private static array $index = [];

    public static function clear(): void
    {
        self::$index = [];
    }

    /**
     * @param  list<string>  $dirs  directories relative to $projectRoot, in precedence order
     */
    public static function findFile(string $projectRoot, array $dirs, string $filename): ?string
    {
        foreach ($dirs as $dir) {
            $base = rtrim($projectRoot, '/').'/'.$dir;
            $map = self::$index[$base] ??= self::buildDirIndex($base);
            if (isset($map[$filename])) {
                return $map[$filename];
            }
        }

        return null;
    }

    /** @return array<string, string> filename => first matching path in walk order */
    private static function buildDirIndex(string $base): array
    {
        if (! is_dir($base)) {
            return [];
        }

        $map = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $map[$file->getFilename()] ??= $file->getPathname();
        }

        return $map;
    }
}
