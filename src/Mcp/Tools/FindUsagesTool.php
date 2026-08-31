<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Ai\UsageFinder;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Finds every direct caller of a graph node from the last scan, grouped by the file each caller lives in. Use this to check what breaks if a method, model, or service changes. Node ids come from brain_get_manifest, brain_get_subgraph, or brain_get_graph.')]
#[IsReadOnly]
class FindUsagesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'nodeId' => 'required|string',
        ]);

        $store = GraphStoreFactory::make();

        try {
            $result = UsageFinder::find($store, $validated['nodeId']);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        if ($result === null) {
            return Response::error("No node found with id \"{$validated['nodeId']}\". Call brain_get_manifest or brain_get_graph to find valid node ids.");
        }

        return Response::structured($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nodeId' => $schema->string()
                ->description('The exact graph node id to find usages of, e.g. "service::OrderService::place".')
                ->required(),
        ];
    }
}
