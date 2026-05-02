<?php

declare(strict_types=1);

namespace App\Modules\Content;

use Illuminate\Support\ServiceProvider;

final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
