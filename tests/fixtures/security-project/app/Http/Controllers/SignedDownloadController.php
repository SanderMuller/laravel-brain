<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Reproduces Bug 2: route guarded only by `signed` (cryptographic URL
 * signature). Must be classified as `authed`, not `public`.
 */
class SignedDownloadController
{
    public function download(Request $request): array
    {
        return ['ok' => true];
    }
}
