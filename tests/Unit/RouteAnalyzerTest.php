<?php

use Acme\Pkg\AcmeVendorStub;
use App\Http\Controllers\DashController;
use App\Http\Controllers\MultiController;
use App\Http\Controllers\UserController;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Http\Controllers\BrainController;

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

it('auto-discover mode pulls routes from the live router', function () {
    $router = makeAutoDiscoverRouter();

    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->post('/login', AutoDiscoverInvokableStub::class); // invokable
    $router->get('/ping', function () {
        return 'pong';
    });
    $router->middleware(['auth:sanctum'])->group(function ($router) {
        $router->get('/dashboard', [DashController::class, 'index'])->name('dashboard');
    });
    $router->match(['GET', 'POST', 'HEAD'], '/multi', [MultiController::class, 'handle']);

    $routes = (new RouteAnalyzer([], autoDiscover: true))->analyze('/unused');

    expect($routes)->toBeArray()->each->toBeInstanceOf(RouteDefinition::class);

    $show = findRoute($routes, fn ($r) => $r->uri === '/users/{id}' && $r->method === 'GET');
    expect($show->controller)->toBe('App\Http\Controllers\UserController')
        ->and($show->action)->toBe('show')
        ->and($show->tabGroup)->toBe('GET /users/{id}');

    // Each route lands in its own tab subgraph (matches AST-mode behaviour)
    $tabGroups = array_map(fn ($r) => $r->tabGroup, $routes);
    expect(count($tabGroups))->toBe(count(array_unique($tabGroups)));

    $login = findRoute($routes, fn ($r) => $r->uri === '/login' && $r->method === 'POST');
    expect($login->controller)->toBe(AutoDiscoverInvokableStub::class)
        ->and($login->action)->toBe('__invoke');

    $closure = findRoute($routes, fn ($r) => $r->uri === '/ping');
    expect($closure->controller)->toBe('')
        ->and($closure->action)->toBe('closure')
        ->and($closure->closureNode)->not->toBeNull()
        ->and($closure->closureUseMap)->toBeArray();

    $dash = findRoute($routes, fn ($r) => $r->uri === '/dashboard');
    expect($dash->middlewares)->toContain('auth:sanctum')
        ->and($dash->name)->toBe('dashboard');

    // HEAD is filtered; GET+POST remain (one RouteDefinition per non-HEAD verb)
    $multi = array_values(array_filter($routes, fn ($r) => $r->uri === '/multi'));
    $methods = array_map(fn ($r) => $r->method, $multi);
    sort($methods);
    expect($methods)->toBe(['GET', 'POST']);

    Container::setInstance(null);
});

it('auto-discover mode excludes routes whose controller lives under vendor/', function () {
    $router = makeAutoDiscoverRouter();

    // App controller (this test file is NOT under vendor/)
    $router->get('/app-route', [UserController::class, 'show']);

    // Fake "vendor" controller: a stub class whose ReflectionClass file lives
    // inside a vendor/-shaped temp directory we put on the autoloader manually.
    $tmpVendor = sys_get_temp_dir().'/lb-vendor-'.uniqid('', true);
    mkdir($tmpVendor.'/vendor/acme/pkg/src', 0777, true);
    $stubFile = $tmpVendor.'/vendor/acme/pkg/src/AcmeVendorStub.php';
    file_put_contents($stubFile, <<<'PHP'
<?php
namespace Acme\Pkg;
class AcmeVendorStub
{
    public function __invoke() {}
}
PHP);
    require $stubFile;

    $router->get('/vendor-route', AcmeVendorStub::class);

    try {
        $routes = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: true))
            ->analyze($tmpVendor);

        $uris = array_map(fn ($r) => $r->uri, $routes);
        expect($uris)->toContain('/app-route')
            ->and($uris)->not->toContain('/vendor-route');

        // Disabling the filter brings the vendor route back.
        $all = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: false))
            ->analyze($tmpVendor);
        expect(array_map(fn ($r) => $r->uri, $all))->toContain('/vendor-route');
    } finally {
        routeAnalyzerTestDeleteTree($tmpVendor);
        Container::setInstance(null);
    }
});

it('auto-discover mode always drops the package\'s own _laravel-brain routes', function () {
    $router = makeAutoDiscoverRouter();

    $router->get('/app-route', [UserController::class, 'show']);
    $router->get('/_laravel-brain/api/source', [BrainController::class, 'source']);

    try {
        // Even with excludeVendor disabled, brain's own routes must be skipped.
        $routes = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: false))
            ->analyze('/unused');

        $uris = array_map(fn ($r) => $r->uri, $routes);
        expect($uris)->toContain('/app-route')
            ->and($uris)->not->toContain('/_laravel-brain/api/source');
    } finally {
        Container::setInstance(null);
    }
});

it('resolves bare action strings inside Route::controller() groups', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/memes.php',
        <<<'PHP'
<?php

use App\Http\Controllers\MemeController;
use Illuminate\Support\Facades\Route;

Route::controller(MemeController::class)->group(function () {
    Route::get('/divorce-child-custody-memes', 'index')->name('index.memes');
    Route::get('/divorce-child-custody-memes/{id}', 'showMemeById')->whereNumber('id')->name('show.meme-by-id');
    Route::get('/divorce-child-custody-memes/{slug}', 'show')->where('slug', '(.*)?')->name('show.meme-by-slug');
});

Route::get('/divorce-child-custody-memes/1/', function () {
    return redirect()->route('index.memes');
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $index = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes' && $r->method === 'GET');
        expect($index)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('App\Http\Controllers\MemeController')
            ->action->toBe('index');

        $byId = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes/{id}');
        expect($byId)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('App\Http\Controllers\MemeController')
            ->action->toBe('showMemeById');

        $bySlug = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes/{slug}');
        expect($bySlug)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('App\Http\Controllers\MemeController')
            ->action->toBe('show');

        $closure = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes/1/');
        expect($closure)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('Closure');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('follows Route::group(base_path(...)) chain form and applies prefix/middleware to inner routes', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    // Place both files in a subdirectory matching the default 'routes/*/*.php'
    // glob so the child file *would* be discoverable standalone — proving the
    // analyzer correctly excludes it from the standalone pass once it sees
    // the group.
    file_put_contents(
        $tmp.'/routes/web/api.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->prefix('api/admin')
    ->group(base_path('routes/web/admin.php'));

PHP
    );
    file_put_contents(
        $tmp.'/routes/web/admin.php',
        <<<'PHP'
<?php

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [AdminController::class, 'dashboard']);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $dashboardRoutes = array_values(array_filter(
            $routes,
            fn ($r) => $r->action === 'dashboard'
        ));

        // Exactly one definition — not double-counted standalone.
        expect($dashboardRoutes)->toHaveCount(1);

        $dashboard = $dashboardRoutes[0];
        expect($dashboard)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/admin/dashboard')
            ->method->toBe('GET')
            ->controller->toBe('App\Http\Controllers\AdminController');
        expect($dashboard->middlewares)->toContain('api');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('follows Route::group($attrs, base_path(...)) static form with attrs prefix/middleware', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/api.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::group(
    ['prefix' => 'v2/reports', 'middleware' => ['auth:sanctum']],
    base_path('routes/web/reports.php')
);

PHP
    );
    file_put_contents(
        $tmp.'/routes/web/reports.php',
        <<<'PHP'
<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/summary', [ReportController::class, 'summary']);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $summary = array_values(array_filter(
            $routes,
            fn ($r) => $r->action === 'summary'
        ));

        expect($summary)->toHaveCount(1);
        expect($summary[0])->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/v2/reports/summary')
            ->method->toBe('GET');
        expect($summary[0]->middlewares)->toContain('auth:sanctum');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('applies the prefix/middleware from a RouteServiceProvider::boot() group registration', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/api', 0777, true);
    mkdir($tmp.'/app/Providers', 0777, true);
    file_put_contents(
        $tmp.'/app/Providers/RouteServiceProvider.php',
        <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/customer')
            ->group(base_path('routes/api/customer.php'));
    }
}
PHP
    );
    file_put_contents(
        $tmp.'/routes/api/customer.php',
        <<<'PHP'
<?php

use App\Http\Controllers\AddressController;
use Illuminate\Support\Facades\Route;

Route::delete('/address/{id}', [AddressController::class, 'destroy']);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $delete = array_values(array_filter(
            $routes,
            fn ($r) => $r->action === 'destroy' && $r->method === 'DELETE'
        ));

        expect($delete)->toHaveCount(1);
        expect($delete[0])->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/customer/address/{id}')
            ->controller->toBe('App\Http\Controllers\AddressController');
        expect($delete[0]->middlewares)->toContain('api');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('applies static-form Route::group($attrs, base_path(...)) registered in a provider', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/api', 0777, true);
    mkdir($tmp.'/app/Providers', 0777, true);
    file_put_contents(
        $tmp.'/app/Providers/RouteServiceProvider.php',
        <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::group(
            ['prefix' => 'api/restaurant', 'middleware' => ['api', 'auth:sanctum']],
            base_path('routes/api/restaurant.php')
        );
    }
}
PHP
    );
    file_put_contents(
        $tmp.'/routes/api/restaurant.php',
        <<<'PHP'
<?php

use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

Route::delete('/category/{id}', [CategoryController::class, 'destroy']);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $delete = array_values(array_filter(
            $routes,
            fn ($r) => $r->action === 'destroy' && $r->method === 'DELETE'
        ));

        expect($delete)->toHaveCount(1);
        expect($delete[0])->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/restaurant/category/{id}');
        expect($delete[0]->middlewares)
            ->toContain('api')
            ->toContain('auth:sanctum');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('combines a provider-applied prefix with an inner Route::prefix(...) group', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/api', 0777, true);
    mkdir($tmp.'/app/Providers', 0777, true);
    file_put_contents(
        $tmp.'/app/Providers/RouteServiceProvider.php',
        <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;

class RouteServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api/v1.php'));
    }
}
PHP
    );
    file_put_contents(
        $tmp.'/routes/api/v1.php',
        <<<'PHP'
<?php

use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        $index = findRoute($routes, fn ($r) => $r->action === 'index');

        expect($index)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/v1/posts')
            ->method->toBe('GET');
        expect($index->middlewares)->toContain('api');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it("renders Route::get('/') inside a prefix group as the prefix without trailing slash", function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/api', 0777, true);
    file_put_contents(
        $tmp.'/routes/api/admin.php',
        <<<'PHP'
<?php

use App\Http\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('permission')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
});

Route::prefix('status')->group(function () {
    Route::get('/', [PermissionController::class, 'status']);
});

// Bare root route inside no prefix — should still resolve to '/'.
Route::get('/', [PermissionController::class, 'root']);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $perm = findRoute($routes, fn ($r) => $r->action === 'index');
        expect($perm)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/permission'); // not '/permission/'

        $status = findRoute($routes, fn ($r) => $r->action === 'status');
        expect($status)->uri->toBe('/status'); // not '/status/'

        $root = findRoute($routes, fn ($r) => $r->action === 'root');
        expect($root)->uri->toBe('/'); // bare root stays '/'
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('renders Route::get(/) inside a provider prefix without trailing slash', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/api', 0777, true);
    mkdir($tmp.'/app/Providers', 0777, true);
    file_put_contents(
        $tmp.'/app/Providers/RouteServiceProvider.php',
        <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;

class RouteServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/admin')
            ->group(base_path('routes/api/admin.php'));
    }
}
PHP
    );
    file_put_contents(
        $tmp.'/routes/api/admin.php',
        <<<'PHP'
<?php

use App\Http\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('permission')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        $perm = findRoute($routes, fn ($r) => $r->action === 'index');

        // Combined provider prefix + inner prefix + root URI must NOT keep
        // the trailing slash from the empty Route::get('/') segment.
        expect($perm)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/admin/permission');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('resolves base_path(...) inside a require statement', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/api', 0777, true);
    file_put_contents(
        $tmp.'/routes/api/web.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('settings')->group(function () {
    require base_path('routes/api/settings.php');
});

PHP
    );
    file_put_contents(
        $tmp.'/routes/api/settings.php',
        <<<'PHP'
<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/general', [SettingsController::class, 'general']);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        $general = findRoute($routes, fn ($r) => $r->action === 'general');

        expect($general)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/settings/general');
        expect($general->middlewares)->toContain('auth');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('follows require into a route file carrying parent group context, once', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/inc', 0777, true);
    file_put_contents(
        $tmp.'/routes/api.php',
        <<<'PHP'
<?php

use App\Http\Middleware\EnsureProjectUnlocked;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', EnsureProjectUnlocked::class])
    ->prefix('api')
    ->group(function () {
        require __DIR__ . '/inc/notes.php';
    });

PHP
    );
    file_put_contents(
        $tmp.'/routes/inc/notes.php',
        <<<'PHP'
<?php

use App\Http\Controllers\ClientController;
use App\Http\Middleware\EnforcesModelRelations;
use Illuminate\Support\Facades\Route;

Route::middleware([EnforcesModelRelations::class])->group(function () {
    Route::get('clients/{client}/notes', [ClientController::class, 'indexNotes']);
    Route::post('clients/{client}/notes', [ClientController::class, 'storeNote']);
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $index = findRoute($routes, fn ($r) => $r->method === 'GET' && str_contains($r->uri, 'clients'));
        expect($index)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/clients/{client}/notes')
            ->controller->toBe('App\Http\Controllers\ClientController')
            ->action->toBe('indexNotes');
        expect($index->middlewares)
            ->toContain('auth:sanctum')
            ->toContain('throttle:api')
            ->toContain('App\Http\Middleware\EnsureProjectUnlocked')
            ->toContain('App\Http\Middleware\EnforcesModelRelations');

        // notes.php must not also be parsed standalone (which would yield a
        // duplicate without the parent prefix/middleware).
        $noteRoutes = array_values(array_filter($routes, fn ($r) => str_contains($r->uri, 'clients')));
        expect($noteRoutes)->toHaveCount(2);
        foreach ($noteRoutes as $r) {
            expect($r->uri)->toStartWith('/api/');
        }
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

// Helper Functions

class AutoDiscoverInvokableStub
{
    public function __invoke() {}
}

function makeAutoDiscoverRouter(): Router
{
    $container = new Container;
    Container::setInstance($container);

    $events = new Dispatcher($container);
    $router = new Router($events, $container);

    $container->instance('router', $router);
    $container->instance(Router::class, $router);

    return $router;
}

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
