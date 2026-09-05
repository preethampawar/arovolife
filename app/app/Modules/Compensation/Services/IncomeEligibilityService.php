<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\RepurchaseMonthlySnapshot;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Laravel\Pennant\Feature;

/**
 * Decides, from a distributor's repurchase cycle, whether a bonus may be paid.
 *
 * KP 2026-06-28: a missed repurchase suspends GSB, Fortune and Growth Booster
 * only — Mentorship and Rank are NEVER suspended. During the grace window income
 * is calculated but held; after grace it is blocked (forfeited) until the
 * distributor completes their repurchase. The whole thing is gated by the
 * {@see RepurchaseEngineFeature} flag, so when the engine is off everyone is
 * eligible and existing runs are unchanged.
 */
final class IncomeEligibilityService
{
    /** Pay the bonus normally. */
    public const ELIGIBLE = 'eligible';

    /** Grace window — calculate the bonus but do not credit it yet. */
    public const HOLD = 'hold';

    /** Suspended — do not credit (forfeited until repurchase is completed). */
    public const BLOCKED = 'blocked';

    /** @var array<int,?RepurchaseCycle> Latest cycle per distributor (null = no cycle yet). */
    private array $cycleCache = [];

    /**
     * Batch-load the latest repurchase cycle for each distributor so
     * subsequent {@see statusFor()} calls skip the per-distributor query.
     *
     * @param  int[]  $distributorIds
     */
    public function warmCycleCache(array $distributorIds): void
    {
        if ($distributorIds === []) {
            return;
        }

        $byCycle = RepurchaseCycle::query()
            ->whereIn('distributor_id', $distributorIds)
            ->orderByDesc('cycle_start_date')
            ->get()
            ->groupBy('distributor_id');

        foreach ($distributorIds as $id) {
            $this->cycleCache[$id] = $byCycle->get($id)?->first();
        }
    }

    /** Whether the repurchase engine is enabled. */
    public function engineActive(): bool
    {
        return Feature::for(null)->active(RepurchaseEngineFeature::class);
    }

    /** Bonuses suspended on repurchase non-compliance (KP: GSB / Fortune / GBB). */
    public function suspends(BonusType $bonus): bool
    {
        return in_array($bonus, [BonusType::Gsb, BonusType::Fortune, BonusType::GrowthBooster], true);
    }

    /**
     * Repurchase-driven eligibility for $bonus. This is a READ — it reflects the
     * distributor's latest cycle status as maintained by the daily
     * repurchase:evaluate command (the sole writer), so bonus runs never mutate
     * cycle state or fire events. ELIGIBLE when the engine is off, the bonus is
     * never suspended (Mentorship/Rank/ADC/Awards), the distributor has no cycle
     * yet (pre-Retailer or not-yet-evaluated → fail-open), or their cycle is
     * active/completed. HOLD during grace; BLOCKED once suspended.
     */
    public function statusFor(int $distributorId, BonusType $bonus): string
    {
        if (! $this->engineActive() || ! $this->suspends($bonus)) {
            return self::ELIGIBLE;
        }

        $cycle = array_key_exists($distributorId, $this->cycleCache)
            ? $this->cycleCache[$distributorId]
            : RepurchaseCycle::query()
                ->where('distributor_id', $distributorId)
                ->orderByDesc('cycle_start_date')
                ->first();

        if ($cycle === null) {
            return self::ELIGIBLE;
        }

        return match ($cycle->status) {
            RepurchaseCycle::STATUS_GRACE => self::HOLD,
            RepurchaseCycle::STATUS_SUSPENDED => self::BLOCKED,
            default => self::ELIGIBLE,
        };
    }

    /**
     * Whether the distributor's repurchase wallet stood at ₹0 at the end of
     * $cycleMonth, read off the frozen month-end snapshot rather than the live
     * balance so a re-run of a month's engine can never reach a different
     * verdict than the run that paid it.
     *
     * @param  string  $cycleMonth  first day of the month being judged (YYYY-MM-DD)
     */
    public function repurchaseWalletZeroedForMonth(int $distributorId, string $cycleMonth): bool
    {
        $snapshot = RepurchaseMonthlySnapshot::query()
            ->where('distributor_id', $distributorId)
            ->whereDate('cycle_month', $cycleMonth)
            ->first();

        // Fail open: a month with no snapshot (the command has not run yet, or
        // the month predates it) must not silently withhold everyone's income.
        return $snapshot === null || $snapshot->was_zeroed;
    }
}
