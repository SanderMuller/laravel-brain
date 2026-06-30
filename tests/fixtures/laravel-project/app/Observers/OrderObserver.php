<?php

namespace App\Observers;

use App\Models\Order;

class OrderObserver
{
    public function created(Order $order): void {}

    public function updated(Order $order): void {}

    public function deleted(Order $order): void {}
}
