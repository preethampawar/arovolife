<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Email a recipient when they receive a new direct message.
 *
 * The in-app notification (bell badge + /messages page) is NOT this
 * notification — it's driven by the persisted `messages` row directly,
 * so we don't double-write the database-channel notification. This
 * class is mail-only.
 */
final class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Message $message,
        private readonly User $sender,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $senderName = $this->sender->full_name ?: explode('@', (string) $this->sender->email)[0];
        $threadUrl = URL::route('messages.show', ['user' => $this->sender->id]);

        // Truncate the preview body in the email — the email is a nudge,
        // not the canonical surface for reading the full message.
        $preview = mb_strimwidth($this->message->body, 0, 300, '…');

        return (new MailMessage)
            ->subject(sprintf('New message from %s', $senderName))
            ->greeting('Hello,')
            ->line(sprintf('%s sent you a new message on arovolife.', $senderName))
            ->line('Message:')
            ->line($preview)
            ->action('Open conversation', $threadUrl)
            ->line('You can also view all your messages from the bell icon in the top navigation.');
    }
}
