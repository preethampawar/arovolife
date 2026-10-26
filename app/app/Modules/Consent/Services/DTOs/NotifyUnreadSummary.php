<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services\DTOs;

use Illuminate\Support\Carbon;

/**
 * What one `plan:notify-unread` run found and did. A change takes effect from
 * the start of the day after publication (IST), and for weekly/monthly bonuses
 * from the next period (plan page §12, client requirement 2026-10-26), so these counts
 * are the record of who was told: `neverReached` lists those the email has
 * not reached and who have not agreed.
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
}
