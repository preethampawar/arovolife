<?php

declare(strict_types=1);

namespace App\Modules\Kyc;

use Illuminate\Support\ServiceProvider;

final class KycServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
