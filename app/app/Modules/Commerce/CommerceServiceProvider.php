<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Commerce\Events\OrderPlaced;
use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Listeners\SendOrderPlacedMail;
use App\Modules\Commerce\Listeners\SendOrderStatusChangedMail;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\CouponService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Commerce\Services\ShippingService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttributionService::class);
        $this->app->singleton(CouponService::class);
        $this->app->singleton(CartService::class);
        $this->app->singleton(CheckoutService::class);
        $this->app->singleton(OrderStateMachine::class);
        $this->app->singleton(ShippingService::class);
        $this->app->singleton(BvLedgerService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Order notifications are event-driven (CLAUDE.md). Listeners are
        // queued and dispatch channel-agnostic Notifications, so adding SMS
        // later is a channel change, not a rewrite.
        Event::listen(OrderPlaced::class, SendOrderPlacedMail::class);
        Event::listen(OrderStatusChanged::class, SendOrderStatusChangedMail::class);

        // Make the cart item count available to the nav (cart-icon count badge
        // + highlight). Read-only — never creates a cart.
        View::composer('partials.public-topnav', function ($view): void {
            $view->with('cartItemCount', $this->app->make(CartService::class)->itemCount(request()));
        });
    }
}
