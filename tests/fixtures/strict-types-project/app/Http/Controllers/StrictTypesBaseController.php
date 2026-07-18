<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class StrictTypesBaseController
{
    public function show(): string
    {
        return 'ok';
    }
}
