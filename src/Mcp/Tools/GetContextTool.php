<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Ai\ContextExporter;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description("Exports focused AI context for a route or node from the last scan: its call chain, complexity hotspots, database operations, and source. Give either 'route' (a URI or route name) or 'nodeId' to focus on one part of the app, or omit both for a full-project summary ordered by complexity.")]
#[IsReadOnly]
class GetContextTool extends Tool
{
    private const MINIMUM_BUDGET = 500;

    public function handle(Request $request): Response
    {
        $store = GraphStoreFactory::make();

        if (! $store->hasManifest()) {
            return Response::error('No scan data found — call brain_rescan first.');
        }

        $format = $request->get('format', 'markdown');
        $format = in_array($format, ['markdown', 'json'], true) ? $format : 'markdown';

        $budget = max(self::MINIMUM_BUDGET, (int) $request->get('budget', 6000));

        $exporter = new ContextExporter($store, base_path());

        try {
            $output = $exporter->export(
                nodeId: $request->get('nodeId'),
                routeLabel: $request->get('route'),
                budget: $budget,
                format: $format,
            );
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text($output);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'route' => $schema->string()
                ->description('Route URI or route name to focus on (case-insensitive).'),
            'nodeId' => $schema->string()
                ->description('Exact graph node id to focus on, e.g. "action::OrderController::store". Takes precedence over "route".'),
            'budget' => $schema->integer()
                ->description('Token budget for the export.')
                ->default(6000),
            'format' => $schema->string()
                ->enum(['markdown', 'json'])
                ->description('Output format — markdown for reading, json to access fields like security data programmatically.')
                ->default('markdown'),
        ];
    }
}
