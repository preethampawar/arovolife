<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to a distributor an admin registered at /admin/distributors/create.
 * Carries the same signed set-password link as {@see SpouseActivationNotification}
 * (spouse.activate.show), but addresses them as the account holder, and it
 * always states that joining is free (hard rule 1) and that the 30-day
 * cooling-off has begun (hard rule 5), whether or not the admin also sent
 * the optional welcome letter.
 */
final class AccountActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $fullName,
        public readonly string $adn,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Same 30-day window as the spouse link: long enough for a
        // paper-based onboarding, bounded so a leaked link expires.
        $url = URL::temporarySignedRoute(
            'spouse.activate.show',
            now()->addDays(30),
            ['user' => $this->userId],
        );

        return (new MailMessage)
            ->subject('Activate your arovolife account')
            ->view('emails.account-activation', [
                'url' => $url,
                'fullName' => $this->fullName,
                'adn' => $this->adn,
            ]);
    }
}
