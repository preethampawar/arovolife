<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\CouponService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Commerce\Services\ShippingService;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
