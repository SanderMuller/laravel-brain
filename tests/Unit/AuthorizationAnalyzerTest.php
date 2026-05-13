<?php

use LaraMint\LaravelBrain\Analysis\AuthorizationAnalyzer;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;

it('classifies routes by their auth middleware', function () {
    $project = fixture('laravel-project');
    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = (new ControllerAnalyzer)->analyze($project, $routes);

    $result = (new AuthorizationAnalyzer)->analyze($routes, $controllers, $project);

    expect($result)->toHaveKeys(['routes', 'policies']);

    // /login is public
    expect($result['routes'])->toHaveKey('route::POST::/login');
    expect($result['routes']['route::POST::/login']['kind'])->toBe('public');

    // /orders (GET) is authenticated via auth:sanctum
    expect($result['routes'])->toHaveKey('route::GET::/orders');
    $ordersIndex = $result['routes']['route::GET::/orders'];
    expect($ordersIndex['kind'])->toBe('authenticated');
    expect($ordersIndex['guards'])->toContain('auth:sanctum');

    // /admin/orders/{id} (DELETE) uses role:admin → policy-gated
    expect($result['routes'])->toHaveKey('route::DELETE::/admin/orders/{id}');
    $adminDelete = $result['routes']['route::DELETE::/admin/orders/{id}'];
    expect($adminDelete['kind'])->toBe('policy-gated');
    expect($adminDelete['abilities'])->toContain('role:admin');
});

it('discovers Policy classes by naming convention', function () {
    $tmp = sys_get_temp_dir().'/brain-auth-test-'.uniqid();
    mkdir($tmp.'/app/Policies', 0755, true);
    file_put_contents($tmp.'/app/Policies/PostPolicy.php', <<<'PHP'
<?php
namespace App\Policies;
class PostPolicy { public function update($user, $post) { return true; } }
PHP);
    mkdir($tmp.'/routes', 0755, true);
    file_put_contents($tmp.'/routes/api.php', '<?php');

    $result = (new AuthorizationAnalyzer)->analyze([], [], $tmp);

    expect($result['policies'])->toHaveKey('App\\Models\\Post');
    expect($result['policies']['App\\Models\\Post'])->toBe('App\\Policies\\PostPolicy');

    // cleanup
    unlink($tmp.'/app/Policies/PostPolicy.php');
    unlink($tmp.'/routes/api.php');
    rmdir($tmp.'/app/Policies');
    rmdir($tmp.'/app');
    rmdir($tmp.'/routes');
    rmdir($tmp);
});

it('reads policies from AuthServiceProvider', function () {
    $tmp = sys_get_temp_dir().'/brain-auth-asp-'.uniqid();
    mkdir($tmp.'/app/Providers', 0755, true);
    file_put_contents($tmp.'/app/Providers/AuthServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
use App\Models\Article;
use App\Policies\ArticlePolicy;
class AuthServiceProvider {
    protected $policies = [
        Article::class => ArticlePolicy::class,
    ];
}
PHP);

    $result = (new AuthorizationAnalyzer)->analyze([], [], $tmp);

    expect($result['policies'])->toHaveKey('App\\Models\\Article');
    expect($result['policies']['App\\Models\\Article'])->toBe('App\\Policies\\ArticlePolicy');

    unlink($tmp.'/app/Providers/AuthServiceProvider.php');
    rmdir($tmp.'/app/Providers');
    rmdir($tmp.'/app');
    rmdir($tmp);
});
