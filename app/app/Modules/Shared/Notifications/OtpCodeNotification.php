<?php

declare(strict_types=1);

namespace App\Modules\Shared\Notifications;

use App\Modules\Shared\Otp\OtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic OTP delivery email — reusable by any feature that issues a code via
 * {@see OtpService}. The code is passed in (never read
 * from storage) and is not logged.
 */
final class OtpCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $code  the 6-digit code to show
     * @param  string  $action  human phrase, e.g. "update your contact details"
     * @param  int  $expiresMinutes  validity window for the copy
     */
    public function __construct(
        public readonly string $code,
        public readonly string $action,
        public readonly int $expiresMinutes = 10,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife verification code')
            ->greeting('Verify it\'s you')
            ->line('Use this code to '.$this->action.':')
            ->line('**'.$this->code.'**')
            ->line('This code expires in '.$this->expiresMinutes.' minutes.')
            ->line('If you didn\'t request this, you can safely ignore this email and your details stay unchanged.');
    }
}
