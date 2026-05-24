<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Admin\Events\KycApproved;
use App\Modules\Admin\Events\KycRejected;
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
    }
}
