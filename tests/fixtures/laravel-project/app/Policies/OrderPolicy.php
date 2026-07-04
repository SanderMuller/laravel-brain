<?php

namespace App\Policies;

class OrderPolicy
{
    public function view($authUser, $order): bool
    {
        return true;
    }
}
