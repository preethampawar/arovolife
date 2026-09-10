<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Messaging\Exceptions\MessageRefused;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageBlock;
use App\Modules\Messaging\Notifications\NewMessageNotification;
use App\Modules\Shared\Features\MessagingFeature;
use App\Modules\Shared\Rules\NoRawGovernmentId;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Laravel\Pennant\Feature;

/**
 * Canonical message-send pipeline. Every send path (tree-card menu,
 * inline chat compose, future inbox reply) MUST go through here so the
 * side effects and the policy guards always run in lock-step:
 *
 *   1. Refuse the send if policy says so — see the guard order below.
 *   2. Persist the row (canonical record + drives the bell badge query
 *      via the `to_user_id, read_at` index).
 *   3. Send the email notification (queued via NewMessageNotification).
 *   4. (Future) Fire a domain event for real-time WebSocket fan-out
 *      once Phase 2 ships Laravel Reverb.
 *
 * Splitting these into ad-hoc Controller code is the bug-magnet: a
 * "quick" send that bypasses the notification leaves the recipient
 * blind, and one that bypasses the guards leaves the channel open.
 * Always call MessageService::send().
 *
 * Guard order is cheapest-first, with one exception: the audience check runs
 * before the rate limiter so that a blocked or out-of-scope send never
 * consumes the sender's quota. Being told "no" should not cost you the
 * ability to message someone you are allowed to message.
 *
 * Staff bypass the audience, block and rate-limit guards. Compliance has to be
 * able to reach any distributor about their own account; a channel a member
 * can close against the company is not one the company can serve notice on.
 * They do NOT bypass the PAN/Aadhaar guard — hard rule 8 has no staff
 * exception, and an admin pasting a PAN into a chat is the same leak.
 */
final class MessageService
{
    public function __construct(
        private readonly MessagingSettingsService $settings,
        private readonly TeamStatsService $teamStats,
    ) {}

    public function send(User $from, User $to, string $body): Message
    {
        // for(null) pins the global scope the admin console toggles, so the
        // killswitch is not silently per-user.
        if (! Feature::for(null)->active(MessagingFeature::class)) {
            throw MessageRefused::channelClosed();
        }

        $body = trim($body);
        if ($body === '') {
            throw MessageRefused::empty();
        }

        $limit = $this->settings->maxBodyChars();
        if (mb_strlen($body) > $limit) {
            throw MessageRefused::tooLong($limit);
        }

        $staff = $from->isStaff();

        if (! $staff) {
            $this->assertWithinAudience($from, $to);
            $this->assertNotBlocked($from, $to);
        }

        $this->assertNoGovernmentId($body);

        if (! $staff) {
            $this->assertWithinRateLimits($from, $to);
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

    /**
     * May $from open a conversation with $to at all? Used by the UI to decide
     * whether to render a compose box, so that a distributor is told before
     * typing rather than after sending.
     */
    public function canMessage(User $from, User $to): bool
    {
        if ($from->id === $to->id) {
            return false;
        }

        try {
            if (! $from->isStaff()) {
                $this->assertWithinAudience($from, $to);
                $this->assertNotBlocked($from, $to);
            }
        } catch (MessageRefused) {
            return false;
        }

        return true;
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /**
     * Restrict sends to the sender's own line — their sponsor, their upline,
     * their sponsees and their Genos team.
     *
     * The MVP let anyone message any user_id, reasoning that the "Send
     * Message" action only appears on cards the sender can already see. That
     * is a UX limit, not an authorisation one: the endpoint takes a user id,
     * and ids are sequential. Enumerating the whole distributor base and
     * messaging it is the classic MLM cross-recruiting attack, and it is also
     * how one unlucky distributor receives a hundred pitches a day.
     */
    private function assertWithinAudience(User $from, User $to): void
    {
        if (! $this->settings->restrictsAudience()) {
            return;
        }

        // A distributor may always write to the company.
        if ($to->isStaff()) {
            return;
        }

        $fromDistributor = $from->distributor;
        $toDistributor = $to->distributor;

        // Fail closed: if either side has no distributor record there is no
        // line to be on, so there is nothing that permits the send.
        if ($fromDistributor === null || $toDistributor === null) {
            throw MessageRefused::outsideAudience();
        }

        if (! $this->teamStats->sharesLineage($fromDistributor, $toDistributor)) {
            throw MessageRefused::outsideAudience();
        }
    }

    private function assertNotBlocked(User $from, User $to): void
    {
        if (! $this->settings->blockListEnabled()) {
            return;
        }

        // Staff are never blockable, so a block against one is never consulted.
        if ($to->isStaff()) {
            return;
        }

        if (MessageBlock::blocks((int) $to->id, (int) $from->id)) {
            throw MessageRefused::blocked();
        }
    }

    /**
     * Two limits, because they stop two different things: the hourly cap stops
     * a broadcast across many recipients, the daily per-pair cap stops one
     * person being worn down by a single sender who is within their overall
     * quota.
     */
    private function assertWithinRateLimits(User $from, User $to): void
    {
        $perHour = $this->settings->rateLimitPerHour();
        $hourlyKey = "messaging:send:{$from->id}";

        if ($perHour > 0 && RateLimiter::tooManyAttempts($hourlyKey, $perHour)) {
            throw MessageRefused::rateLimited(RateLimiter::availableIn($hourlyKey));
        }

        $perPair = $this->settings->rateLimitPerRecipientPerDay();
        $pairKey = "messaging:send:{$from->id}:to:{$to->id}";

        if ($perPair > 0 && RateLimiter::tooManyAttempts($pairKey, $perPair)) {
            throw MessageRefused::rateLimited(RateLimiter::availableIn($pairKey));
        }

        if ($perHour > 0) {
            RateLimiter::hit($hourlyKey, 3600);
        }

        if ($perPair > 0) {
            RateLimiter::hit($pairKey, 86400);
        }
    }

    /**
     * Hard rule 8 in a channel nobody audits. A member asking their upline to
     * "check my KYC, my PAN is …" is the ordinary case, not the malicious one,
     * which is exactly why a warning under the textarea would not do.
     *
     * Deliberately not a setting. Every sibling surface applies this rule
     * unconditionally — grievance bodies, the report reason, the reviewer's
     * note — and a hard rule with an off switch is not a hard rule: the OFF
     * position writes somebody's Aadhaar to a table in plaintext, and no audit
     * trail on the settings edit undoes that. The audience restriction, the
     * block list and the length cap stay settings because they are policy;
     * this one is not.
     */
    private function assertNoGovernmentId(string $body): void
    {
        // Run through the Validator rather than calling the rule directly: the
        // rule's $fail callback is typed to return a translated string, and
        // the framework is the thing that knows how to supply one.
        $validator = Validator::make(['body' => $body], ['body' => [new NoRawGovernmentId]]);

        if ($validator->fails()) {
            throw MessageRefused::governmentIdInBody((string) $validator->errors()->first('body'));
        }
    }
}
