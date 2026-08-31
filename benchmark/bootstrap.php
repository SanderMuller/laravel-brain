<?php

declare(strict_types=1);

/**
 * Minimal Laravel container + config bootstrap, so the analyzers' config() calls
 * work outside a full Laravel application.
 *
 * ProjectAnalyzer::analyze() takes the project root explicitly, so only 'config'
 * and 'path.base' need binding.
 *
 * The autoloader is resolved from BRAIN_BENCH_AUTOLOAD when that is set. That is
 * what lets one copy of the benchmark measure a different checkout's src/: the CI
 * job points it at the base branch's vendor/autoload.php for one arm and at the
 * pull request's for the other, so both arms run identical harness code.
 */

use Illuminate\Config\Repository;
use Illuminate\Container\Container;

$autoload = getenv('BRAIN_BENCH_AUTOLOAD');

if (! is_string($autoload) || $autoload === '') {
    $autoload = __DIR__.'/../vendor/autoload.php';
}

if (! is_file($autoload)) {
    fwrite(STDERR, "autoloader not found at {$autoload} — run composer install\n");
    exit(1);
}

require $autoload;

/**
 * @param  array<string, mixed>  $config  extra config to merge (e.g. laravel-brain.* overrides)
 */
function brain_bench_bootstrap(array $config = []): Container
{
    $container = new Container;
    Container::setInstance($container);

    $repo = new Repository(array_replace_recursive([
        'app' => [
            'name' => 'BenchmarkApp',
            'url' => 'http://localhost',
        ],
        // Every analyzer passes its own default to config(), so an empty node is
        // enough to make config('laravel-brain.*') resolvable.
        'laravel-brain' => [],
    ], $config));

    $container->instance('config', $repo);
    $container->instance('path.base', getcwd());

    return $container;
}
