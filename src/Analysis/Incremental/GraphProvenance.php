<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

use LaraMint\LaravelBrain\Graph\Graph;

/**
 * Partitions a built graph by the source file each element derives from — the basis for a
 * diff-scoped rebuild: when a file changes, only the elements it owns need recomputing.
 *
 * Ownership rules (why they hold):
 *  - A NODE is owned by its `data.file` (every node carries it; blade/virtual nodes without a
 *    real file land in the '' bucket and are treated as always-rebuilt).
 *  - An EDGE is owned by the file of its SOURCE node — the call originates in that file's code,
 *    so editing the caller is what adds/removes the edge.
 *
 * IMPORTANT — this partition is NECESSARY BUT NOT SUFFICIENT as a merge dirty set (proven on a
 * real corpus; a fixtures-only check missed it). Editing a controller to dispatch a job adds an
 * edge whose SOURCE is the controller (correctly in the controller's partition) but whose TARGET
 * node (job::handle) is owned by the JOB's file — so a partition-only dirty set silently
 * under-updates the dispatch target. The sound merge dirty set is therefore:
 *
 *     dirty = (edited files' partitions)  ∪  (1-hop closure over the edit's EDGE DELTA:
 *             for every edge added / removed / changed while re-analysing the edited files,
 *             also refresh the FOREIGN node that edge targets)
 *
 * The blast-radius census measured that closure at ≤1 node/edit, so it stays cheap. The merge
 * step owns that closure (and dangling-edge pruning when a target file is deleted); this class
 * only provides the partition it starts from. Content-addressed ids (ID1 for edges, nodeIdForHop
 * for nodes) make every id stable across rebuilds, so a partition captured from one build is
 * directly comparable to the next.
 */
final class GraphProvenance
{
    /**
     * @param  array<string, array{nodes: string[], edges: string[]}>  $byFile
     * @param  array<string, string>  $nodeFile  nodeId => owning file
     * @param  array<string, string>  $edgeFile  edgeId => owning file
     */
    private function __construct(
        public readonly array $byFile,
        public readonly array $nodeFile,
        public readonly array $edgeFile,
    ) {}

    public static function of(Graph $graph): self
    {
        $byFile = [];
        $nodeFile = [];
        $edgeFile = [];

        foreach ($graph->nodes() as $node) {
            $file = is_string($node->data['file'] ?? null) ? $node->data['file'] : '';
            $nodeFile[$node->id] = $file;
            $byFile[$file]['nodes'][] = $node->id;
            $byFile[$file]['edges'] ??= [];
        }

        foreach ($graph->edges() as $edge) {
            // Owned by the source node's file (the caller). Falls to '' when the source is a
            // virtual/blade node without a file.
            $file = $nodeFile[$edge->source] ?? '';
            $edgeFile[$edge->id] = $file;
            $byFile[$file]['edges'][] = $edge->id;
            $byFile[$file]['nodes'] ??= [];
        }

        return new self($byFile, $nodeFile, $edgeFile);
    }

    /** Node ids owned by the given files (elements to invalidate when those files change). */
    public function nodeIdsForFiles(string ...$files): array
    {
        $ids = [];
        foreach ($files as $f) {
            foreach ($this->byFile[$f]['nodes'] ?? [] as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Edge ids owned by the given files. */
    public function edgeIdsForFiles(string ...$files): array
    {
        $ids = [];
        foreach ($files as $f) {
            foreach ($this->byFile[$f]['edges'] ?? [] as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
