<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Ai\RulesExporter;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Generates the agent-rules document (tech stack, architecture, routes, complexity hotspots, packages) for a given AI tool from the last scan, e.g. what brain:generate-rules writes to CLAUDE.md. Returns the content directly — it does not write any file.')]
#[IsReadOnly]
class GetAgentRulesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'target' => 'required|string|in:'.implode(',', array_keys(RulesExporter::TARGETS)),
        ]);

        $store = GraphStoreFactory::make();

        if (! $store->hasManifest()) {
            return Response::error('No scan data found — call brain_rescan first.');
        }

        $exporter = new RulesExporter($store, base_path());

        return Response::text($exporter->generate($validated['target']));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->string()
                ->enum(array_keys(RulesExporter::TARGETS))
                ->description('Which AI tool to generate rules for.')
                ->required(),
        ];
    }
}
