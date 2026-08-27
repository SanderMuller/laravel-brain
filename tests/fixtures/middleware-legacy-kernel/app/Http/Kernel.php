<?php

namespace App\Http;

use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\ThrottleApi;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareGroups = [
        'api' => [
            ThrottleApi::class,
        ],
    ];

    protected $middlewareAliases = [
        'auth.customer' => AuthenticateCustomer::class,
    ];
}
