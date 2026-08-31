<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns the tab index of the last scan: every route/command/channel/Filament-resource tab with its node/edge counts and, where issues exist, its security risk level. Call this first to see what has been scanned before drilling into a specific tab or route.')]
#[IsReadOnly]
class GetManifestTool extends Tool
{
    public function handle(Request $request): Response
    {
        $store = GraphStoreFactory::make();

        if (! $store->hasManifest()) {
            return Response::error('No scan data found — call brain_rescan first.');
        }

        return Response::text((string) $store->getManifest());
    }
}
