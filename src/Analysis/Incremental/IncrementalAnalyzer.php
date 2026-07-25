<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

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
    /** @var \Closure(string): Graph */
    private \Closure $fullBuild;

    /** @var \Closure(string, string[]): Graph  (projectRoot, changedFiles) => partial graph */
    private \Closure $scopedBuild;

    /** @var string[] */
    private array $roots;

    private ?Graph $prevGraph = null;

    private ?BuildFingerprint $prevFp = null;

    /**
     * @param  \Closure(string): Graph  $fullBuild
     * @param  \Closure(string, string[]): Graph  $scopedBuild
     * @param  string[]  $roots
     */
    public function __construct(\Closure $fullBuild, \Closure $scopedBuild, array $roots = ['app', 'routes', 'config'])
    {
        $this->fullBuild = $fullBuild;
        $this->scopedBuild = $scopedBuild;
        $this->roots = $roots;
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
        if ($diff['added'] !== [] || $diff['deleted'] !== [] || $this->touchesNonApp($diff['modified'])) {
            return $this->full($projectRoot, $fp);
        }

        $modified = $diff['modified'];
        if ($modified === []) {
            return ['graph' => $this->prevGraph, 'mode' => 'unchanged'];
        }

        $partial = ($this->scopedBuild)($projectRoot, $modified);

        // Precondition for the fast path: the changed files' call graph is intact (same owned
        // edge ids old vs scoped). If not, the edit changed edges/reachability -> full rebuild.
        if (IncrementalMerge::ownedEdgeIdSet($this->prevGraph, $modified)
            != IncrementalMerge::ownedEdgeIdSet($partial, $modified)) {
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
     * @param  string[]  $files
     */
    private function touchesNonApp(array $files): bool
    {
        foreach ($files as $f) {
            if (! str_contains($f, '/app/')) {
                return true;
            }
        }

        return false;
    }
}
