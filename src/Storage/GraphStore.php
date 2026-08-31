<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Storage;

/**
 * Persistence backend for scan output.
 *
 * A scan produces one manifest (the tab index) plus one subgraph JSON blob
 * per tab. Implementations decide where those blobs live — the filesystem
 * (default) or a database table.
 */
interface GraphStore
{
    /**
     * Prepare the backend so a scan can write to it (create the directory
     * or the database table when missing). A no-op when already set up.
     */
    public function ensureSchema(): void;

    public function hasManifest(): bool;

    public function getManifest(): ?string;

    public function putManifest(string $json): void;

    public function getSubgraph(string $tabId): ?string;

    public function putSubgraph(string $tabId, string $json): void;

    /**
     * Tab ids of every stored subgraph (manifest excluded).
     *
     * @return list<string>
     */
    public function subgraphIds(): array;

    /**
     * Drop every stored subgraph whose tab id is not in $keep.
     *
     * A scan writes the complete set of tabs, so anything already stored and not
     * written by it belongs to a route, command or channel that no longer exists.
     * Left behind, those blobs keep answering {@see subgraphIds()} and the viewer
     * goes on listing tabs for a surface the application dropped.
     *
     * @param  list<string>  $keep  tab ids the current scan wrote
     */
    public function pruneSubgraphsExcept(array $keep): void;
}
