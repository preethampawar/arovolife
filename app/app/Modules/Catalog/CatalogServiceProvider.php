<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Console\Commands\CopyCatalogImagesFromS3Command;
use App\Modules\Catalog\Models\ProductCategory;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            // Module commands sit outside app/Console/Commands, where nothing
            // auto-discovers them.
            $this->commands([
                CopyCatalogImagesFromS3Command::class,
            ]);
        }

        // Share active top-level categories with the storefront nav so the
        // "Categories" dropdown menu (Atomy-style) can render everywhere.
        View::composer('partials.public-topnav', function ($view): void {
            $view->with('navCategories', ProductCategory::query()
                ->where('status', ProductCategory::STATUS_ACTIVE)
                ->whereNull('parent_id')
                ->orderBy('sort')
                ->get(['id', 'slug', 'name']));
        });
    }
}
