<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Resources;

use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('graph://manifest')]
#[MimeType('application/json')]
#[Description('The tab index of the last scan — every route/command/channel/Filament-resource tab with its node/edge counts and security risk level. Same content as the brain_get_manifest tool.')]
class ManifestResource extends Resource
{
    public function handle(Request $request): Response
    {
        $store = GraphStoreFactory::make();

        if (! $store->hasManifest()) {
            return Response::error('No scan data found — call the brain_rescan tool first.');
        }

        return Response::text((string) $store->getManifest());
    }
}
