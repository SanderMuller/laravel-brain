<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;

/**
 * Discovers every Eloquent model in a project by walking the PSR-4 namespaces and
 * filtering for classes that (eventually) extend Illuminate's Model.
 *
 * Used by the ERD view so that models which aren't referenced from any controller
 * call chain still appear in the relationship graph.
 */
final class EloquentModelDiscoverer
{
    private PhpFileParser $parser;

    /** @var list<string> */
    private array $modelDirs;

    /**
     * @param  list<string>  $modelDirs  Project-relative directories to scan, e.g. ['app/Models', 'app'].
     */
    public function __construct(array $modelDirs = ['app/Models', 'app'])
    {
        $this->parser = new PhpFileParser;
        $this->modelDirs = $modelDirs;
    }

    /**
     * @return list<string> List of FQCNs of detected Eloquent model classes.
     */
    public function discover(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $found = [];

        foreach ($this->modelDirs as $dir) {
            $base = $projectRoot.'/'.$dir;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $fqcn = $this->detectModelFqcn($file->getPathname());
                if ($fqcn !== null) {
                    $found[$fqcn] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function detectModelFqcn(string $file): ?string
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return null;
        }

        $namespace = '';
        $useMap = $parsed['useMap'] ?? [];

        foreach ($parsed['ast'] as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $namespace = $node->name !== null ? $node->name->toString() : '';
                foreach ($node->stmts as $inner) {
                    $fqcn = $this->classMatches($inner, $namespace, $useMap);
                    if ($fqcn !== null) {
                        return $fqcn;
                    }
                }
            }

            $fqcn = $this->classMatches($node, $namespace, $useMap);
            if ($fqcn !== null) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function classMatches(Node $node, string $namespace, array $useMap): ?string
    {
        if (! $node instanceof Node\Stmt\Class_ || $node->isAbstract() || $node->name === null) {
            return null;
        }
        if ($node->extends === null) {
            return null;
        }

        $parentShort = $node->extends->toString();
        $parentFqcn = $useMap[$parentShort] ?? $parentShort;

        $eloquentBases = [
            'Illuminate\\Database\\Eloquent\\Model',
            'Illuminate\\Foundation\\Auth\\User',
            'Illuminate\\Database\\Eloquent\\Authenticatable',
        ];

        // Direct match — common case.
        foreach ($eloquentBases as $base) {
            if ($parentFqcn === $base || ltrim($parentFqcn, '\\') === $base) {
                return ($namespace !== '' ? $namespace.'\\' : '').$node->name->toString();
            }
        }

        // Heuristic: parent short name "Model" / "Authenticatable" — assume Eloquent.
        if (in_array($parentShort, ['Model', 'Authenticatable'], true)) {
            return ($namespace !== '' ? $namespace.'\\' : '').$node->name->toString();
        }

        return null;
    }
}
