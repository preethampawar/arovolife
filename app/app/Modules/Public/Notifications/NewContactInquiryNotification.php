<?php

declare(strict_types=1);

namespace App\Modules\Public\Notifications;

use App\Modules\Public\Models\ContactInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued notification routed to the support inbox whenever a contact form
 * submission arrives. Routing is configured at dispatch time via
 * `Notification::route('mail', config('arovolife.support_email'))`.
 *
 * PII handling — only the inquiry id is serialised onto the queue payload;
 * the actual personal data is re-fetched at delivery time. This keeps name,
 * email, phone, address and message out of the `jobs` table even when the
 * default database queue driver is in use (CLAUDE.md PII rule + DPDP §8(3)).
 *
 * Render — uses the branded `emails.contact-inquiry` Blade template
 * (table-based, inline styles, > 80% HTML compatibility check) instead of
 * Laravel's default markdown chain (which scores ~35% in HTML Check).
 */
final class NewContactInquiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $inquiryId) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inquiry = ContactInquiry::query()->find($this->inquiryId);

        if ($inquiry === null) {
            return (new MailMessage)
                ->subject('Contact form submission #'.$this->inquiryId)
                ->line('Inquiry record is no longer available (purged or deleted).');
        }

        $reasonLabel = match ($inquiry->reason) {
            'referral_link_required' => 'Tried to register without a referral link',
            'invalid_referral_link' => 'Referral link could not be verified',
            'placement_taken' => 'Placement slot was claimed during registration — needs admin reassignment',
            'join_us' => 'Used the public "Register with us" link',
            'general' => 'General contact form',
            default => 'No reason recorded',
        };

        $adminUrl = url('/admin/contact-inquiries/'.$inquiry->id);

        return (new MailMessage)
            ->subject('New contact form submission — '.$inquiry->purpose)
            ->view('emails.contact-inquiry', [
                'inquiry' => $inquiry,
                'reasonLabel' => $reasonLabel,
                'adminUrl' => $adminUrl,
            ]);
    }
}
