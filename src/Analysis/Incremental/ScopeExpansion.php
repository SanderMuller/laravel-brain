<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * Which controllers a scoped run has to trace beyond the ones the changed files declare.
 *
 * A scoped run traces controllers, and everything a controller reaches is traced with it. So a
 * changed file that declares no controller — a service, an action class, anything the chain
 * descends into — contributes nothing to the fresh graph, and the soundness check in
 * {@see ProjectAnalyzer} then compares the previous build's owned edges against nothing at all.
 * When the edit added an edge, both sides read empty, the check approves, and the merge carries
 * the previous graph's edges over: the added call is missing from a graph nothing reports as
 * stale.
 *
 * The previous build already knows which controllers reach that file — it recorded the chain. So
 * the scope is widened with them, the fresh build re-derives the file's own edges through those
 * chains, and the check gets something real to compare. An edit that moved a call is then refused
 * as it should be; one that did not keeps the fast path, and its nodes are substituted with their
 * freshly built selves rather than carried over stale.
 *
 * Only what the previous graph can see. A file it attributes nothing to — most of `app/` — widens
 * nothing, because there is no chain recorded to widen along.
 */
final class ScopeExpansion
{
    /** Node types that a traced controller file declares. */
    private const CONTROLLER_NODE_TYPES = ['controller', 'action'];

    /**
     * The files declaring controllers whose call chain reached anything the changed files own.
     *
     * @param  string[]  $changedFiles
     * @return array<string, true> owning file path => true, in the form the previous build recorded
     */
    public static function controllerFilesReaching(Graph $previous, array $changedFiles): array
    {
        $provenance = GraphProvenance::of($previous);

        $frontier = [];
        foreach ($provenance->nodeIdsForFiles(...$changedFiles) as $id) {
            $frontier[$id] = true;
        }

        if ($frontier === []) {
            return [];
        }

        $callers = [];
        foreach ($previous->edges() as $edge) {
            $callers[$edge->target][$edge->source] = true;
        }

        $nodesById = [];
        foreach ($previous->nodes() as $node) {
            $nodesById[$node->id] = $node;
        }

        // Walk the chain backwards from what the changed files own. Every controller on the way
        // up has to be traced again for those files to be rebuilt through it.
        $seen = $frontier;
        $files = [];
        while ($frontier !== []) {
            $next = [];
            foreach (array_keys($frontier) as $id) {
                foreach (array_keys($callers[$id] ?? []) as $caller) {
                    if (isset($seen[$caller])) {
                        continue;
                    }
                    $seen[$caller] = true;
                    $next[$caller] = true;

                    $node = $nodesById[$caller] ?? null;
                    if ($node === null || ! in_array($node->type, self::CONTROLLER_NODE_TYPES, true)) {
                        continue;
                    }

                    $file = $node->data['file'] ?? null;
                    if (is_string($file) && $file !== '') {
                        $files[$file] = true;
                    }
                }
            }
            $frontier = $next;
        }

        return $files;
    }
}
