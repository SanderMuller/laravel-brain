<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Ai\MergedGraph;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns the full merged dependency graph (every node and edge) from the last scan, optionally filtered by node type. Expensive on a large project — prefer brain_get_subgraph or brain_get_context when you already know which route or tab you need.')]
#[IsReadOnly]
class GetGraphTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $store = GraphStoreFactory::make();

        try {
            $graph = MergedGraph::load($store);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $types = $request->get('nodeTypes');
        if (is_array($types) && $types !== []) {
            $allowed = array_flip($types);
            $graph['nodes'] = array_values(array_filter(
                $graph['nodes'],
                fn (array $n): bool => isset($allowed[$n['type'] ?? ''])
            ));
            $keepIds = array_flip(array_column($graph['nodes'], 'id'));
            $graph['edges'] = array_values(array_filter(
                $graph['edges'],
                fn (array $e): bool => isset($keepIds[$e['source'] ?? '']) && isset($keepIds[$e['target'] ?? ''])
            ));
        }

        return Response::structured($graph);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nodeTypes' => $schema->array()
                ->items($schema->string())
                ->description('Only include nodes of these types, e.g. ["route", "controller"]. Omit for every type.'),
        ];
    }
}
