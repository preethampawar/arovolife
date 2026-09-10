<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Modules\Content\Console\Commands\PublishContentPageCommand;
use App\Modules\Content\Services\AnnouncementService;
use App\Modules\Content\Services\AnnouncementSettingsService;
use Illuminate\Support\ServiceProvider;

final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnnouncementSettingsService::class);
        $this->app->singleton(AnnouncementService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PublishContentPageCommand::class]);
        }
    }
}
