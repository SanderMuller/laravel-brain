<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description("Returns one tab's subgraph (nodes + edges) from the last scan by its tab id — cheaper than brain_get_graph when you already know which route/command/channel tab you need. Tab ids come from brain_get_manifest.")]
#[IsReadOnly]
class GetSubgraphTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tabId' => 'required|string',
        ]);

        $store = GraphStoreFactory::make();
        $json = $store->getSubgraph($validated['tabId']);

        if ($json === null) {
            return Response::error("No tab found with id \"{$validated['tabId']}\". Call brain_get_manifest for valid tab ids.");
        }

        return Response::text($json);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tabId' => $schema->string()
                ->description("The tab id to fetch, from brain_get_manifest's \"tabs\" list.")
                ->required(),
        ];
    }
}
