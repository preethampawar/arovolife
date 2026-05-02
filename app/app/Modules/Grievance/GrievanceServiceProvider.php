<?php

declare(strict_types=1);

namespace App\Modules\Grievance;

use Illuminate\Support\ServiceProvider;

final class GrievanceServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
