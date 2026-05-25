<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\DistributorDeactivated;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\DistributorDeactivatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the distributor their distributor record was marked inactive and
 * points them at support to reactivate. No admin-side mail.
 */
final class SendDistributorDeactivatedMail implements ShouldQueue
{
    public function handle(DistributorDeactivated $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new DistributorDeactivatedNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            deactivatedAtFormatted: $event->deactivatedAt->format('d M Y H:i'),
        ));
    }
}
