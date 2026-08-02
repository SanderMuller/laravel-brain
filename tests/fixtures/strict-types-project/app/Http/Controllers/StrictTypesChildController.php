<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class StrictTypesChildController extends StrictTypesBaseController
{
    public function index(): string
    {
        return 'index';
    }
}
