<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\AccountTerminatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Final-state notification. Distributor is told their account is closed and
 * pointed at support if they believe it's an error. No admin-side mail.
 */
final class SendDistributorTerminatedMail implements ShouldQueue
{
    public function handle(DistributorTerminated $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new AccountTerminatedNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            reason: $event->reason,
            terminatedAtFormatted: $event->terminatedAt->format('d M Y H:i'),
        ));
    }
}
