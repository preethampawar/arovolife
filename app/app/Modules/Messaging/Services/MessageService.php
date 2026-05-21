<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Canonical message-send pipeline. Every send path (tree-card menu,
 * inline chat compose, future inbox reply) MUST go through here so the
 * three side effects always run in lock-step:
 *
 *   1. Persist the row (canonical record + drives the bell badge query
 *      via the `to_user_id, read_at` index).
 *   2. Send the email notification (queued via NewMessageNotification).
 *   3. (Future) Fire a domain event for real-time WebSocket fan-out
 *      once Phase 2 ships Laravel Reverb.
 *
 * Splitting these into ad-hoc Controller code is the bug-magnet: a
 * "quick" send that bypasses the notification leaves the recipient
 * blind. Always call MessageService::send().
 */
final class MessageService
{
    public function send(User $from, User $to, string $body): Message
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Message body cannot be empty.');
        }

        $message = Message::create([
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'body' => $body,
            'read_at' => null,
        ]);

        Notification::send($to, new NewMessageNotification($message, $from));

        return $message;
    }

    /**
     * Flip every unread message FROM $other TO $me to read_at=now().
     * Called when $me opens the chat thread with $other — analogous to
     * "mark as seen" in any chat app.
     */
    public function markThreadRead(User $me, User $other): int
    {
        return Message::query()
            ->unreadFor($me->id)
            ->where('from_user_id', $other->id)
            ->update(['read_at' => now()]);
    }
}
