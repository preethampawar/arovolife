<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Admin\Events\DistributorDeactivated;
use App\Modules\Admin\Events\DistributorFrozen;
use App\Modules\Admin\Events\DistributorReactivated;
use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Admin\Events\DistributorUnfrozen;
use App\Modules\Admin\Events\KycApproved;
use App\Modules\Admin\Events\KycRejected;
use App\Modules\Admin\Listeners\SendAccountFrozenMail;
use App\Modules\Admin\Listeners\SendAccountUnfrozenMail;
use App\Modules\Admin\Listeners\SendDistributorDeactivatedMail;
use App\Modules\Admin\Listeners\SendDistributorReactivatedMail;
use App\Modules\Admin\Listeners\SendDistributorTerminatedMail;
use App\Modules\Admin\Listeners\SendKycApprovedMail;
use App\Modules\Admin\Listeners\SendKycRejectedMail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Wire the admin-triggered KYC lifecycle events to their email
        // listeners. Without these the events would fire silently and the
        // distributor would have no way to learn what happened to their KYC.
        Event::listen(KycApproved::class, SendKycApprovedMail::class);
        Event::listen(KycRejected::class, SendKycRejectedMail::class);
        Event::listen(DistributorTerminated::class, SendDistributorTerminatedMail::class);

        // Account-state changes the distributor needs to be told about:
        // freeze / unfreeze on the user account, and deactivate / reactivate
        // on the distributor record itself.
        Event::listen(DistributorFrozen::class, SendAccountFrozenMail::class);
        Event::listen(DistributorUnfrozen::class, SendAccountUnfrozenMail::class);
        Event::listen(DistributorDeactivated::class, SendDistributorDeactivatedMail::class);
        Event::listen(DistributorReactivated::class, SendDistributorReactivatedMail::class);
    }
}
