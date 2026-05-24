<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Listeners;

use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Notifications\NewDirectReferralNotification;
use App\Modules\Genealogy\Notifications\NewPlacementUnderYouNotification;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * On every successful placement, send two emails:
 *
 *   1. To the placement parent (binary-tree parent) — "a new distributor
 *      was added to your L/R leg"
 *   2. To the sponsor (sponsorship-tree parent) — "your direct referral
 *      registered" — but ONLY when sponsor and placement parent are
 *      different people; if they're the same person, the placement email
 *      already covers it.
 *
 * Both emails are suppressed for the reserved root node (the company's own
 * Arovolife Private Limited row) — that account is a placeholder, not a
 * real human inbox.
 */
final class SendPlacementCreatedMails implements ShouldQueue
{
    public function handle(PlacementCreated $event): void
    {
        $newJoiner = Distributor::query()
            ->with('user')
            ->find($event->result->distributorId);
        if ($newJoiner === null || $newJoiner->user === null) {
            return;
        }

        $placedAt = Carbon::now()->format('d M Y H:i');

        // Placement parent notification.
        $placementParent = Distributor::query()
            ->with('user')
            ->find($event->placementId);

        if ($placementParent !== null
            && $placementParent->user !== null
            && ! ReservedAdns::isReserved($placementParent->adn)
        ) {
            Notification::send($placementParent->user, new NewPlacementUnderYouNotification(
                parentFullName: (string) ($placementParent->user->full_name ?? 'Distributor'),
                parentAdn: $placementParent->adn,
                newJoinerFullName: (string) ($newJoiner->user->full_name ?? 'New distributor'),
                newJoinerAdn: $newJoiner->adn,
                side: $event->result->side,
                sideChosenBy: $event->result->sideChosenBy,
                placedAtFormatted: $placedAt,
            ));
        }

        // Sponsor notification — only when sponsor != placement parent AND
        // sponsor is a real distributor. The wizard pins sponsor and
        // placement to the same node by default; they diverge on deeper
        // trees where the sponsor explicitly places the new joiner below a
        // descendant of theirs.
        if ($event->sponsorId !== $event->placementId) {
            $sponsor = Distributor::query()
                ->with('user')
                ->find($event->sponsorId);

            if ($sponsor !== null
                && $sponsor->user !== null
                && ! ReservedAdns::isReserved($sponsor->adn)
            ) {
                Notification::send($sponsor->user, new NewDirectReferralNotification(
                    sponsorFullName: (string) ($sponsor->user->full_name ?? 'Sponsor'),
                    sponsorAdn: $sponsor->adn,
                    referralFullName: (string) ($newJoiner->user->full_name ?? 'New distributor'),
                    referralAdn: $newJoiner->adn,
                    registeredAtFormatted: $placedAt,
                ));
            }
        }
    }
}
