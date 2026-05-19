<?php

use App\Http\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

// Provider registers this file with prefix('api/admin')->middleware('api').
// The inner Route::prefix('permission')->group(Route::get('/')) used to render
// as /api/admin/permission/ (trailing slash). Expected full URI is now
// /api/admin/permission — no trailing slash, matching Laravel's router.
Route::prefix('permission')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
});
