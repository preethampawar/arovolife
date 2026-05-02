<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Services\StubGateway;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StubGateway::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
