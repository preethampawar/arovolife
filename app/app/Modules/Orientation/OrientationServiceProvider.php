<?php

declare(strict_types=1);

namespace App\Modules\Orientation;

use Illuminate\Support\ServiceProvider;

final class OrientationServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
