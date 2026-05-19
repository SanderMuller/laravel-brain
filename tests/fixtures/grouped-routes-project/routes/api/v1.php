<?php

use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

// Provider registers this file with prefix('api')->middleware('api').
// The inner Route::prefix('v1') composes with the provider's prefix.
// Expected full URI: GET /api/v1/posts
Route::prefix('v1')->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
});
