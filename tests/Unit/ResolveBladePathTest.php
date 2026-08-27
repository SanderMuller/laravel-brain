<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

function deleteTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fileinfo) {
        $path = $fileinfo->getPathname();
        if ($fileinfo->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

it('resolves module-style namespaced views under Modules/{studly}/resources/views', function () {
    $tmp = sys_get_temp_dir().'/laravel-brain-blade-'.uniqid('', true);
    mkdir($tmp.'/Modules/Blog/resources/views/posts', 0777, true);
    $expected = $tmp.'/Modules/Blog/resources/views/posts/index.blade.php';
    file_put_contents($expected, '<div>ok</div>');

    try {
        $builder = new GraphBuilder;
        $rootProp = new ReflectionProperty(GraphBuilder::class, 'projectRoot');

        if (\PHP_VERSION_ID < 80100) {
            $rootProp->setAccessible(true);
        }

        $rootProp->setValue($builder, $tmp);

        $method = new ReflectionMethod(GraphBuilder::class, 'resolveBladePath');

        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        expect($method->invoke($builder, 'blog::posts.index'))->toBe($expected);
    } finally {
        deleteTree($tmp);
    }
});

it('resolves namespaced views under resources/views/vendor/{hint}', function () {
    $tmp = sys_get_temp_dir().'/laravel-brain-blade-'.uniqid('', true);
    mkdir($tmp.'/resources/views/vendor/acme', 0777, true);
    $expected = $tmp.'/resources/views/vendor/acme/widget.blade.php';
    file_put_contents($expected, '<div>v</div>');

    try {
        $builder = new GraphBuilder;
        $rootProp = new ReflectionProperty(GraphBuilder::class, 'projectRoot');

        if (\PHP_VERSION_ID < 80100) {
            $rootProp->setAccessible(true);
        }

        $rootProp->setValue($builder, $tmp);

        $method = new ReflectionMethod(GraphBuilder::class, 'resolveBladePath');

        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        expect($method->invoke($builder, 'acme::widget'))->toBe($expected);
    } finally {
        deleteTree($tmp);
    }
});

it('falls back to scanning Modules/*/resources/views when studly folder name differs', function () {
    $tmp = sys_get_temp_dir().'/laravel-brain-blade-'.uniqid('', true);
    mkdir($tmp.'/Modules/CustomBlog/resources/views', 0777, true);
    $expected = $tmp.'/Modules/CustomBlog/resources/views/home.blade.php';
    file_put_contents($expected, '<div>h</div>');

    try {
        $builder = new GraphBuilder;
        $rootProp = new ReflectionProperty(GraphBuilder::class, 'projectRoot');

        if (\PHP_VERSION_ID < 80100) {
            $rootProp->setAccessible(true);
        }

        $rootProp->setValue($builder, $tmp);

        $method = new ReflectionMethod(GraphBuilder::class, 'resolveBladePath');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        expect($method->invoke($builder, 'blog::home'))->toBe($expected);
    } finally {
        deleteTree($tmp);
    }
});

// ── Ambiguity across configured view roots ────────────────────────────────────

/** @param string[] $viewPaths */
function resolveBlade(string $projectRoot, array $viewPaths, string $viewDot): ?string
{
    SourceDirectories::clear();

    $builder = new GraphBuilder;
    $builder->setViewPaths($viewPaths);

    $rootProp = new ReflectionProperty(GraphBuilder::class, 'projectRoot');
    $rootProp->setValue($builder, $projectRoot);

    $method = new ReflectionMethod(GraphBuilder::class, 'resolveBladePath');

    return $method->invoke($builder, $viewDot);
}

function blade_writeTwoPackages(): string
{
    $tmp = sys_get_temp_dir().'/lb_blade_'.bin2hex(random_bytes(6));
    foreach (['alpha', 'shipping'] as $package) {
        mkdir($tmp.'/packages/'.$package.'/resources/views/orders', 0o777, true);
        file_put_contents($tmp.'/packages/'.$package.'/resources/views/orders/index.blade.php', '<div></div>');
    }
    mkdir($tmp.'/packages/alpha/resources/views/reports', 0o777, true);
    file_put_contents($tmp.'/packages/alpha/resources/views/reports/daily.blade.php', '<div></div>');

    return $tmp;
}

it('resolves a view that only one configured root holds', function () {
    $tmp = blade_writeTwoPackages();

    try {
        expect(resolveBlade($tmp, ['packages/*/resources/views'], 'reports.daily'))
            ->toBe($tmp.'/packages/alpha/resources/views/reports/daily.blade.php');
    } finally {
        deleteTree($tmp);
    }
});

it('refuses to guess when more than one root holds the same view', function () {
    $tmp = blade_writeTwoPackages();

    try {
        // Picking by array order would link `orders.index` to whichever package sorted
        // first — a confidently wrong edge, which reads worse than a missing one.
        expect(resolveBlade($tmp, ['packages/*/resources/views'], 'orders.index'))->toBeNull();
    } finally {
        deleteTree($tmp);
    }
});

it('lets a namespace hint pick the root it names', function () {
    $tmp = blade_writeTwoPackages();

    try {
        expect(resolveBlade($tmp, ['packages/*/resources/views'], 'shipping::orders.index'))
            ->toBe($tmp.'/packages/shipping/resources/views/orders/index.blade.php');
    } finally {
        deleteTree($tmp);
    }
});

it('takes the package half of a vendor-prefixed namespace hint', function () {
    $tmp = blade_writeTwoPackages();

    try {
        expect(resolveBlade($tmp, ['packages/*/resources/views'], 'acme-shipping::orders.index'))
            ->toBe($tmp.'/packages/shipping/resources/views/orders/index.blade.php');
    } finally {
        deleteTree($tmp);
    }
});

it('does not accept a root whose name merely starts the hint', function () {
    $tmp = blade_writeTwoPackages();

    try {
        // `alpha` must not answer for `alpha-pro`: a substring match here is the same
        // "close enough" step that produces a wrong edge.
        expect(resolveBlade($tmp, ['packages/*/resources/views'], 'alpha-pro::orders.index'))->toBeNull();
    } finally {
        deleteTree($tmp);
    }
});

it('still resolves a hinted view when only one root holds it at all', function () {
    $tmp = blade_writeTwoPackages();

    try {
        expect(resolveBlade($tmp, ['packages/*/resources/views'], 'nobody::reports.daily'))
            ->toBe($tmp.'/packages/alpha/resources/views/reports/daily.blade.php');
    } finally {
        deleteTree($tmp);
    }
});
