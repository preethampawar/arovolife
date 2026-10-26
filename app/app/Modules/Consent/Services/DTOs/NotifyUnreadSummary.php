<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services\DTOs;

use Illuminate\Support\Carbon;

/**
 * What one `plan:notify-unread` run found and did, plus the earliest date the
 * current plan text may lawfully take effect (plan page §12): no earlier than
 * 33 days after the notice started, and at least 30 days after the last
 * notice email was sent. It counts only distributors the notice reached:
 * `neverReached` must be 0 (or each one covered by the risk acceptance in
 * R-123) before a start date is written on the page.
 */
final class NotifyUnreadSummary
{
    /**
     * @param  list<string>  $failedAdns
     */
    public function __construct(
        public readonly ?string $label,
        public readonly ?Carbon $noticeStartedAt,
        public readonly ?Carbon $emailsDueFrom,
        public readonly int $notAccepted,
        public readonly int $accepted,
        public readonly int $emailedNow,
        public readonly int $emailedEver,
        public readonly array $failedAdns,
        public readonly int $skippedNoUser,
        public readonly int $reservedNotAccepted,
        public readonly ?Carbon $lastEmailedAt,
        public readonly int $neverReached = 0,
        public readonly ?string $blockedReason = null,
    ) {}

    public static function idle(?string $blockedReason = null): self
    {
        return new self(null, null, null, 0, 0, 0, 0, [], 0, 0, null, 0, $blockedReason);
    }

    /** The first whole day the change may start on, or null while no notice has started. */
    public function earliestStart(): ?Carbon
    {
        if ($this->noticeStartedAt === null) {
            return null;
        }

        $earliest = self::nextWholeDay($this->noticeStartedAt->copy()->addDays(33));

        if ($this->lastEmailedAt !== null) {
            $afterEmail = self::nextWholeDay($this->lastEmailedAt->copy()->addDays(30));

            if ($afterEmail->greaterThan($earliest)) {
                $earliest = $afterEmail;
            }
        }

        return $earliest;
    }

    /** The start of the day at or after the given moment, so no part-day counts as a day. */
    private static function nextWholeDay(Carbon $moment): Carbon
    {
        return $moment->equalTo($moment->copy()->startOfDay())
            ? $moment
            : $moment->startOfDay()->addDay();
    }
}
