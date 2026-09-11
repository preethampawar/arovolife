<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms that the distributor's bank details changed — the "was this you?"
 * receipt for a change that redirects money. The mail channel is the one that
 * reaches a hijacked account's real owner; the database channel puts the same
 * line in the in-app bell.
 *
 * Carries only the last 4 digits of the account: an email is not a safe place
 * for a full account number.
 */
final class BankDetailsUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $accountLast4,
        public readonly string $ifsc,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife bank details were updated')
            ->view('emails.bank-details-updated', [
                'accountLast4' => $this->accountLast4,
                'ifsc' => $this->ifsc,
                'bankUrl' => url('/profile/bank'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'distributor.bank_details_updated',
            'account_last4' => $this->accountLast4,
            'ifsc' => $this->ifsc,
        ];
    }
}
