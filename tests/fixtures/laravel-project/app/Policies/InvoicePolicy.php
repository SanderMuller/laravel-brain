<?php

namespace App\Policies;

class InvoicePolicy
{
    public function view($authUser, $invoice): bool
    {
        return true;
    }
}
