<?php

use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;

it('extracts basic routes from api.php', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));

    expect($routes)
        ->toBeArray()
        ->each->toBeInstanceOf(RouteDefinition::class);
});

it('finds the POST /login route', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $login = findRoute($routes, fn ($r) => str_contains($r->uri, 'login'));

    expect($login)->toBeInstanceOf(RouteDefinition::class)
        ->method->toBe('POST')
        ->controller->toBe('App\Http\Controllers\AuthController')
        ->action->toBe('login');
});

it('extracts middleware from groups', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $ordersRoute = findRoute($routes, fn ($r) => $r->uri === '/orders' && $r->method === 'GET');

    expect($ordersRoute)->toBeInstanceOf(RouteDefinition::class)
        ->middlewares->toBeArray()->toContain('auth:sanctum');
});

it('applies prefix from nested group', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $adminRoute = findRoute($routes, fn ($r) => str_contains($r->uri, 'admin'));

    expect($adminRoute)->toBeInstanceOf(RouteDefinition::class)
        ->uri->toBe('/admin/orders/{id}')
        ->middlewares->toBeArray()->toContain('role:admin');
});

it('finds 13 routes total', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));

    expect($routes)
        ->toBeArray()
        ->toHaveCount(13);
});

it('captures middleware chained after the HTTP method call', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $brandsRoute = findRoute($routes, fn ($r) => $r->uri === '/brands' && $r->method === 'GET');

    expect($brandsRoute)->toBeInstanceOf(RouteDefinition::class)
        ->middlewares->toBeArray()->toContain('ability:view-maintenance-requests,monitor-maintenance,create-transfer');
});

it('expands Route::resource with distinct URIs and tab groups per action', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/blog.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::resource('blog', \App\Http\Controllers\BlogController::class);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        expect($routes)->toHaveCount(8);

        $updateRoutes = array_values(array_filter($routes, fn ($r) => $r->action === 'update'));
        expect($updateRoutes)->toHaveCount(2);
        $updateMethods = array_map(fn ($r) => $r->method, $updateRoutes);
        sort($updateMethods);
        expect($updateMethods)->toBe(['PATCH', 'PUT']);

        $index = findRoute($routes, fn ($r) => $r->action === 'index' && $r->method === 'GET');
        expect($index->uri)->toBe('/blog');
        expect($index->tabGroup)->toBe('GET /blog');

        $create = findRoute($routes, fn ($r) => $r->action === 'create' && $r->method === 'GET');
        expect($create->uri)->toBe('/blog/create');
        expect($create->tabGroup)->toBe('GET /blog/create');

        $show = findRoute($routes, fn ($r) => $r->action === 'show');
        expect($show->uri)->toBe('/blog/{blog}');

        $tabGroups = array_map(fn ($r) => $r->tabGroup, $routes);
        expect(count($tabGroups))->toBe(count(array_unique($tabGroups)));
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('expands Route::apiResource without create or edit routes', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/posts.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;

Route::apiResource('posts', \App\Http\Controllers\PostController::class);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        expect($routes)->toHaveCount(6);
        expect(findRoute($routes, fn ($r) => $r->action === 'create'))->toBeNull();
        expect(findRoute($routes, fn ($r) => $r->action === 'edit'))->toBeNull();

        $show = findRoute($routes, fn ($r) => $r->action === 'show');
        expect($show->uri)->toBe('/posts/{post}');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

// Helper Functions

function findRoute(array $routes, callable $predicate): mixed
{
    foreach ($routes as $r) {
        if ($predicate($r)) {
            return $r;
        }
    }

    return null;
}

function routeAnalyzerTestDeleteTree(string $dir): void
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
