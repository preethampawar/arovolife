<?php

declare(strict_types=1);

namespace App\Modules\Consent;

use Illuminate\Support\ServiceProvider;

final class ConsentServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
