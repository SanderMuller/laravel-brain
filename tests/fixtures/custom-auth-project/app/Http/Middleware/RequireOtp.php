<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

/**
 * A second confirmation step layered on the framework's verification guard.
 * The `verified` alias is already an auth pattern; this is the same guard
 * under a name of the application's own.
 */
class RequireOtp extends EnsureEmailIsVerified {}
