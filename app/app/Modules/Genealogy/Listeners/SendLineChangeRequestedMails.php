<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Listeners;

use App\Modules\Admin\Support\AdminNotificationRecipients;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Notifications\LineChangeRequestedAdminNotification;
use App\Modules\Genealogy\Notifications\LineChangeRequestedRequesterNotification;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * On a new line-change request, email every admin reviewer AND the requester.
 */
final class SendLineChangeRequestedMails implements ShouldQueue
{
    public function handle(LineChangeRequested $event): void
    {
        $requester = Distributor::query()->with('user')->find($event->distributorId);
        $target = Distributor::query()->find($event->toPlacementParentId);
        if ($requester === null || $target === null) {
            return;
        }

        $request = LineChangeRequest::find($event->requestId);
        $reason = $request?->reason;

        // Requester confirmation.
        if ($requester->user !== null) {
            Notification::send($requester->user, new LineChangeRequestedRequesterNotification(
                requesterAdn: $requester->adn,
                targetParentAdn: $target->adn,
            ));
        }

        // Admin reviewers.
        $admins = AdminNotificationRecipients::lineChangeReviewers();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new LineChangeRequestedAdminNotification(
                requestId: $event->requestId,
                requesterAdn: $requester->adn,
                targetParentAdn: $target->adn,
                reason: $reason,
            ));
        }
    }
}
