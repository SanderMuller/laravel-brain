<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Graph\GraphBuilder;

it('walks the inheritance chain of a class that opens with declare(strict_types=1)', function () {
    $builder = new GraphBuilder;

    $psr4 = new ReflectionProperty(GraphBuilder::class, 'psr4Map');

    if (\PHP_VERSION_ID < 80100) {
        $psr4->setAccessible(true);
    }

    $psr4->setValue($builder, ['App' => fixture('strict-types-project').'/app']);

    $method = new ReflectionMethod(GraphBuilder::class, 'findMethodNodeInChain');

    if (\PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }

    // show() lives on the parent only, so resolving it from the child forces the extends walk
    // through extractExtendsFromAst() — on an AST whose Namespace_ node sits behind the declare.
    $found = $method->invoke($builder, 'App\Http\Controllers\StrictTypesChildController', 'show');

    expect($found)->not->toBeNull()
        ->and($found['declaringFqcn'])->toBe('App\Http\Controllers\StrictTypesBaseController');
});
