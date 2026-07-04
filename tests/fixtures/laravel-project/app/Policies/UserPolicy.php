<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view($authUser, User $user): bool
    {
        return true;
    }

    public function update($authUser, User $user): bool
    {
        return true;
    }
}
