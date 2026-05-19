<?php

use App\Http\Controllers\AddressController;
use Illuminate\Support\Facades\Route;

// Provider registers this file with prefix('api/customer')->middleware('api').
// Expected full URI: DELETE /api/customer/address/{id}
Route::delete('/address/{id}', [AddressController::class, 'destroy']);
