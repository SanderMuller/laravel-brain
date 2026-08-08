<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Incremental;

/**
 * Thrown when a scoped rebuild cannot stand in for a full one after all.
 *
 * A scoped run reuses every edge from the previous graph, which only holds while the changed
 * files' own call graph is intact — an edit that adds or removes a call has to be rebuilt in
 * full. That is not knowable until the scoped build has run and can be compared, so it surfaces
 * as an exception rather than a return value: a partial graph must never be mistaken for a
 * whole one by a caller that forgot to check a flag.
 */
final class ScopedRebuildNotApplicable extends \RuntimeException
{
    public function __construct(string $reason = 'the changed files\' call graph moved')
    {
        parent::__construct('Scoped rebuild not applicable: '.$reason.'. Run a full analysis.');
    }
}
