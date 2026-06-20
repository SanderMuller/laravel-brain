<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            abort(429);
        }
        RateLimiter::hit($this->throttleKey());
    }

    public function throttleKey(): string
    {
        return 'login|'.$this->ip();
    }
}
