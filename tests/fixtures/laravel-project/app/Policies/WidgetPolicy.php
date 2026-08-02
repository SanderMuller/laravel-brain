<?php

namespace App\Policies;

class WidgetPolicy
{
    public function view($authUser, $widget): bool
    {
        return true;
    }
}
