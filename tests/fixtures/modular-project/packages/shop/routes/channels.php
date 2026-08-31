<?php

use Acme\Shop\Broadcasting\TenantChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', fn ($user, $orderId) => true);

TenantChannel::channel('tenant.orders.{orderId}', fn ($user, $orderId) => true);
