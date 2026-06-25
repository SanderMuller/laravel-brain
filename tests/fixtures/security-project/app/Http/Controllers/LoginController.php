<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Reproduces Bug 3 (controller-side throttle):
 * `POST /login` uses RateLimiter::tooManyAttempts() directly in the controller
 * — Breeze 2.x pattern — instead of the `throttle:` middleware.
 */
class LoginController
{
    public function store(Request $request): array
    {
        $key = 'login|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429);
        }
        RateLimiter::hit($key);

        return ['ok' => true];
    }

    /**
     * Reproduces Bug 3 (FormRequest-side throttle):
     * Breeze's LoginRequest::authenticate() throttles inside the FormRequest.
     */
    public function loginViaRequest(LoginRequest $request): array
    {
        return ['ok' => true];
    }
}
