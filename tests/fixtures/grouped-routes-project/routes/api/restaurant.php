<?php

use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

// Provider registers this file via Route::group(['prefix' => 'api/restaurant',
// 'middleware' => ['api', 'auth:sanctum']], base_path(...)). Expected full URI:
// DELETE /api/restaurant/category/{id}
Route::delete('/category/{id}', [CategoryController::class, 'destroy']);
