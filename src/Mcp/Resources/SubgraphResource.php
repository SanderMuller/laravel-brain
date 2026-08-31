<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Resources;

use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[MimeType('application/json')]
#[Description('One tab\'s subgraph (nodes + edges) from the last scan, by tab id. Tab ids come from graph://manifest or the brain_get_manifest tool.')]
class SubgraphResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('graph://subgraph/{tabId}');
    }

    public function handle(Request $request): Response
    {
        $tabId = (string) $request->get('tabId');

        $store = GraphStoreFactory::make();
        $json = $store->getSubgraph($tabId);

        if ($json === null) {
            return Response::error("No tab found with id \"{$tabId}\".");
        }

        return Response::text($json);
    }
}
