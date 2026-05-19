<?php

use Illuminate\Support\Facades\Route;

// This file demonstrates Route::group(base_path(...)) used inside another
// routes file (rather than in a service provider). The analyzer must
// recurse into routes/web/settings.php with this group's context applied
// AND must not also parse settings.php standalone.
Route::middleware('web')
    ->prefix('settings')
    ->group(base_path('routes/web/settings.php'));
