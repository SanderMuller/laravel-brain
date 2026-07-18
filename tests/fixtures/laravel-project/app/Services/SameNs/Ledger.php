<?php

namespace App\Services\SameNs;

class Ledger
{
    public function record(): void
    {
        // Same-namespace sibling, no import: the local useMap has no entry, and
        // the bare name "Reconciler" would trip the single-word model heuristic.
        // Only the parser's resolved FQCN links this as a service.
        (new Reconciler)->run();
    }

    public function recordViaVar(): void
    {
        // The common assign-then-call shape: the resolved type must be recorded
        // for $recon so the later method call is traced.
        $recon = new Reconciler;
        $recon->run();
    }
}
