<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MemberInvocationFacts;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * A throwaway project — one controller action injecting one service, which has an invoked
 * method and a never-called sibling — built fresh so this suite cannot shift the counts other
 * suites assert on against the shared fixture projects (mirrors MethodTracerResolutionTest's
 * sameNsProject() helper, extended with a routes/ file since this needs a full route-to-service
 * graph rather than a single traced method).
 */
function serviceMemberGraph(): Graph
{
    $root = sys_get_temp_dir().'/brain-svcmembers-'.uniqid();

    $files = [
        'routes/web.php' => '<?php
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get("/reports", [ReportController::class, "index"]);',
        'app/Http/Controllers/ReportController.php' => '<?php
namespace App\Http\Controllers;

use App\Services\ReportBuilder;

class ReportController
{
    public function index(ReportBuilder $builder)
    {
        return $builder->build();
    }
}',
        'app/Services/ReportBuilder.php' => '<?php
namespace App\Services;

class ReportBuilder
{
    public function build(): array
    {
        return [];
    }

    public function purge(): void
    {
        //
    }

    private function format(): string
    {
        return "";
    }
}',
    ];

    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $contents);
    }

    $routes = (new RouteAnalyzer)->analyze($root);
    $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
    $traces = (new MethodTracer)->trace($controllers);

    $graph = (new GraphBuilder)->build(
        'service-member-project',
        $routes,
        new MiddlewareRegistry([], [], []),
        $controllers,
        $traces,
        [],
        $root,
    );

    MemberInvocationFacts::stamp($graph);

    exec('rm -rf '.escapeshellarg($root));

    return $graph;
}

/** @return array<string, array<string, mixed>> App\Services\ReportBuilder's members, keyed by name */
function reportBuilderMembers(): array
{
    foreach (serviceMemberGraph()->nodes() as $node) {
        if ($node->type === 'service' && ($node->data['fqcn'] ?? null) === 'App\Services\ReportBuilder') {
            $byName = [];
            foreach ($node->data['members'] as $member) {
                $byName[$member['name']] = $member;
            }

            return $byName;
        }
    }

    throw new RuntimeException('ReportBuilder service node not found in graph');
}

it("attaches the service class's own member list to its node, private methods included", function () {
    // The facade code path this reuses defaults to public+protected only; the service path
    // must ask for private too, or the "grouped by visibility" panel would have an empty group.
    $members = reportBuilderMembers();

    expect($members)->toHaveKey('build')->toHaveKey('purge')->toHaveKey('format');
    expect($members['format']['visibility'])->toBe('private');
});

it("carries each member's visibility, params, and return type", function () {
    $members = reportBuilderMembers();

    expect($members['build'])->toMatchArray([
        'visibility' => 'public',
        'static' => false,
        'params' => [],
        'returnType' => 'array',
    ]);
});

it('marks the traced method invoked and its uncalled sibling not invoked', function () {
    $members = reportBuilderMembers();

    expect($members['build']['invoked'])->toBeTrue()
        ->and($members['purge']['invoked'])->toBeFalse();
});
