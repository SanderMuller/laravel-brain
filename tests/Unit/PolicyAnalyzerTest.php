<?php

use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\PolicyAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function policyMap(): array
{
    $project = fixture('laravel-project');
    $models = (new ModelAnalyzer(['app/Models']))->discoverModels($project);

    return (new PolicyAnalyzer)->analyze($project, $models);
}

it('resolves a policy by the App\\Models → App\\Policies convention', function () {
    expect(policyMap()['App\\Models\\User'] ?? null)->toBe('App\\Policies\\UserPolicy');
});

it('resolves a policy from a #[UsePolicy] attribute', function () {
    expect(policyMap()['App\\Models\\Article'] ?? null)->toBe('App\\Policies\\Custom\\ArticleAccessPolicy');
});

it('resolves a policy from an AuthServiceProvider::$policies map', function () {
    expect(policyMap()['App\\Models\\Comment'] ?? null)->toBe('App\\Policies\\CommentPolicy');
});

it('lets an explicit Gate::policy() registration override the convention', function () {
    // App\Policies\OrderPolicy exists (convention), but Gate::policy names SpecialOrderPolicy.
    expect(policyMap()['App\\Models\\Order'] ?? null)->toBe('App\\Policies\\SpecialOrderPolicy');
});

it('does not invent a policy for a model whose conventional policy is absent', function () {
    // No App\Policies\TagPolicy file exists, so Tag must not appear.
    expect(policyMap())->not->toHaveKey('App\\Models\\Tag');
});

it('maps each model to at most one policy', function () {
    foreach (policyMap() as $policy) {
        expect($policy)->toBeString();
    }
});

it('lets a #[UsePolicy] attribute override an existing conventional policy', function () {
    // App\Policies\ArticlePolicy exists (convention), but the attribute names ArticleAccessPolicy.
    expect(policyMap()['App\\Models\\Article'] ?? null)->toBe('App\\Policies\\Custom\\ArticleAccessPolicy');
});

it('recognises an aliased Gate facade import in a registration', function () {
    // use Illuminate\Support\Facades\Gate as Access; Access::policy(Widget::class, WidgetPolicy::class)
    expect(policyMap()['App\\Models\\Widget'] ?? null)->toBe('App\\Policies\\WidgetPolicy');
});

it('does not treat an unrelated ::policy() call as a Gate registration', function () {
    // App\Support\Gate::policy(Gadget::class, ...) is not the facade — no edge, and Gadget has no convention policy.
    expect(policyMap())->not->toHaveKey('App\\Models\\Gadget');
});

it('resolves string-literal model and policy references in a $policies map', function () {
    expect(policyMap()['App\\Models\\Report'] ?? null)->toBe('App\\Policies\\ReportPolicy');
});

it('resolves a nested-namespace model to a flat App\\Policies policy by convention', function () {
    expect(policyMap()['App\\Models\\Billing\\Invoice'] ?? null)->toBe('App\\Policies\\InvoicePolicy');
});

it('wires a model → policy edge into the graph', function () {
    $map = policyMap();

    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [], []);
    $builder->addPolicies($map);

    $policyNode = array_values(array_filter(
        $graph->nodes(),
        fn ($n) => $n->type === 'policy' && $n->data['fqcn'] === 'App\\Policies\\UserPolicy'
    ));
    expect($policyNode)->toHaveCount(1);
    expect($policyNode[0]->id)->toBe('policy::App\\Policies\\UserPolicy');

    $edge = array_values(array_filter(
        $graph->edges(),
        fn ($e) => $e->source === 'model::App\\Models\\User' && $e->target === $policyNode[0]->id
    ));
    expect($edge)->toHaveCount(1);
    expect($edge[0])
        ->label->toBe('authorized by')
        ->type->toBe('model-to-policy');
});

it('hangs the policy edge off the same model node as the fired-event edge', function () {
    $project = fixture('laravel-project');

    // Order both fires OrderPlaced (via $dispatchesEvents) and is authorized by a policy.
    $models = (new ModelAnalyzer)->analyze($project, ['App\\Models\\Order']);
    $map = policyMap();

    $builder = new GraphBuilder;
    $graph = $builder->build('test', [], new MiddlewareRegistry([], [], []), [], [], $models, $project);
    $builder->addPolicies($map);

    $modelNodes = array_filter($graph->nodes(), fn ($n) => $n->id === 'model::App\\Models\\Order');
    expect($modelNodes)->toHaveCount(1);

    $types = array_map(
        fn ($e) => $e->type,
        array_values(array_filter($graph->edges(), fn ($e) => $e->source === 'model::App\\Models\\Order'))
    );
    expect($types)
        ->toContain('model-to-event')
        ->toContain('model-to-policy');
});
