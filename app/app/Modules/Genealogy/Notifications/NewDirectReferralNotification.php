<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a sponsor that a new direct referral has joined under their name.
 * Sponsorship is the horizontal-tree relationship and earns the sponsor
 * referral credit regardless of where the placement landed in the binary
 * tree (DSR Rule 5).
 *
 * Sent only when sponsor != placement parent — otherwise the placement-
 * parent email already conveys the same person. The listener handles that
 * dedup.
 */
final class NewDirectReferralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $sponsorFullName,
        public readonly string $sponsorAdn,
        public readonly string $referralFullName,
        public readonly string $referralAdn,
        public readonly string $registeredAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New direct referral — ADN {$this->referralAdn}")
            ->view('emails.new-direct-referral', [
                'sponsorFullName' => $this->sponsorFullName,
                'sponsorAdn' => $this->sponsorAdn,
                'referralFullName' => $this->referralFullName,
                'referralAdn' => $this->referralAdn,
                'registeredAtFormatted' => $this->registeredAtFormatted,
                'referralsUrl' => url('/tree/sponsorship'),
            ]);
    }
}
