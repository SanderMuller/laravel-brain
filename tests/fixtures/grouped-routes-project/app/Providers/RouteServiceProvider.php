<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Chain form: Route::middleware(...)->prefix(...)->group(base_path('routes/...'))
        Route::middleware('api')
            ->prefix('api/customer')
            ->group(base_path('routes/api/customer.php'));

        // Static form: Route::group($attrs, base_path('routes/...'))
        Route::group(
            ['prefix' => 'api/restaurant', 'middleware' => ['api', 'auth:sanctum']],
            base_path('routes/api/restaurant.php')
        );

        // Chain form used for trailing-slash + nested-prefix verification
        Route::middleware('api')
            ->prefix('api/admin')
            ->group(base_path('routes/api/admin.php'));

        // Chain form combined with an inner Route::prefix() group inside the file
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api/v1.php'));
    }
}
