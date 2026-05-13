<?php

use LaraMint\LaravelBrain\Analysis\EloquentModelDiscoverer;

it('discovers Eloquent models in app/Models', function () {
    $models = (new EloquentModelDiscoverer)->discover(fixture('laravel-project'));

    expect($models)->toContain('App\\Models\\Order');
    expect($models)->toContain('App\\Models\\User');
});

it('returns an empty list when no models are present', function () {
    $tmp = sys_get_temp_dir().'/brain-empty-project-'.uniqid();
    mkdir($tmp.'/app', 0755, true);

    $models = (new EloquentModelDiscoverer)->discover($tmp);

    expect($models)->toBe([]);

    rmdir($tmp.'/app');
    rmdir($tmp);
});
