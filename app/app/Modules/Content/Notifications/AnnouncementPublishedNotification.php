<?php

declare(strict_types=1);

namespace App\Modules\Content\Notifications;

use App\Modules\Content\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails one published announcement to one distributor.
 *
 * Queued, because publishing to a large audience must not hold the admin's
 * request open, and a failed send must not fail the publish — the in-app copy
 * is the announcement; the email is a courtesy copy of it.
 *
 * The body is sent as the plain text the administrator typed. It is never
 * rendered as HTML: an announcement is authored in a textarea by someone who
 * is not writing markup, and treating that input as markup is how an admin
 * account becomes an XSS vector against every distributor at once.
 */
final class AnnouncementPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Announcement $announcement) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->announcement->title)
            ->greeting('From arovolife');

        foreach (preg_split('/\R{2,}/', trim($this->announcement->body)) ?: [] as $paragraph) {
            $message->line($paragraph);
        }

        return $message
            ->action('Read it in your account', route('announcements.show', ['announcement' => $this->announcement->id]))
            ->salutation('— arovolife');
    }
}
