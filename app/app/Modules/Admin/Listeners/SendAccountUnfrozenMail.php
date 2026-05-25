<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\DistributorUnfrozen;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\AccountUnfrozenNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the distributor the freeze on their account was lifted and that they
 * can sign in again. No admin-side mail.
 */
final class SendAccountUnfrozenMail implements ShouldQueue
{
    public function handle(DistributorUnfrozen $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new AccountUnfrozenNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            unfrozenAtFormatted: $event->unfrozenAt->format('d M Y H:i'),
        ));
    }
}
