<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Ai;

use LaraMint\LaravelBrain\Storage\GraphStore;

/**
 * Finds where a node is used across the whole project — not just within the
 * single per-route subgraph the frontend happens to have loaded.
 *
 * Scope is intentionally one hop, incoming-only: who directly references this
 * node, grouped by their file.
 */
final class UsageFinder
{
    /**
     * @return array{nodeId: string, label: string, type: string, file: ?string, usageCount: int, fileCount: int, files: list<array{file: ?string, count: int, usages: list<array<string, string>>}>}|null
     *
     * @throws \RuntimeException when no scan data is present
     */
    public static function find(GraphStore $store, string $nodeId): ?array
    {
        return self::findInGraph(MergedGraph::load($store), $nodeId);
    }

    /**
     * Pure, I/O-free core split out from find() so a future feature can load
     * the merged graph once and query many nodes without re-reading storage
     * for each one.
     *
     * @param  array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{nodeId: string, label: string, type: string, file: ?string, usageCount: int, fileCount: int, files: list<array{file: ?string, count: int, usages: list<array<string, string>>}>}|null
     */
    public static function findInGraph(array $graph, string $nodeId): ?array
    {
        /** @var array<string, array<string, mixed>> $nodeIndex */
        $nodeIndex = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            $nodeIndex[(string) ($node['id'] ?? '')] = $node;
        }

        if (! isset($nodeIndex[$nodeId])) {
            return null;
        }

        // Group by source id first: the graph deliberately keeps duplicate
        // edges (e.g. a method calling the same dependency twice), so raw
        // edge count would overcount how many distinct callers there are.
        /** @var array<string, list<array<string, mixed>>> $edgesBySource */
        $edgesBySource = [];
        foreach ($graph['edges'] ?? [] as $edge) {
            if ((string) ($edge['target'] ?? '') !== $nodeId) {
                continue;
            }

            $source = (string) ($edge['source'] ?? '');
            if ($source === '' || $source === $nodeId) {
                continue;
            }

            $edgesBySource[$source][] = $edge;
        }

        /** @var array<string, array{file: ?string, count: int, usages: list<array<string, string>>}> $groups */
        $groups = [];

        foreach ($edgesBySource as $sourceId => $edges) {
            $sourceNode = $nodeIndex[$sourceId] ?? [];
            $file = self::nodeFile($sourceNode);

            $edgeLabels = [];
            $edgeTypes = [];
            foreach ($edges as $edge) {
                $edgeLabels[] = (string) ($edge['label'] ?? '');
                $edgeTypes[] = (string) ($edge['type'] ?? '');
            }

            $usage = [
                'nodeId' => $sourceId,
                'label' => (string) ($sourceNode['label'] ?? $sourceId),
                'type' => (string) ($sourceNode['type'] ?? 'unknown'),
                'edgeLabel' => self::joinUnique($edgeLabels),
                'edgeType' => self::joinUnique($edgeTypes),
            ];

            // Fileless sources each get their own group, keyed by node id, so
            // unrelated symbols never collapse into one misleading "file".
            $groupKey = $file ?? "#{$sourceId}";

            $groups[$groupKey] ??= ['file' => $file, 'count' => 0, 'usages' => []];
            $groups[$groupKey]['count']++;
            $groups[$groupKey]['usages'][] = $usage;
        }

        $files = array_values($groups);

        usort($files, function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }
            if ($a['file'] === $b['file']) {
                return 0;
            }
            if ($a['file'] === null) {
                return 1;
            }
            if ($b['file'] === null) {
                return -1;
            }

            return $a['file'] <=> $b['file'];
        });

        $targetNode = $nodeIndex[$nodeId];

        return [
            'nodeId' => $nodeId,
            'label' => (string) ($targetNode['label'] ?? $nodeId),
            'type' => (string) ($targetNode['type'] ?? 'unknown'),
            'file' => self::nodeFile($targetNode),
            'usageCount' => count($edgesBySource),
            'fileCount' => count(array_filter($files, fn (array $g): bool => $g['file'] !== null)),
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function nodeFile(array $node): ?string
    {
        $data = $node['data'] ?? null;
        $file = is_array($data) ? ($data['file'] ?? null) : null;

        return is_string($file) && $file !== '' ? $file : null;
    }

    /**
     * @param  list<string>  $values
     */
    private static function joinUnique(array $values): string
    {
        return implode(', ', array_values(array_unique(array_filter($values, fn (string $v): bool => $v !== ''))));
    }
}
