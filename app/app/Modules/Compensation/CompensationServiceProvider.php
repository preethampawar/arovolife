<?php

declare(strict_types=1);

namespace App\Modules\Compensation;

use Illuminate\Support\ServiceProvider;

final class CompensationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
