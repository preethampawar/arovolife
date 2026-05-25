<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\DistributorReactivated;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\DistributorReactivatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the distributor their distributor record is active again.
 * No admin-side mail.
 */
final class SendDistributorReactivatedMail implements ShouldQueue
{
    public function handle(DistributorReactivated $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new DistributorReactivatedNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            reactivatedAtFormatted: $event->reactivatedAt->format('d M Y H:i'),
        ));
    }
}
