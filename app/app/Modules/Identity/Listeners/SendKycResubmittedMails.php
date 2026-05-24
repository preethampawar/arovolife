<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Admin\Notifications\NewKycReviewNotification;
use App\Modules\Admin\Support\AdminNotificationRecipients;
use App\Modules\Identity\Events\KycResubmitted;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\KycResubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Confirmation to the distributor + queue-alert to the admin team after a
 * rejected applicant uploads replacement documents.
 */
final class SendKycResubmittedMails implements ShouldQueue
{
    public function handle(KycResubmitted $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new KycResubmittedNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            documentTypes: $event->documentTypes,
            resubmittedAtFormatted: $event->resubmittedAt->format('d M Y H:i'),
        ));

        $admins = AdminNotificationRecipients::compliance();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewKycReviewNotification(
                adn: $distributor->adn,
                applicantName: (string) $distributor->user->full_name,
                distributorId: (int) $distributor->id,
                isResubmission: true,
            ));
        }
    }
}
