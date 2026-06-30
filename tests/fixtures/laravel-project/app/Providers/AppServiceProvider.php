<?php

namespace App\Providers;

use App\Contracts\ThingRepositoryInterface;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\UserObserver;
use App\Repositories\SqlThingRepository;

class AppServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThingRepositoryInterface::class, SqlThingRepository::class);
    }

    public function boot(): void
    {
        Order::observe(OrderObserver::class);
        User::observe([UserObserver::class]);

        // Also registered via #[ObservedBy] on the model — must de-duplicate to one edge.
        Product::observe(ProductObserver::class);

        // String-literal observer reference (Tag also has an aliased #[ObservedBy]).
        Tag::observe('App\\Observers\\TagAuditObserver');
    }
}
