<?php

namespace App\Providers;

use App\Models\Comment;
use App\Models\Gadget;
use App\Models\Order;
use App\Models\Widget;
use App\Policies\CommentPolicy;
use App\Policies\GadgetPolicy;
use App\Policies\SpecialOrderPolicy;
use App\Policies\WidgetPolicy;
use App\Support\Gate as HomeGrownGate;
use Illuminate\Support\Facades\Gate as Access;

class AuthServiceProvider
{
    protected $policies = [
        Comment::class => CommentPolicy::class,
        // String-literal registration (both key and value).
        'App\\Models\\Report' => 'App\\Policies\\ReportPolicy',
    ];

    public function boot(): void
    {
        // Explicit registration overrides the App\Policies\OrderPolicy convention.
        Access::policy(Order::class, SpecialOrderPolicy::class);

        // Aliased Gate facade import must still be recognised.
        Access::policy(Widget::class, WidgetPolicy::class);

        // An unrelated, home-grown ::policy() must NOT be treated as Gate.
        HomeGrownGate::policy(Gadget::class, GadgetPolicy::class);
    }
}
