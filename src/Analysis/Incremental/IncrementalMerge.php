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
        //     elements whose owner is a changed file). Provenance indexes edges by id, so the ids
        //     are translated to content identity here to stay in one key space with (b).
        foreach ([[$oldProv, $old], [$newProv, $new]] as [$prov, $graph]) {
            $keysById = self::edgeKeysById($graph);
            foreach ($prov->nodeIdsForFiles(...$changedFiles) as $id) {
                $dirtyNodes[$id] = true;
            }
            foreach ($prov->edgeIdsForFiles(...$changedFiles) as $id) {
                if (isset($keysById[$id])) {
                    $dirtyEdges[$keysById[$id]] = true;
                }
            }
        }

        // (b) 1-hop closure over the edge delta. Edges are compared by content identity, so an
        //     edge present in exactly one build is an add or a remove; refresh the foreign nodes
        //     it connects (the caller edit may own the edge but not its target node).
        $oldEdges = self::edgeMap($old);
        $newEdges = self::edgeMap($new);
        foreach ([$oldEdges, $newEdges] as $edges) {
            foreach ($edges as $key => $edge) {
                if (isset($oldEdges[$key]) && isset($newEdges[$key])) {
                    continue; // unchanged edge
                }
                $dirtyEdges[$key] = true;
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

        $seen = [];
        foreach ($new->edges() as $e) {
            $key = self::edgeKey($e);
            $n = $seen[$key] ?? 0;
            $seen[$key] = $n + 1;
            $key .= '#'.$n;

            $result->addEdge(
                (! isset($dirty['edges'][$key]) && isset($oldEdges[$key])) ? $oldEdges[$key] : $e
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
        // One pass, in the previous build's order: an untouched node is carried over, and a
        // changed file's node is substituted in place by its freshly rebuilt self. Rebuilding
        // them in a second pass would move every one to the end, leaving a merged graph holding
        // the same nodes as a rebuild in a different sequence — which anything diffing or
        // caching the output would see as churn.
        foreach ($old->nodes() as $n) {
            if (! isset($dirtyNodes[$n->id])) {
                $result->addNode($n);
            } elseif (isset($partialNodes[$n->id])) {
                $result->addNode($partialNodes[$n->id]);
            }
        }
        // Edges are unchanged under the precondition, so carry them all from $old.
        foreach ($old->edges() as $e) {
            $result->addEdge($e);
        }

        return $result;
    }

    /**
     * The call graph the given files own, as content identities — used by the orchestrator to
     * verify a scoped rebuild matches the previous build (the applyPartial precondition).
     *
     * Occurrences are numbered within the owned set rather than across the whole graph, so the
     * comparison isn't disturbed by duplicate edges elsewhere: the previous build is a full graph
     * and the scoped one is not, and only the owned slice is being compared.
     *
     * @return array<string, true>
     */
    public static function ownedEdgeKeySet(Graph $graph, array $files): array
    {
        $owned = [];
        foreach (GraphProvenance::of($graph)->edgeIdsForFiles(...$files) as $id) {
            $owned[$id] = true;
        }

        $set = [];
        $seen = [];
        foreach ($graph->edges() as $e) {
            if (! isset($owned[$e->id])) {
                continue;
            }
            $key = self::edgeKey($e);
            $n = $seen[$key] ?? 0;
            $seen[$key] = $n + 1;
            $set[$key.'#'.$n] = true;
        }

        return $set;
    }

    /**
     * Edge id => content identity, numbered in the same iteration order as {@see self::edgeMap()}
     * so the two agree on which copy of a duplicated edge is which.
     *
     * @return array<string, string>
     */
    private static function edgeKeysById(Graph $graph): array
    {
        $out = [];
        $seen = [];
        foreach ($graph->edges() as $e) {
            $key = self::edgeKey($e);
            $n = $seen[$key] ?? 0;
            $seen[$key] = $n + 1;
            $out[$e->id] = $key.'#'.$n;
        }

        return $out;
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
        // Keyed by content identity, not by id: two builds of the same project must compare equal
        // whether or not the id scheme numbers edges by build order.
        $edges = [];
        foreach (self::edgeMap($graph) as $key => $e) {
            $edges[$key] = hash('xxh128', serialize([$e->source, $e->target, $e->label, $e->type]));
        }
        ksort($nodes);
        ksort($edges);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * An edge's identity for merge purposes: what the edge says, not what it is called.
     *
     * Deliberately derived from the edge's own fields rather than from `$e->id`. An id is only
     * usable as an identity if it is stable across builds, and that is a property of whichever
     * id scheme the graph happens to use — under a build-order counter, the same relationship
     * gets a different id whenever anything upstream of it changes, and every comparison here
     * would report the whole graph as changed. Computing identity from content keeps this layer
     * correct under any id scheme.
     *
     * The graph deliberately keeps genuinely identical edges, so callers append a per-occurrence
     * index to keep them distinct.
     */
    private static function edgeKey(Edge $e): string
    {
        return $e->source."\x1f".$e->target."\x1f".$e->type."\x1f".$e->label;
    }

    /**
     * Edges by content identity. Identical edges are numbered in iteration order, so a graph
     * holding N copies of one relationship yields N distinct keys in both builds.
     *
     * @return array<string, Edge>
     */
    private static function edgeMap(Graph $graph): array
    {
        $map = [];
        $seen = [];
        foreach ($graph->edges() as $e) {
            $key = self::edgeKey($e);
            $n = $seen[$key] ?? 0;
            $seen[$key] = $n + 1;
            $map[$key.'#'.$n] = $e;
        }

        return $map;
    }
}
