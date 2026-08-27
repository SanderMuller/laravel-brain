<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;

/**
 * A guard on top of HTTP Basic auth — the shape a machine-to-machine endpoint
 * takes. Nothing in its name says "auth", and `auth.basic` is not the `auth`
 * pattern, so only the extends chain recognises it.
 */
class RequireBasicCredentials extends AuthenticateWithBasicAuth {}
