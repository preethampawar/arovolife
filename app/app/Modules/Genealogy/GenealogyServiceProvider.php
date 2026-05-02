<?php

declare(strict_types=1);

namespace App\Modules\Genealogy;

use Illuminate\Support\ServiceProvider;

final class GenealogyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
