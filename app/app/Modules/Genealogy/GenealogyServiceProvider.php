<?php

declare(strict_types=1);

namespace App\Modules\Genealogy;

use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Listeners\SendLineChangeDecidedMails;
use App\Modules\Genealogy\Listeners\SendLineChangeRequestedMails;
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

        // Line-change emails. The request listener is a single-handler class;
        // the decision listener exposes two handlers (handleApproved /
        // handleRejected) so each is wired to its event explicitly. This app
        // wires genealogy listeners here (not via auto-discovery), matching
        // SendPlacementCreatedMails above.
        Event::listen(LineChangeRequested::class, SendLineChangeRequestedMails::class);
        Event::listen(LineChangeApproved::class, [SendLineChangeDecidedMails::class, 'handleApproved']);
        Event::listen(LineChangeRejected::class, [SendLineChangeDecidedMails::class, 'handleRejected']);
    }
}
