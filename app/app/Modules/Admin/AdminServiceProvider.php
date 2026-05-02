<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
