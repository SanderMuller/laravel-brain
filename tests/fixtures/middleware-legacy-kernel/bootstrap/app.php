<?php

use App\Http\Kernel;
use Illuminate\Foundation\Application;

// A Laravel 10 bootstrap file: it creates the application and binds the
// kernels, and configures no middleware at all.
$app = new Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    Kernel::class
);

return $app;
