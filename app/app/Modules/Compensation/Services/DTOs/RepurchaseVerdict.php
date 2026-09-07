<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\IncomeEligibilityService;

/**
 * One distributor's repurchase standing on one date: whether their bonuses may
 * be credited, and — when they may not — which of the client's two rule-4
 * conditions failed.
 *
 * The reason is what keeps the shipped result statuses meaningful. A wallet
 * failure still reports as `repurchase_wallet_blocked` on the GSB/GBB/Rank
 * result rows; a BV shortfall reports as a plain repurchase hold. Both withhold
 * the same money and both are released together on fulfilment — the split
 * exists so an admin can see WHY a distributor was held without opening the
 * cycle table.
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

    public static function held(?string $reason, ?int $cycleId): self
    {
        return new self(IncomeEligibilityService::HOLD, $reason, $cycleId);
    }

    public function isEligible(): bool
    {
        return $this->status === IncomeEligibilityService::ELIGIBLE;
    }

    /** The hold was caused by a non-zero repurchase wallet (rule 4B). */
    public function blockedOnWallet(): bool
    {
        return in_array(
            $this->reason,
            [RepurchaseCycle::REASON_WALLET_NONZERO, RepurchaseCycle::REASON_BOTH],
            true,
        );
    }
}
