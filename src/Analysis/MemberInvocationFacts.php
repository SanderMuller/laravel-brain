<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Graph\Graph;

/**
 * Marks each service node's own class members as invoked or not, by checking whether any edge
 * in the finished graph already targets that method's node id.
 *
 * Runs as a stamping pass over the whole graph, after every edge-adding pass, for the same
 * reason a job-group region is stamped last (see GraphBuilder::stampJobGroupRegions()): "is this
 * method called from anywhere" can only be answered once every edge exists.
 *
 * Scoped to `service`-type nodes only. `action_class`/`validation_request` nodes share the same
 * builder and so also carry `members`, but their ids resolve through nodeIdForHop()'s controller
 * branch rather than the plain "fqcn::method" branch this stamp reproduces — stamping them here
 * would mis-mark every one of their members as uncalled.
 */
class MemberInvocationFacts
{
    /**
     * @return int nodes stamped
     */
    public static function stamp(Graph $graph): int
    {
        $targets = [];
        foreach ($graph->edges() as $edge) {
            $targets[$edge->target] = true;
        }

        $stamped = 0;

        foreach ($graph->nodes() as $node) {
            if ($node->type !== 'service') {
                continue;
            }

            $fqcn = $node->data['fqcn'] ?? null;
            $members = $node->data['members'] ?? null;

            if (! is_string($fqcn) || $fqcn === '' || ! is_array($members) || $members === []) {
                continue;
            }

            $prefix = strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn)).'::';

            $updated = array_map(
                static function (array $member) use ($targets, $prefix): array {
                    $member['invoked'] = isset($targets[$prefix.($member['name'] ?? '')]);

                    return $member;
                },
                $members,
            );

            // Merged, not passed alone: updateNodeData replaces the node's whole data array, so
            // handing it just 'members' would drop the file path, flow steps, and everything
            // else the builder put there.
            $graph->updateNodeData($node->id, [...$node->data, 'members' => $updated]);
            $stamped++;
        }

        return $stamped;
    }
}
