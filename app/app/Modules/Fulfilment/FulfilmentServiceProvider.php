<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment;

use Illuminate\Support\ServiceProvider;

final class FulfilmentServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
