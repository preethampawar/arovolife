<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\DTOs\RepurchaseVerdict;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Decides, from a distributor's repurchase cycles, whether a bonus may be paid
 * on a given date.
 *
 * Client 2026-09-06, rules 6–8: a failed repurchase cycle withholds GSB, Rank
 * Bonus, Growth Booster and Fortune. Mentorship is always paid, and ADC and
 * Awards & Rewards are outside the repurchase condition entirely. Withheld
 * income is HELD, not forfeited — it is released the moment the distributor
 * fulfils the obligation (rule 8), which is why nothing here returns BLOCKED
 * any more.
 *
 * The verdict is a function of a DATE, not of "the newest cycle row". A monthly
 * engine re-running August must reach the verdict August's run reached, and a
 * distributor who failed on 9 Aug and fulfilled on 19 Aug was held for exactly
 * those ten days. `fulfilled_on` on the cycle row is what makes that
 * answerable after the fact.
 *
 * The whole thing is gated by the {@see RepurchaseEngineFeature} flag, so when
 * the engine is off everyone is eligible and existing runs are unchanged.
 */
final class IncomeEligibilityService
{
    /** Pay the bonus normally. */
    public const ELIGIBLE = 'eligible';

    /** Calculate the bonus but do not credit it yet — released on fulfilment. */
    public const HOLD = 'hold';

    /**
     * Forfeited. No longer produced (rule 8 pays held income back); retained
     * because result rows written before that decision carry it.
     */
    public const BLOCKED = 'blocked';

    /** @var array<int, Collection<int, RepurchaseCycle>> Cycles per distributor, newest first. */
    private array $cycleCache = [];

    /**
     * Batch-load every repurchase cycle for these distributors so subsequent
     * {@see verdictAsOf()} calls skip the per-distributor query. All cycles, not
     * just the latest: the verdict for a past date may sit on an older row.
     *
     * @param  int[]  $distributorIds
     */
    public function warmCycleCache(array $distributorIds): void
    {
        if ($distributorIds === []) {
            return;
        }

        $byDistributor = RepurchaseCycle::query()
            ->whereIn('distributor_id', $distributorIds)
            ->orderByDesc('cycle_start_date')
            ->get()
            ->groupBy('distributor_id');

        foreach ($distributorIds as $id) {
            /** @var Collection<int, RepurchaseCycle> $cycles */
            $cycles = $byDistributor->get($id) ?? collect();
            $this->cycleCache[$id] = $cycles;
        }
    }

    /** Whether the repurchase engine is enabled. */
    public function engineActive(): bool
    {
        return Feature::for(null)->active(RepurchaseEngineFeature::class);
    }

    /**
     * Bonuses withheld on repurchase non-compliance (client rule 7: GSB, Rank,
     * Growth Booster and Fortune are suspended; MSB is the one of the five that
     * keeps paying). ADC and Awards & Rewards are exempt by rule 6.
     */
    public function suspends(BonusType $bonus): bool
    {
        return in_array(
            $bonus,
            [BonusType::Gsb, BonusType::Fortune, BonusType::GrowthBooster, BonusType::Rank],
            true,
        );
    }

    /**
     * Repurchase standing for $bonus on $asOf. This is a READ — it reflects the
     * cycle state maintained by the daily `repurchase:evaluate` command (the
     * sole writer), so bonus runs never mutate cycle state or fire events.
     *
     * Eligible when the engine is off, the bonus is never withheld
     * (Mentorship/ADC/Awards), or the distributor has no cycle covering $asOf
     * (pre-600-BV, or not yet evaluated → fail open, so a lagging daily command
     * can never silently withhold everyone's income).
     */
    public function verdictAsOf(int $distributorId, BonusType $bonus, Carbon $asOf): RepurchaseVerdict
    {
        if (! $this->engineActive() || ! $this->suspends($bonus)) {
            return RepurchaseVerdict::eligible();
        }

        $asOf = $asOf->copy()->startOfDay();
        $cycle = $this->cycleCovering($distributorId, $asOf);

        if ($cycle === null) {
            return RepurchaseVerdict::eligible();
        }

        // Inside its own window a cycle is never a hold: the obligation is not
        // yet due, and condition (B) is not even knowable until the last day.
        if ($asOf->lessThanOrEqualTo($cycle->due_date->copy()->startOfDay())) {
            return RepurchaseVerdict::eligible($cycle->id);
        }

        // Past the window. The distributor was held from the day after it ended
        // until the day they fulfilled — a cycle fulfilled on or before its due
        // date never held anything.
        if ($cycle->fulfilledOnTime()) {
            return RepurchaseVerdict::eligible($cycle->id);
        }

        return RepurchaseVerdict::held($cycle->failure_reason, $cycle->id);
    }

    /**
     * Repurchase standing today. Kept for callers that genuinely mean "right
     * now" — a UI badge, an ad-hoc admin read.
     */
    public function statusFor(int $distributorId, BonusType $bonus): string
    {
        return $this->verdictAsOf($distributorId, $bonus, Carbon::today())->status;
    }

    /**
     * The cycle whose window started on or before $asOf — the one that governs
     * that date. A later cycle cannot judge an earlier day, and the gap between
     * a failed window and its late fulfilment belongs to the failed cycle.
     */
    private function cycleCovering(int $distributorId, Carbon $asOf): ?RepurchaseCycle
    {
        if (array_key_exists($distributorId, $this->cycleCache)) {
            return $this->cycleCache[$distributorId]
                ->first(fn (RepurchaseCycle $c): bool => $c->cycle_start_date->copy()->startOfDay()->lessThanOrEqualTo($asOf));
        }

        return RepurchaseCycle::query()
            ->where('distributor_id', $distributorId)
            ->whereDate('cycle_start_date', '<=', $asOf->toDateString())
            ->orderByDesc('cycle_start_date')
            ->first();
    }
}
