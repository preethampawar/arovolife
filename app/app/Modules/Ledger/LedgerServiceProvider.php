<?php

declare(strict_types=1);

namespace App\Modules\Ledger;

use App\Modules\Ledger\Services\LedgerPoster;
use Illuminate\Support\ServiceProvider;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LedgerPoster::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
