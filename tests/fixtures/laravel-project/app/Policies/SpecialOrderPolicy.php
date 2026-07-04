<?php

namespace App\Policies;

class SpecialOrderPolicy
{
    public function view($authUser, $order): bool
    {
        return true;
    }

    public function delete($authUser, $order): bool
    {
        return true;
    }
}
