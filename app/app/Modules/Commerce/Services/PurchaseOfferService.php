<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Enums\PurchaseOfferType;
use App\Modules\Commerce\Models\MonthlyOfferProduct;
use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The two purchase offers for distributors who hold no rank (KP 2026-06-26).
 *
 *  1. **Half-price product** — a month in which the distributor is activated
 *     and repurchased the qualifying volume earns them one company-announced
 *     product at half the distributor price.
 *  2. **Redeem points** — six consecutive months at or above the qualifying
 *     volume earn 20% of the BV accumulated across those months as points,
 *     one point being one rupee off a future purchase. A second consecutive
 *     six earns another 20%, and completing twelve adds a further 10% of the
 *     whole year.
 *
 * Both hang entirely off BV the distributor personally purchased. The
 * "joining" trigger in the original description was dropped by the Product
 * Owner on 2026-08-16 and has no representation anywhere in this service:
 * an offer earned by joining would break hard rule 1 (joining is free of cost)
 * and hard rule 2 (value only from product sales).
 *
 * ⚠ Two readings of KP's text are implemented as written and need his
 * confirmation before the flag is turned on. They are recorded in the risk
 * register (R-48) rather than left in someone's memory:
 *
 *  - **"do not hold any rank"** is read as *has never qualified for a rank*.
 *    Rank achievement is permanent in this plan, so "currently unranked" and
 *    "never ranked" are the same set — but if KP meant "not ranked in the
 *    qualifying month", the population is larger and the cost is higher.
 *  - **"20% of total BV"** is read as 20% of the BV accumulated over the
 *    streak, at one point per rupee of BV. The alternative reading — 20% of
 *    the rupee value spent — gives a different and generally larger number.
 */
final class PurchaseOfferService
{
    /** @var array<string, int>|null memoised month buckets for one distributor */
    private ?array $buckets = null;

    private ?int $bucketsForDistributorId = null;

    public function __construct(
        private readonly PurchaseOfferSettings $settings,
        private readonly RedeemPointsService $points,
    ) {}

    /**
     * @return array{half_price_granted: int, points_granted: int, points_awarded: int, skipped_ranked: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();

        $halfPriceGranted = 0;
        $pointsGranted = 0;
        $pointsAwarded = 0;
        $skippedRanked = 0;

        $offerVariantId = MonthlyOfferProduct::whereDate('month_start', $monthStart->toDateString())
            ->value('product_variant_id');

        Distributor::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(200, function ($distributors) use (
                $monthStart, $offerVariantId,
                &$halfPriceGranted, &$pointsGranted, &$pointsAwarded, &$skippedRanked
            ): void {
                foreach ($distributors as $distributor) {
                    if ($this->holdsARank($distributor->id)) {
                        $skippedRanked++;

                        continue;
                    }

                    if ($this->grantHalfPrice($distributor->id, $monthStart, $offerVariantId)) {
                        $halfPriceGranted++;
                    }

                    $award = $this->grantPoints($distributor->id, $monthStart);

                    if ($award > 0) {
                        $pointsGranted++;
                        $pointsAwarded += $award;
                    }
                }
            });

        return [
            'half_price_granted' => $halfPriceGranted,
            'points_granted' => $pointsGranted,
            'points_awarded' => $pointsAwarded,
            'skipped_ranked' => $skippedRanked,
        ];
    }

    /**
     * Both offers are for distributors who hold no rank.
     *
     * Only `qualified` rows count — a rank that was voided was never held.
     */
    public function holdsARank(int $distributorId): bool
    {
        return DB::table('rank_qualifications')
            ->where('distributor_id', $distributorId)
            ->where('status', 'qualified')
            ->exists();
    }

    /**
     * The distributor's OWN purchases in one month, net of anything returned.
     *
     * Two things this has to get right, and a plain sum over
     * `bv_ledger_entries` gets both wrong.
     *
     * **Own purchases, not attributed sales.** The ledger credits a distributor
     * for retail sales to third parties as well as for what they bought
     * themselves. The published §11.2 says these offers are "earned entirely
     * from a Distributor's own product purchases", so the base is filtered to
     * `orders.self_consumption` — otherwise the plan page and the engine
     * describe two different systems.
     *
     * **Netted against the month the purchase belongs to.** A reversal is
     * written with `effective_at = now()`, so a purchase made in August and
     * returned in September lands its debit in September: August would keep
     * qualifying on a sale that came back, and September's live streak would be
     * broken by a purchase it never made. So the month is defined by which
     * ORDERS accrued in it, and every entry for those orders — accrual and
     * later reversal alike — is netted into that month. An offer earned on a
     * returned purchase is an offer earned on no sale at all, which hard rule 2
     * forbids.
     */
    public function monthlyBvPaise(int $distributorId, Carbon $monthStart): int
    {
        return $this->monthBuckets($distributorId)[$monthStart->format('Y-m')] ?? 0;
    }

    /**
     * Lifetime own-purchase BV, net of returns — what activation is measured
     * against.
     */
    public function lifetimeBvPaise(int $distributorId): int
    {
        return (int) DB::table('bv_ledger_entries')
            ->where('bv_ledger_entries.distributor_id', $distributorId)
            ->join('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
            ->where('orders.self_consumption', true)
            ->sum('bv_ledger_entries.bv_paise');
    }

    /**
     * Every month's own-purchase BV for one distributor, in two queries.
     *
     * `streakLength()` walks back twelve months and `bvOverMonths()` walks the
     * same ground again, so asking the database per month meant roughly fifty
     * round trips per distributor across a run that iterates the whole active
     * base. The month boundaries are drawn in PHP instead — the semantics are
     * identical to the per-month version, including an order whose accrual and
     * later reversal net to zero.
     *
     * Memoised for one distributor at a time. The monthly run processes them
     * sequentially, so this keeps every repeated lookup free without holding
     * the whole base in memory.
     *
     * The memo invalidates on distributor id and nothing else. No current
     * caller writes `bv_ledger_entries` — grants do not, and the service is not
     * bound as a singleton — but anything that accrues BV and then asks the
     * SAME instance for a month would read a stale figure. Resolve a fresh
     * instance after a BV write.
     *
     * @return array<string, int> 'YYYY-MM' => net own-purchase BV in paise
     */
    private function monthBuckets(int $distributorId): array
    {
        if ($this->bucketsForDistributorId === $distributorId && $this->buckets !== null) {
            return $this->buckets;
        }

        // Which of the distributor's own orders accrued, and when. An order
        // with accruals in two months belongs to both, exactly as the
        // per-month query treated it.
        $accruals = DB::table('bv_ledger_entries')
            ->join('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
            ->where('bv_ledger_entries.distributor_id', $distributorId)
            ->where('bv_ledger_entries.type', 'accrual')
            ->where('orders.self_consumption', true)
            ->get(['bv_ledger_entries.order_id', 'bv_ledger_entries.effective_at']);

        if ($accruals->isEmpty()) {
            $this->bucketsForDistributorId = $distributorId;

            return $this->buckets = [];
        }

        /** @var array<string, array<int, true>> $monthOrders */
        $monthOrders = [];
        /** @var array<int, true> $allOrderIds */
        $allOrderIds = [];

        foreach ($accruals as $row) {
            $month = Carbon::parse((string) $row->effective_at)->format('Y-m');
            $monthOrders[$month][(int) $row->order_id] = true;
            $allOrderIds[(int) $row->order_id] = true;
        }

        // The net of EVERY entry for those orders — a reversal is dated to the
        // refund, so it must be pulled back to the month the purchase belongs
        // to rather than counted where it landed.
        $netByOrder = DB::table('bv_ledger_entries')
            ->where('distributor_id', $distributorId)
            ->whereIn('order_id', array_keys($allOrderIds))
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(bv_paise) as net_paise')
            ->pluck('net_paise', 'order_id');

        $buckets = [];

        foreach ($monthOrders as $month => $orderIds) {
            $total = 0;

            foreach (array_keys($orderIds) as $orderId) {
                $total += (int) ($netByOrder[$orderId] ?? 0);
            }

            $buckets[$month] = $total;
        }

        $this->bucketsForDistributorId = $distributorId;

        return $this->buckets = $buckets;
    }

    /**
     * How many consecutive months up to and including this one met the
     * qualifying volume. Zero if this month did not.
     *
     * Capped at the cycle length times two, because nothing in the offer needs
     * to know about a longer run and an unbounded loop over a distributor with
     * years of history would read the whole ledger to learn nothing.
     */
    public function streakLength(int $distributorId, Carbon $monthStart): int
    {
        $threshold = $this->settings->qualifyingBvPaise();
        $maxLookback = $this->settings->cycleMonths() * 2;
        $streak = 0;

        for ($back = 0; $back < $maxLookback; $back++) {
            $cursor = $monthStart->copy()->subMonths($back);

            if ($this->monthlyBvPaise($distributorId, $cursor) < $threshold) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * Grant the half-price product for a qualifying month.
     *
     * Needs three things: the distributor is activated (lifetime BV at or above
     * the activation threshold), they repurchased the qualifying volume this
     * month, and the company actually announced a product. No announcement, no
     * grant — an entitlement to an unnamed product is not an entitlement.
     */
    public function grantHalfPrice(int $distributorId, Carbon $monthStart, ?int $offerVariantId): bool
    {
        if ($offerVariantId === null) {
            return false;
        }

        if ($this->lifetimeBvPaise($distributorId) < $this->settings->activationBvPaise()) {
            return false;
        }

        $monthlyBv = $this->monthlyBvPaise($distributorId, $monthStart);

        if ($monthlyBv < $this->settings->qualifyingBvPaise()) {
            return false;
        }

        if ($this->alreadyGranted($distributorId, PurchaseOfferType::HalfPriceProduct, $monthStart)) {
            return false;
        }

        PurchaseOfferGrant::create([
            'distributor_id' => $distributorId,
            'offer_type' => PurchaseOfferType::HalfPriceProduct,
            'month_start' => $monthStart->toDateString(),
            'qualifying_bv_paise' => $monthlyBv,
            'streak_months' => 1,
            'product_variant_id' => $offerVariantId,
            'status' => PurchaseOfferGrant::STATUS_GRANTED,
            // The offer is for the month it was announced in. Letting it run
            // indefinitely would turn a monthly promotion into a permanent
            // discount nobody budgeted for.
            'expires_on' => $monthStart->copy()->addMonths($this->settings->halfPriceValidityMonths())->endOfMonth()->toDateString(),
        ]);

        return true;
    }

    /**
     * Award points when a streak completes a cycle.
     *
     * @return int points awarded, 0 if this month completes nothing
     */
    public function grantPoints(int $distributorId, Carbon $monthStart): int
    {
        $cycle = $this->settings->cycleMonths();
        $streak = $this->streakLength($distributorId, $monthStart);

        if ($streak === 0 || $streak % $cycle !== 0) {
            return 0;
        }

        if ($this->alreadyGranted($distributorId, PurchaseOfferType::RedeemPoints, $monthStart)) {
            return 0;
        }

        // The cycle just completed: the last `$cycle` months.
        $cycleBv = $this->bvOverMonths($distributorId, $monthStart, $cycle);
        $points = $this->pointsFor($cycleBv, $this->settings->cycleRateBp());

        // Completing the full year adds a further percentage of the whole
        // twelve months — not of the second half again.
        if ($streak >= $cycle * 2) {
            $yearBv = $this->bvOverMonths($distributorId, $monthStart, $cycle * 2);
            $points += $this->pointsFor($yearBv, $this->settings->fullYearBonusRateBp());
        }

        if ($points <= 0) {
            return 0;
        }

        DB::transaction(function () use ($distributorId, $monthStart, $streak, $cycleBv, $points): void {
            $grant = PurchaseOfferGrant::create([
                'distributor_id' => $distributorId,
                'offer_type' => PurchaseOfferType::RedeemPoints,
                'month_start' => $monthStart->toDateString(),
                'qualifying_bv_paise' => $cycleBv,
                'streak_months' => $streak,
                'points_awarded' => $points,
                'status' => PurchaseOfferGrant::STATUS_CONSUMED,
                'consumed_at' => Carbon::now(),
            ]);

            $this->points->accrue(
                distributorId: $distributorId,
                points: $points,
                referenceType: 'purchase_offer_grant',
                referenceId: $grant->id,
                memo: $streak.'-month purchase streak to '.$monthStart->format('M Y'),
            );
        });

        return $points;
    }

    /** Signed BV over the last `$months` months, inclusive of `$monthStart`. */
    public function bvOverMonths(int $distributorId, Carbon $monthStart, int $months): int
    {
        $total = 0;

        for ($back = 0; $back < $months; $back++) {
            $total += $this->monthlyBvPaise($distributorId, $monthStart->copy()->subMonths($back));
        }

        return $total;
    }

    /**
     * Points for a BV total at a given rate.
     *
     * BV is stored in paise at 100 paise to the BV, and one point is one rupee
     * of BV — so the divisor carries both conversions.
     */
    public function pointsFor(int $bvPaise, int $rateBp): int
    {
        if ($bvPaise <= 0) {
            return 0;
        }

        return intdiv($bvPaise * $rateBp, 10_000 * 100);
    }

    private function alreadyGranted(int $distributorId, PurchaseOfferType $type, Carbon $monthStart): bool
    {
        return PurchaseOfferGrant::where('distributor_id', $distributorId)
            ->where('offer_type', $type->value)
            ->whereDate('month_start', $monthStart->toDateString())
            ->exists();
    }
}
