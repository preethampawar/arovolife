<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the spouse user account created during a couple registration.
 * Contains a signed, time-limited URL that lands on the activation page
 * where the spouse sets their own password. Without this email the spouse
 * cannot log in (LoginController gates on `password_set_at IS NOT NULL`),
 * which would block them from exercising statutory rights — DPDP §6.
 */
final class SpouseActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $primaryFullName,
        public readonly string $primaryAdn,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // 30-day signed URL — long enough for a paper-based onboarding
        // workflow but bounded so a leaked link can't be used a year later.
        $url = URL::temporarySignedRoute(
            'spouse.activate.show',
            now()->addDays(30),
            ['user' => $this->userId],
        );

        return (new MailMessage)
            ->subject('Activate your arovolife co-distributor account')
            ->view('emails.spouse-activation', [
                'url'             => $url,
                'primaryFullName' => $this->primaryFullName,
                'primaryAdn'      => $this->primaryAdn,
            ]);
    }
}
