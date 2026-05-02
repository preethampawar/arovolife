<?php

declare(strict_types=1);

namespace App\Modules\Returns;

use Illuminate\Support\ServiceProvider;

final class ReturnsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
