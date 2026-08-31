<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Ai\MergedGraph;
use LaraMint\LaravelBrain\Ai\UsageFinder;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description("Lists every route from the last scan with its security classification — exposure (public/guest/authed/admin), risk level, and any specific issues (mass assignment, missing throttle, unvalidated input, XSS). Filter by exposure/riskLevel/uriContains to answer 'which routes are public' or 'what's still unprotected'.")]
#[IsReadOnly]
class GetRouteSecurityTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $store = GraphStoreFactory::make();

        try {
            $graph = MergedGraph::load($store);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $routes = self::filterRoutes(
            $graph,
            $request->get('exposure'),
            $request->get('riskLevel'),
            $request->get('uriContains'),
        );

        return Response::structured(['routes' => $routes, 'count' => count($routes)]);
    }

    /**
     * Pure, I/O-free core split out from handle() so it's testable without a scan on disk —
     * the same reasoning as {@see UsageFinder::findInGraph()}.
     *
     * @param  array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{routeId: string, method: mixed, uri: string, name: mixed, exposure: string, riskLevel: string, issues: mixed}>
     */
    public static function filterRoutes(array $graph, ?string $exposure, ?string $riskLevel, ?string $uriContains): array
    {
        $routes = [];

        foreach ($graph['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'route') {
                continue;
            }

            $data = (array) ($node['data'] ?? []);
            $security = (array) ($data['security'] ?? []);
            $uri = (string) ($data['uri'] ?? '');

            if ($exposure !== null && ($security['exposure'] ?? null) !== $exposure) {
                continue;
            }
            if ($riskLevel !== null && ($security['riskLevel'] ?? null) !== $riskLevel) {
                continue;
            }
            if ($uriContains !== null && ! str_contains($uri, $uriContains)) {
                continue;
            }

            $routes[] = [
                'routeId' => (string) ($node['id'] ?? ''),
                'method' => $data['method'] ?? null,
                'uri' => $uri,
                'name' => $data['name'] ?? null,
                'exposure' => $security['exposure'] ?? 'unknown',
                'riskLevel' => $security['riskLevel'] ?? 'none',
                'issues' => $security['issues'] ?? [],
            ];
        }

        return $routes;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'exposure' => $schema->string()
                ->enum(['public', 'guest', 'authed', 'admin'])
                ->description('Only include routes with this exposure classification.'),
            'riskLevel' => $schema->string()
                ->enum(['none', 'low', 'medium', 'high', 'critical'])
                ->description('Only include routes at this risk level.'),
            'uriContains' => $schema->string()
                ->description('Only include routes whose URI contains this substring.'),
        ];
    }
}
