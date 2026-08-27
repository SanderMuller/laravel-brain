<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\PhpStructureInspector;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function payloadKeysOf(string $resource): array
{
    return (new PhpStructureInspector)->payloadKeys(
        fixture('resource-payloads')."/app/Http/Resources/{$resource}.php"
    );
}

/** @param list<array{key: string, value: string}> $rows */
function keysOnly(array $rows): array
{
    return array_column($rows, 'key');
}

it('reads the keys a resource writes, in the order it writes them', function () {
    $keys = keysOnly(payloadKeysOf('ArticleResource'));

    // The editor branch first, since it is returned first, then the public one.
    expect($keys)->toBe([
        'id', 'title', 'reviewer_notes',
        'id', 'title', 'Article::FIELD_SLUG', 'author', 'tags', '...',
    ]);
});

it('keeps what fills each key', function () {
    $rows = payloadKeysOf('ArticleResource');
    $byKey = [];
    foreach ($rows as $row) {
        $byKey[$row['key']] ??= $row['value'];
    }

    expect($byKey['author'])->toBe('new AuthorResource($this->author)')
        ->and($byKey['tags'])->toBe("\$this->whenLoaded('tags')")
        ->and($byKey['id'])->toBe('$this->id');
});

it('prints a key that is not a literal as the expression it is', function () {
    // Dropping it would say the payload has one key fewer than it does.
    expect(keysOnly(payloadKeysOf('ArticleResource')))->toContain('Article::FIELD_SLUG');
});

it('marks a spread rather than pretending the rows above are the whole payload', function () {
    $rows = payloadKeysOf('ArticleResource');
    $spread = array_values(array_filter($rows, fn (array $row): bool => $row['key'] === '...'));

    expect($spread)->toHaveCount(1)
        ->and($spread[0]['value'])->toBe('$this->meta()');
});

it('says nothing about a payload the parent builds', function () {
    // `return parent::toArray($request)` enumerates nothing here, and an empty list is the honest
    // answer: it is not a payload with no keys, it is a payload this cannot read.
    expect(payloadKeysOf('AuthorResource'))->toBe([]);
});

it('says nothing about a payload built up over several statements', function () {
    expect(payloadKeysOf('TagResource'))->toBe([]);
});

it('says nothing about a file that is not there', function () {
    expect((new PhpStructureInspector)->payloadKeys('/no/such/Resource.php'))->toBe([]);
});

it('reads the same file once', function () {
    // GraphBuilder asks per resource node, and a resource composing a sibling asks again.
    $inspector = new PhpStructureInspector;
    $file = fixture('resource-payloads').'/app/Http/Resources/ArticleResource.php';

    expect($inspector->payloadKeys($file))->toBe($inspector->payloadKeys($file));
});

it('puts the payload on the resource node the graph builds', function () {
    // The wiring: a route reaches ArticleResource, and the node the builder makes for it carries
    // what that resource returns.
    $project = fixture('resource-payloads');
    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = new ControllerAnalyzer;
    $definitions = $controllers->analyze($project, $routes);
    $traces = (new MethodTracer)->trace($definitions, $controllers->getPsr4Map(), $project);

    // The project root is what gives the builder its PSR-4 map, and the file is what the payload
    // is read from — without it the node has no file and nothing to read.
    $graph = (new GraphBuilder)->build('test', $routes, new MiddlewareRegistry([], [], []), $definitions, $traces, [], $project);

    $resource = null;
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'resource') {
            $resource = $node;
            break;
        }
    }

    expect($resource)->not->toBeNull()
        ->and(array_column($resource->data['payloadKeys'] ?? [], 'key'))
        ->toBe(['id', 'title', 'reviewer_notes', 'id', 'title', 'Article::FIELD_SLUG', 'author', 'tags', '...']);
});
