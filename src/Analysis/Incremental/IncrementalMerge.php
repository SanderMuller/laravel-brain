<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * The correctness core of incremental analyze(): given the PREVIOUS full graph and the set of
 * changed files, decide the exact "dirty" element set that must be refreshed, and reconstruct
 * the new graph by reusing every unchanged element from the previous build.
 *
 * The dirty-set rule (empirically validated on a real corpus — see GraphProvenance):
 *
 *     dirty = (changed files' partitions, in BOTH the old and new provenance)
 *           ∪ (1-hop closure over the edge delta: for every edge whose presence changed between
 *              the two builds, also refresh the nodes it connects — this catches edges a caller
 *              edit adds whose TARGET node is owned by a different file, e.g. a new job dispatch)
 *
 * This class is the deterministic, side-effect-free heart that the full engine is gated on:
 * `reconstruct(old, new, changed)` MUST equal a full rebuild `new`. It takes `new` as the oracle
 * for the changed files' fresh contribution; the perf-delivering step (computing that fresh
 * contribution from a SCOPED re-analysis of only the changed files, instead of a full rebuild)
 * layers on top without changing this logic — which is exactly why it can be proven correct now.
 */
final class IncrementalMerge
{
    /**
     * The sound dirty set: node ids and edge ids that must be taken from the fresh build.
     *
     * @param  string[]  $changedFiles
     * @return array{nodes: array<string, true>, edges: array<string, true>}
     */
    public static function dirtyIds(Graph $old, Graph $new, array $changedFiles): array
    {
        $oldProv = GraphProvenance::of($old);
        $newProv = GraphProvenance::of($new);

        $dirtyNodes = [];
        $dirtyEdges = [];

        // (a) Everything the changed files own, in either build (covers modified + added + removed
        //     elements whose owner is a changed file).
        foreach ([$oldProv, $newProv] as $prov) {
            foreach ($prov->nodeIdsForFiles(...$changedFiles) as $id) {
                $dirtyNodes[$id] = true;
            }
            foreach ($prov->edgeIdsForFiles(...$changedFiles) as $id) {
                $dirtyEdges[$id] = true;
            }
        }

        // (b) 1-hop closure over the edge delta. Edge ids are content-addressed (ID1), so an edge
        //     whose id is present in exactly one build is an add or a remove; refresh the foreign
        //     nodes it connects (the caller edit may own the edge but not its target node).
        $oldEdges = self::edgeMap($old);
        $newEdges = self::edgeMap($new);
        foreach ([$oldEdges, $newEdges] as $edges) {
            foreach ($edges as $id => $edge) {
                $inOld = isset($oldEdges[$id]);
                $inNew = isset($newEdges[$id]);
                if ($inOld && $inNew) {
                    continue; // unchanged edge
                }
                $dirtyEdges[$id] = true;
                $dirtyNodes[$edge->source] = true;
                $dirtyNodes[$edge->target] = true;
            }
        }

        return ['nodes' => $dirtyNodes, 'edges' => $dirtyEdges];
    }

    /**
     * Rebuild the new graph by taking dirty elements from $new and reusing every other element
     * from $old. If the dirty set is complete, this equals $new exactly; a gap surfaces as a
     * difference from $new (that is the correctness gate). Meta is taken from $new.
     */
    public static function reconstruct(Graph $old, Graph $new, array $changedFiles): Graph
    {
        $dirty = self::dirtyIds($old, $new, $changedFiles);

        $oldNodes = [];
        foreach ($old->nodes() as $n) {
            $oldNodes[$n->id] = $n;
        }
        $oldEdges = self::edgeMap($old);

        $result = new Graph;

        foreach ($new->nodes() as $n) {
            // Reuse the previous instance for an unchanged, non-dirty node; otherwise take the fresh one.
            $result->addNode(
                (! isset($dirty['nodes'][$n->id]) && isset($oldNodes[$n->id])) ? $oldNodes[$n->id] : $n
            );
        }

        foreach ($new->edges() as $e) {
            $result->addEdge(
                (! isset($dirty['edges'][$e->id]) && isset($oldEdges[$e->id])) ? $oldEdges[$e->id] : $e
            );
        }

        return $result;
    }

    /**
     * Apply a SCOPED rebuild: fold the fresh contribution of the changed files ($partial — a graph
     * built from only those files plus their reachable downstream) into the previous full graph,
     * reusing every element the change didn't touch.
     *
     * Correctness precondition (the caller MUST enforce, falling back to a full rebuild otherwise):
     * the set of edges owned by the changed files is IDENTICAL in $old and $partial — i.e. the edit
     * changed node bodies but not the call graph. Under that precondition there is no edge delta and
     * no reachability change, so the dirty set is exactly the changed files' own nodes (their
     * flow/metrics data), and every other node and every edge is reused verbatim from $old. (Edge
     * additions/removals and their cross-file reachability effects are the next slice; until then
     * they take the full-rebuild path, so output is always correct.)
     */
    public static function applyPartial(Graph $old, Graph $partial, array $changedFiles): Graph
    {
        $dirtyNodes = [];
        foreach (GraphProvenance::of($old)->nodeIdsForFiles(...$changedFiles) as $id) {
            $dirtyNodes[$id] = true;
        }
        foreach (GraphProvenance::of($partial)->nodeIdsForFiles(...$changedFiles) as $id) {
            $dirtyNodes[$id] = true;
        }

        $partialNodes = [];
        foreach ($partial->nodes() as $n) {
            $partialNodes[$n->id] = $n;
        }

        $result = new Graph;
        // Reused, untouched nodes from the previous build...
        foreach ($old->nodes() as $n) {
            if (! isset($dirtyNodes[$n->id])) {
                $result->addNode($n);
            }
        }
        // ...and the changed files' own nodes, freshly rebuilt.
        foreach ($old->nodes() as $n) {
            if (isset($dirtyNodes[$n->id]) && isset($partialNodes[$n->id])) {
                $result->addNode($partialNodes[$n->id]);
            }
        }
        // Edges are unchanged under the precondition — carry them all from $old (stable ids).
        foreach ($old->edges() as $e) {
            $result->addEdge($e);
        }

        return $result;
    }

    /**
     * Edge ids owned by the given files within a graph — used by the orchestrator to verify the
     * scoped rebuild's call graph matches the previous build (the applyPartial precondition).
     *
     * @return array<string, true>
     */
    public static function ownedEdgeIdSet(Graph $graph, array $files): array
    {
        $set = [];
        foreach (GraphProvenance::of($graph)->edgeIdsForFiles(...$files) as $id) {
            $set[$id] = true;
        }

        return $set;
    }

    /**
     * Content signature of a graph's elements, independent of build metadata (analyzedAt etc.) and
     * insertion order — for asserting two graphs are behaviourally identical.
     *
     * @return array{nodes: array<string, string>, edges: array<string, string>}
     */
    public static function signature(Graph $graph): array
    {
        $nodes = [];
        foreach ($graph->nodes() as $n) {
            $nodes[$n->id] = hash('xxh128', serialize([$n->type, $n->label, $n->data]));
        }
        $edges = [];
        foreach ($graph->edges() as $e) {
            $edges[$e->id] = hash('xxh128', serialize([$e->source, $e->target, $e->label, $e->type]));
        }
        ksort($nodes);
        ksort($edges);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return array<string, Edge>
     */
    private static function edgeMap(Graph $graph): array
    {
        $map = [];
        foreach ($graph->edges() as $e) {
            $map[$e->id] = $e;
        }

        return $map;
    }
}
