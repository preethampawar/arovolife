<?php

declare(strict_types=1);

namespace App\Modules\Tax;

use App\Modules\Tax\Services\InvoiceGenerator;
use Illuminate\Support\ServiceProvider;

final class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceGenerator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
