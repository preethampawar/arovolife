<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\DistributorFrozen;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\AccountFrozenNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the distributor their account was temporarily frozen and that they
 * cannot sign in until it's reviewed. No admin-side mail.
 */
final class SendAccountFrozenMail implements ShouldQueue
{
    public function handle(DistributorFrozen $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new AccountFrozenNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            reason: $event->reason !== '' ? $event->reason : null,
            frozenAtFormatted: $event->frozenAt->format('d M Y H:i'),
        ));
    }
}
