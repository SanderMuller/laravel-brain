<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * Diff-scoped graph rebuild. Holds the previous build in memory (the watch-mode case: keep one
 * IncrementalAnalyzer across rescans) and, when a rescan changes only a few files, refreshes just
 * those files' contribution instead of rebuilding the whole graph.
 *
 * Conservative by construction — it only takes the fast path when it is provably output-identical
 * to a full rebuild, and falls back to a full build otherwise:
 *   - files added or deleted, or any non-app (routes/config) file changed  -> full
 *   - a changed file whose CALL GRAPH changed (its owned edge-id set differs from the scoped
 *     rebuild's) -> full (edge add/remove + cross-file reachability is the next slice)
 *   - otherwise (node bodies changed, call graph intact) -> scoped refresh + IncrementalMerge
 *
 * fullBuild and scopedBuild are injected so this stays decoupled from ProjectAnalyzer's container
 * wiring and testable with the raw analyzers; production supplies a ProjectAnalyzer-backed full
 * build and a scoped GraphBuilder for the changed files' contribution.
 */
final class IncrementalAnalyzer
{
    /**
     * Everything that can change the graph in a default Laravel skeleton.
     *
     * @var string[]
     */
    public const DEFAULT_ROOTS = ['app', 'routes', 'config'];

    /** @var \Closure(string): Graph */
    private \Closure $fullBuild;

    /** @var \Closure(string, string[]): Graph  (projectRoot, changedFiles) => partial graph */
    private \Closure $scopedBuild;

    /** @var string[] */
    private array $roots;

    private ?Graph $prevGraph = null;

    private ?BuildFingerprint $prevFp = null;

    /** @var string[] */
    private array $sourcePaths;

    /**
     * @param  \Closure(string): Graph  $fullBuild
     * @param  \Closure(string, string[]): Graph  $scopedBuild
     * @param  string[]  $roots  everything that can change the graph
     * @param  string[]  $sourcePaths  the subset a scoped rebuild may be limited to
     */
    public function __construct(
        \Closure $fullBuild,
        \Closure $scopedBuild,
        array $roots = self::DEFAULT_ROOTS,
        array $sourcePaths = SourceDirectories::DEFAULT_SOURCE_PATHS,
    ) {
        $this->fullBuild = $fullBuild;
        $this->scopedBuild = $scopedBuild;
        $this->roots = $roots;
        $this->sourcePaths = $sourcePaths;
    }

    /**
     * @return array{graph: Graph, mode: 'full'|'incremental'|'unchanged'}
     */
    public function analyze(string $projectRoot): array
    {
        $fp = BuildFingerprint::capture($projectRoot, $this->roots);

        if ($this->prevGraph === null) {
            return $this->full($projectRoot, $fp);
        }

        $diff = $fp->diff($this->prevFp);

        // Structural: added/deleted files, or a routes/config change, can shift the route table,
        // entry points or reachability wholesale — always a full rebuild.
        if ($diff['added'] !== [] || $diff['deleted'] !== [] || $this->touchesNonSource($projectRoot, $diff['modified'])) {
            return $this->full($projectRoot, $fp);
        }

        $modified = $diff['modified'];
        if ($modified === []) {
            return ['graph' => $this->prevGraph, 'mode' => 'unchanged'];
        }

        $partial = ($this->scopedBuild)($projectRoot, $modified);

        // Precondition for the fast path: the changed files' call graph is intact (the same owned
        // edges old vs scoped, compared by content). If not, the edit changed edges/reachability
        // -> full rebuild.
        if (IncrementalMerge::ownedEdgeKeySet($this->prevGraph, $modified)
            != IncrementalMerge::ownedEdgeKeySet($partial, $modified)) {
            return $this->full($projectRoot, $fp);
        }

        $merged = IncrementalMerge::applyPartial($this->prevGraph, $partial, $modified);
        $this->prevGraph = $merged;
        $this->prevFp = $fp;

        return ['graph' => $merged, 'mode' => 'incremental'];
    }

    /**
     * @return array{graph: Graph, mode: 'full'}
     */
    private function full(string $projectRoot, BuildFingerprint $fp): array
    {
        $graph = ($this->fullBuild)($projectRoot);
        $this->prevGraph = $graph;
        $this->prevFp = $fp;

        return ['graph' => $graph, 'mode' => 'full'];
    }

    /**
     * Whether any changed file sits outside the source paths — a routes or config change
     * that a scoped rebuild cannot account for.
     *
     * @param  string[]  $files
     */
    private function touchesNonSource(string $projectRoot, array $files): bool
    {
        $directories = SourceDirectories::resolve($projectRoot, $this->sourcePaths);

        foreach ($files as $f) {
            if (! SourceDirectories::contains($projectRoot, $directories, $f)) {
                return true;
            }
        }

        return false;
    }
}
