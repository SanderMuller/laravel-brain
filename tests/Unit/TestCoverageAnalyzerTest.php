<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\TestCoverageAnalyzer;

function buildCoverageTmpProject(string $testBody): string
{
    $tmp = sys_get_temp_dir().'/brain-coverage-test-'.uniqid();
    mkdir($tmp.'/tests/Feature', 0755, true);
    mkdir($tmp.'/routes', 0755, true);

    file_put_contents($tmp.'/routes/api.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/orders', function() {});\nRoute::post('/orders', function() {});\nRoute::get('/orders/{id}', function() {});\n");
    file_put_contents($tmp.'/tests/Feature/OrdersTest.php', $testBody);

    return $tmp;
}

it('matches HTTP test calls to their routes', function () {
    $tmp = buildCoverageTmpProject(<<<'PHP'
<?php
class OrdersTest {
    public function test_index() {
        $this->getJson('/orders');
    }
    public function test_show() {
        $this->get('/orders/123');
    }
    public function test_store() {
        $this->postJson('/orders', ['status' => 'new']);
    }
}
PHP);

    $routes = (new RouteAnalyzer)->analyze($tmp);
    $controllers = (new ControllerAnalyzer)->analyze($tmp, $routes);

    $result = (new TestCoverageAnalyzer)->analyze($routes, $controllers, $tmp);

    expect($result['routes']['route::GET::/orders']['strength'])->toBe('http');
    expect($result['routes']['route::GET::/orders']['covered_by'])->toContain('tests/Feature/OrdersTest.php');

    expect($result['routes']['route::POST::/orders']['strength'])->toBe('http');

    // Wildcard match: /orders/{id} should match /orders/123
    expect($result['routes']['route::GET::/orders/{id}']['strength'])->toBe('http');
});

it('marks routes without any matching test call as uncovered', function () {
    $tmp = buildCoverageTmpProject(<<<'PHP'
<?php
class OrdersTest {
    public function test_unrelated() { $this->get('/something-else'); }
}
PHP);

    $routes = (new RouteAnalyzer)->analyze($tmp);
    $controllers = (new ControllerAnalyzer)->analyze($tmp, $routes);

    $result = (new TestCoverageAnalyzer)->analyze($routes, $controllers, $tmp);

    foreach ($result['routes'] as $row) {
        expect($row['strength'])->toBe('none');
        expect($row['covered_by'])->toBe([]);
    }
});

it('detects a Clover coverage file when present', function () {
    $tmp = buildCoverageTmpProject('<?php');
    file_put_contents($tmp.'/coverage.xml', '<?xml version="1.0"?><coverage/>');

    $routes = (new RouteAnalyzer)->analyze($tmp);
    $controllers = (new ControllerAnalyzer)->analyze($tmp, $routes);

    $result = (new TestCoverageAnalyzer)->analyze($routes, $controllers, $tmp);

    expect($result['cloverFile'])->toBe('coverage.xml');
});
