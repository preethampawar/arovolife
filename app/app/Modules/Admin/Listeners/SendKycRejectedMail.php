<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\KycRejected;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\KycRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Rejection email. Includes the admin's reason verbatim plus a link to the
 * re-upload page so the applicant can fix and resubmit. Without this listener
 * a rejected applicant has no way to learn why or what to do next.
 */
final class SendKycRejectedMail implements ShouldQueue
{
    public function handle(KycRejected $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new KycRejectedNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            reason: $event->reason,
            rejectedAtFormatted: $event->rejectedAt->format('d M Y H:i'),
        ));
    }
}
