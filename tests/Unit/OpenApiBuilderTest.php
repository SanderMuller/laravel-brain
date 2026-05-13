<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\OpenApi\OpenApiBuilder;

it('builds an OpenAPI 3.0 document from the fixture project', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);

    $spec = (new OpenApiBuilder)->build($routes, $controllers, 'Test API', '2.0.0');

    expect($spec['openapi'])->toBe('3.0.3');
    expect($spec['info'])->toMatchArray(['title' => 'Test API', 'version' => '2.0.0']);
    expect($spec['paths'])->toBeNonEmptyArray();
});

it('emits a POST /login path with a tag', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);

    $spec = (new OpenApiBuilder)->build($routes, $controllers);

    expect($spec['paths'])->toHaveKey('/login');
    expect($spec['paths']['/login'])->toHaveKey('post');
    expect($spec['paths']['/login']['post']['tags'])->toBe(['Auth']);
});

it('extracts path parameters from {id} segments', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);

    $spec = (new OpenApiBuilder)->build($routes, $controllers);

    expect($spec['paths'])->toHaveKey('/orders/{id}');
    $params = $spec['paths']['/orders/{id}']['get']['parameters'];
    expect($params)->toHaveCount(1);
    expect($params[0])->toMatchArray([
        'name' => 'id',
        'in' => 'path',
        'required' => true,
    ]);
});

it('emits bearerAuth security for auth:sanctum middleware', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);

    $spec = (new OpenApiBuilder)->build($routes, $controllers);

    expect($spec['components']['securitySchemes'])->toHaveKey('bearerAuth');
    expect($spec['paths']['/orders']['get']['security'])->toBe([['bearerAuth' => []]]);
});
