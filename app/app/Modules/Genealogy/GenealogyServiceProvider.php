<?php

declare(strict_types=1);

namespace App\Modules\Genealogy;

use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Listeners\SendPlacementCreatedMails;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class GenealogyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Notify the binary-tree parent ("a distributor was placed on your
        // L/R leg") and the sponsor ("your direct referral registered")
        // every time the PlacementEngine commits a new placement.
        Event::listen(PlacementCreated::class, SendPlacementCreatedMails::class);
    }
}
