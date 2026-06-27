<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Support\BonusDeduction;

/**
 * Single authority for the admin charge + TDS deducted from every bonus.
 *
 * Replaces the previously-duplicated deduction math in each engine service so
 * the rules live in one place. Reads the rate, cap, TDS rate and the per-bonus
 * "applies to" toggle through CompensationPlanSettingsService (SSOT), so admins
 * can switch the admin charge on/off per bonus without code changes. Per-bonus
 * rounding/TDS-base quirks are declared on BonusType.
 */
final class BonusDeductionService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Compute the admin charge, TDS and net for a bonus gross (all paise).
     *
     * Admin charge applies only when the bonus's `applies_to` toggle is on;
     * it is min(rate × gross, cap), floored or rounded per BonusType. TDS is a
     * flat rate on either the gross or gross−admin, per BonusType. Net is
     * clamped to ≥ 0 so a misconfigured rate can never produce a negative payout.
     */
    public function for(BonusType $bonus, int $grossPaise): BonusDeduction
    {
        $adminCharge = $this->plan->adminChargeAppliesTo($bonus)
            ? $this->adminCharge($bonus, $grossPaise)
            : 0;

        $tdsBase = $bonus->tdsOnGross() ? $grossPaise : $grossPaise - $adminCharge;
        $tds = $this->plan->tds($tdsBase);

        $net = max(0, $grossPaise - $adminCharge - $tds);

        return new BonusDeduction($grossPaise, $adminCharge, $tds, $net);
    }

    /** Admin charge = min(rate × gross, cap); floored for Rank, rounded otherwise. */
    private function adminCharge(BonusType $bonus, int $grossPaise): int
    {
        $raw = $grossPaise * $this->plan->adminChargeRateBp() / 10_000;
        $charge = $bonus->adminChargeUsesFloor() ? (int) floor($raw) : (int) round($raw);

        return min($charge, $this->plan->adminChargeCapPaise());
    }
}
