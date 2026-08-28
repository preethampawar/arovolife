<?php

declare(strict_types=1);

namespace App\Modules\Compliance\DTOs;

use Illuminate\Support\Carbon;

/**
 * Where one distributor stands against the agreement's §21 dormancy rule.
 */
final readonly class InactivityAssessment
{
    public function __construct(
        public int $distributorId,
        public ?Carbon $lastSaleAt,
        /** Effective date, or the last sale if later — whichever the clock runs from. */
        public Carbon $clockRunningFrom,
        /** The date twelve continuous months without a sale is reached. */
        public Carbon $dormantFrom,
        public bool $isDormant,
        public ?Carbon $noticeIssuedAt,
        public ?Carbon $noticeExpiresAt,
    ) {}

    public function isUnderNotice(): bool
    {
        return $this->noticeIssuedAt !== null;
    }

    public function noticeHasExpired(?Carbon $asOf = null): bool
    {
        if ($this->noticeExpiresAt === null) {
            return false;
        }

        return $this->noticeExpiresAt->lessThanOrEqualTo($asOf ?? Carbon::now());
    }

    /**
     * Days left before the account is terminated. Null when no notice is live.
     */
    public function daysLeftOnNotice(?Carbon $asOf = null): ?int
    {
        if ($this->noticeExpiresAt === null) {
            return null;
        }

        $asOf ??= Carbon::now();

        return $this->noticeExpiresAt->lessThanOrEqualTo($asOf)
            ? 0
            : (int) ceil($asOf->diffInHours($this->noticeExpiresAt) / 24);
    }
}
