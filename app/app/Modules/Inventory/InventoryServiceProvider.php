<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Inventory\Console\Commands\BackfillOpeningStockCommand;
use App\Modules\Inventory\Console\Commands\VerifyStockLedgerCommand;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventorySettings::class);
        $this->app->singleton(StockLedger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillOpeningStockCommand::class,
                VerifyStockLedgerCommand::class,
            ]);
        }
    }
}
