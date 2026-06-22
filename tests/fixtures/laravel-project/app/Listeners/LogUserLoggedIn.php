<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;

class LogUserLoggedIn
{
    public function __invoke(UserLoggedIn $event): void {}
}
