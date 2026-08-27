<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;

/** The live guard the `auth.customer` alias in bootstrap/app.php points at. */
class AuthenticateCustomer extends Authenticate {}
