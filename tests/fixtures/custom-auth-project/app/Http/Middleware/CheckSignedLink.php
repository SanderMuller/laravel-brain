<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ValidateSignature;

/** A signed-URL guard renamed for the application, one hop from the framework class. */
class CheckSignedLink extends ValidateSignature {}
