<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the placement parent that a new distributor has been added beneath
 * them in the binary tree, on the left or right leg, with the new joiner's
 * ADN and full name. Sent the moment PlacementEngine commits the placement,
 * before the admin has approved KYC — the recipient is the *binary-tree
 * parent*, who needs immediate visibility for tree balancing decisions.
 *
 * Distinct from NewDirectReferralNotification (sent to the sponsor). The
 * sponsor and placement parent are the same person on shallow trees; the
 * notifications are split to keep their copy honest in the deeper-tree
 * case where they diverge.
 */
final class NewPlacementUnderYouNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $parentFullName,
        public readonly string $parentAdn,
        public readonly string $newJoinerFullName,
        public readonly string $newJoinerAdn,
        public readonly string $side,           // 'L' or 'R'
        public readonly string $sideChosenBy,   // 'referral_explicit', 'referral_default', etc.
        public readonly string $placedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sideLabel = $this->side === 'L' ? 'left' : 'right';

        return (new MailMessage)
            ->subject("New distributor placed on your {$sideLabel} leg — ADN {$this->newJoinerAdn}")
            ->view('emails.new-placement-under-you', [
                'parentFullName' => $this->parentFullName,
                'parentAdn' => $this->parentAdn,
                'newJoinerFullName' => $this->newJoinerFullName,
                'newJoinerAdn' => $this->newJoinerAdn,
                'side' => $this->side,
                'sideLabel' => $sideLabel,
                'sideChosenBy' => $this->sideChosenBy,
                'placedAtFormatted' => $this->placedAtFormatted,
                'treeUrl' => url('/tree'),
            ]);
    }
}
