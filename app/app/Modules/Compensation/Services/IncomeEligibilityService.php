<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Support\Carbon;
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

    public function __construct(
        private readonly RepurchaseCycleService $cycles,
    ) {}

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
     * Repurchase-driven eligibility for $bonus as of $asOf. ELIGIBLE when the
     * engine is off, the bonus is never suspended (Mentorship/Rank/ADC/Awards),
     * the distributor is not yet a Retailer, or their cycle is active/completed.
     * HOLD during grace; BLOCKED once suspended.
     */
    public function statusFor(int $distributorId, BonusType $bonus, Carbon $asOf): string
    {
        if (! $this->engineActive() || ! $this->suspends($bonus)) {
            return self::ELIGIBLE;
        }

        $cycle = $this->cycles->evaluate($distributorId, $asOf);
        if ($cycle === null) {
            return self::ELIGIBLE;
        }

        return match ($cycle->status) {
            RepurchaseCycle::STATUS_GRACE => self::HOLD,
            RepurchaseCycle::STATUS_SUSPENDED => self::BLOCKED,
            default => self::ELIGIBLE,
        };
    }
}
