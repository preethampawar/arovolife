<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\IncomeEligibilityService;

/**
 * One distributor's repurchase standing on one date: whether the day counts,
 * and — when it does not — which of the client's two cycle conditions failed.
 *
 * The reason is what keeps the shipped result statuses meaningful. A wallet
 * failure reports as `repurchase_wallet_blocked` on the result rows; a BV
 * shortfall reports as a plain repurchase forfeit. Both lose the same money —
 * the split exists so an admin can see WHY a day was forfeited without opening
 * the cycle table.
 */
final readonly class RepurchaseVerdict
{
    private function __construct(
        public string $status,
        public ?string $reason,
        public ?int $cycleId,
    ) {}

    public static function eligible(?int $cycleId = null): self
    {
        return new self(IncomeEligibilityService::ELIGIBLE, null, $cycleId);
    }

    public static function forfeited(?string $reason, ?int $cycleId): self
    {
        return new self(IncomeEligibilityService::FORFEITED, $reason, $cycleId);
    }

    public function isEligible(): bool
    {
        return $this->status === IncomeEligibilityService::ELIGIBLE;
    }

    /** The forfeit was caused by a non-zero repurchase wallet (condition B). */
    public function blockedOnWallet(): bool
    {
        return in_array(
            $this->reason,
            [RepurchaseCycle::REASON_WALLET_NONZERO, RepurchaseCycle::REASON_BOTH],
            true,
        );
    }
}
