<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Graph\GraphBuilder;

/** Build a GraphBuilder whose PSR-4 map points App\ at $appDir, and expose the private walk. */
function chainWalker(string $appDir): Closure
{
    $builder = new GraphBuilder;

    $psr4 = new ReflectionProperty(GraphBuilder::class, 'psr4Map');

    if (\PHP_VERSION_ID < 80100) {
        $psr4->setAccessible(true);
    }

    $psr4->setValue($builder, ['App' => $appDir]);

    $method = new ReflectionMethod(GraphBuilder::class, 'findMethodNodeInChain');

    if (\PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }

    return fn (string $fqcn, string $name) => $method->invoke($builder, $fqcn, $name);
}

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

it('returns the first declaration when one file declares the method twice', function () {
    $dir = sys_get_temp_dir().'/brain-chain-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/Duo.php', <<<'PHP'
        <?php
        namespace App;
        class Duo { public function handle(int $first) {} }
        class Sibling { public function handle(int $second) {} }
        PHP);

    $found = chainWalker($dir)('App\Duo', 'handle');

    expect($found)->not->toBeNull()
        ->and($found['methodNode']->params[0]->var->name)->toBe('first');

    unlink($dir.'/Duo.php');
    rmdir($dir);
});

it('does not resolve a method declared inside an anonymous class in a method body', function () {
    $dir = sys_get_temp_dir().'/brain-chain-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/Outer.php', <<<'PHP'
        <?php
        namespace App;
        class Outer {
            public function boot() {
                return new class { public function handle() {} };
            }
        }
        PHP);

    // Outer::handle() does not exist; the handle() nested in boot()'s body belongs to an
    // anonymous class and must not be reported as Outer's.
    expect(chainWalker($dir)('App\Outer', 'handle'))->toBeNull();

    unlink($dir.'/Outer.php');
    rmdir($dir);
});
