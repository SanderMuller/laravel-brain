<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Loaded by routes/web/routes-loader.php via
// Route::middleware('web')->prefix('settings')->group(base_path(...)).
// Expected full URI: GET /settings/general
Route::get('/general', [SettingsController::class, 'general']);
