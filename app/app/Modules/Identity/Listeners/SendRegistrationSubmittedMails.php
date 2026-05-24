<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Admin\Notifications\NewKycReviewNotification;
use App\Modules\Admin\Support\AdminNotificationRecipients;
use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\RegistrationSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Fires the welcome-and-awaiting-review email to the new distributor AND
 * the new-queue-item alert to the admin compliance team in one place.
 *
 * Skips admin-attested distributors created via /admin/distributors/create:
 * those flows send their own activation email and shouldn't double up.
 */
final class SendRegistrationSubmittedMails implements ShouldQueue
{
    public function handle(DistributorRegistered $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        // Send to the distributor themselves.
        Notification::send(
            $distributor->user,
            new RegistrationSubmittedNotification(
                adn: $distributor->adn,
                fullName: (string) $distributor->user->full_name,
            ),
        );

        // And fan out to the admin compliance team.
        $admins = AdminNotificationRecipients::compliance();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewKycReviewNotification(
                adn: $distributor->adn,
                applicantName: (string) $distributor->user->full_name,
                distributorId: (int) $distributor->id,
                isResubmission: false,
            ));
        }
    }
}
