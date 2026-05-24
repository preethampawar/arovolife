<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Events\KycApproved;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Notifications\KycApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Welcome-aboard email after admin approves a KYC submission. No admin-side
 * mail — the approver just performed the action and the audit log captures it.
 */
final class SendKycApprovedMail implements ShouldQueue
{
    public function handle(KycApproved $event): void
    {
        $distributor = Distributor::query()->with('user')->find($event->distributorId);
        if ($distributor === null || $distributor->user === null) {
            return;
        }

        Notification::send($distributor->user, new KycApprovedNotification(
            adn: $distributor->adn,
            fullName: (string) $distributor->user->full_name,
            approvedAtFormatted: $event->verifiedAt->format('d M Y H:i'),
        ));
    }
}
