<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Services\PaymentGatewayResolver;
use App\Modules\Payments\Services\RazorpayClient;
use App\Modules\Payments\Services\RazorpayGateway;
use App\Modules\Payments\Services\StubGateway;
use App\Modules\Payments\Support\PaymentSettings;
use App\Modules\Payments\Support\RazorpayPayloadScrubber;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentSettings::class);
        $this->app->singleton(RazorpayPayloadScrubber::class);
        $this->app->singleton(RazorpayClient::class);
        $this->app->singleton(RazorpayGateway::class);
        $this->app->singleton(StubGateway::class);
        $this->app->singleton(PaymentGatewayResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
