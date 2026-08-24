<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;

/**
 * Custom auth middleware backing a second guard (e.g. the `auth.customer`
 * alias). Extending the framework's Authenticate is the standard Laravel
 * idiom for adding a guard alongside `auth` — but the class name does not
 * contain "auth", so basename matching against `Authenticate` alone misses
 * it and the extends-chain walk is what recognises it.
 */
class AuthenticateCustomer extends Authenticate {}
