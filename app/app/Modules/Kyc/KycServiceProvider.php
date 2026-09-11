<?php

declare(strict_types=1);

namespace App\Modules\Kyc;

use App\Modules\Kyc\Console\Commands\EncryptDocumentsCommand;
use App\Modules\Kyc\Console\Commands\PurgeExpiredDocumentsCommand;
use Illuminate\Support\ServiceProvider;

final class KycServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            // Module commands sit outside app/Console/Commands, where nothing
            // auto-discovers them.
            $this->commands([
                PurgeExpiredDocumentsCommand::class,
                EncryptDocumentsCommand::class,
            ]);
        }
    }
}
