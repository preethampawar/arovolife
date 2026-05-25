<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Listeners;

use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Notifications\LineChangeApprovedNotification;
use App\Modules\Genealogy\Notifications\LineChangeRejectedNotification;
use App\Modules\Genealogy\Notifications\NewPlacementUnderYouNotification;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Emails on a line-change decision. Two named handlers (handleApproved /
 * handleRejected) — wired explicitly in GenealogyServiceProvider; they
 * are NOT auto-discovered by Laravel.
 */
final class SendLineChangeDecidedMails implements ShouldQueue
{
    public function handleApproved(LineChangeApproved $event): void
    {
        $requester = Distributor::query()->with('user')->find($event->distributorId);
        $newParent = Distributor::query()->with('user')->find($event->newPlacementParentId);
        if ($requester === null) {
            return;
        }

        if ($requester->user !== null) {
            Notification::send($requester->user, new LineChangeApprovedNotification(
                requesterAdn: $requester->adn,
                newParentAdn: $newParent?->adn ?? '—',
                side: $event->chosenSide,
            ));
        }

        // New placement parent — mirrors SendPlacementCreatedMails. Skip the
        // reserved company root.
        if ($newParent !== null && $newParent->user !== null && ! ReservedAdns::isReserved($newParent->adn)) {
            Notification::send($newParent->user, new NewPlacementUnderYouNotification(
                parentFullName: (string) ($newParent->user->full_name ?? 'Distributor'),
                parentAdn: $newParent->adn,
                newJoinerFullName: (string) ($requester->user?->full_name ?? 'Distributor'),
                newJoinerAdn: $requester->adn,
                side: $event->chosenSide,
                sideChosenBy: 'referral_explicit',
                placedAtFormatted: Carbon::now()->format('d M Y H:i'),
            ));
        }
    }

    public function handleRejected(LineChangeRejected $event): void
    {
        $requester = Distributor::query()->with('user')->find($event->distributorId);
        if ($requester === null || $requester->user === null) {
            return;
        }

        Notification::send($requester->user, new LineChangeRejectedNotification(
            requesterAdn: $requester->adn,
            decisionNote: $event->decisionNote,
        ));
    }
}
