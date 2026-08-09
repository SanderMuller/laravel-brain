<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

/**
 * Per-file content fingerprints for a build, used to decide which files changed between
 * two analyze() runs. Keyed by absolute path → a content hash (not mtime: mtime is
 * integer-second and collides on fast edit→rebuild→edit cycles — the same class of race
 * the parse cache guards against). Content-hashing is O(bytes read) but reads are cheap
 * relative to parsing/analysis, and this runs once per build.
 */
final class BuildFingerprint
{
    /**
     * @param  array<string, string>  $files  absolute path => content hash
     */
    public function __construct(
        public readonly array $files,
    ) {}

    /**
     * Fingerprint every PHP file under the given roots (relative to $projectRoot).
     *
     * @param  string[]  $relativeRoots  e.g. ['app', 'routes', 'config']
     */
    public static function capture(string $projectRoot, array $relativeRoots = ['app', 'routes', 'config']): self
    {
        $projectRoot = rtrim($projectRoot, '/');
        $files = [];

        foreach ($relativeRoots as $rel) {
            $base = $projectRoot.'/'.$rel;
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $contents = @file_get_contents($path);
                if ($contents === false) {
                    continue;
                }
                $files[$path] = hash('xxh128', $contents);
            }
        }

        ksort($files);

        return new self($files);
    }

    /**
     * Files that were added, modified, or deleted relative to a previous fingerprint.
     *
     * @return array{added: string[], modified: string[], deleted: string[]}
     */
    public function diff(self $previous): array
    {
        $added = $modified = $deleted = [];

        foreach ($this->files as $path => $hash) {
            if (! array_key_exists($path, $previous->files)) {
                $added[] = $path;
            } elseif ($previous->files[$path] !== $hash) {
                $modified[] = $path;
            }
        }
        foreach (array_keys($previous->files) as $path) {
            if (! array_key_exists($path, $this->files)) {
                $deleted[] = $path;
            }
        }

        return ['added' => $added, 'modified' => $modified, 'deleted' => $deleted];
    }

    public function equals(self $other): bool
    {
        return $this->files === $other->files;
    }
}
