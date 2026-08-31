<?php

use App\Http\Controllers\ArticleController;
use Illuminate\Support\Facades\Route;

Route::get('/articles/{article}', [ArticleController::class, 'show']);
