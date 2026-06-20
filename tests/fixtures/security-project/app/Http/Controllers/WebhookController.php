<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Reproduces Bug 2: webhook authenticated by HMAC signature in the
 * controller (not via the standard `auth` middleware). With `trusted_route_*`
 * config set, PUBLIC_WRITE must be suppressed.
 */
class WebhookController
{
    public function stripe(Request $request): array
    {
        // Signature verification omitted for fixture brevity.
        return ['ok' => true];
    }
}
