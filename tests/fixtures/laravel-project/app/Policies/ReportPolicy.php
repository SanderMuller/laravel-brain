<?php

namespace App\Policies;

class ReportPolicy
{
    public function view($authUser, $report): bool
    {
        return true;
    }
}
