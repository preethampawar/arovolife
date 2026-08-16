<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Compensation\Models\FranchiseCommissionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Franchise commission engine.
 *
 * Earning: a configurable rate (default 3%) of the **product value of the
 * orders that franchise fulfilled** in the month — Product Owner decision
 * 2026-08-16, taken over the alternative reading of 3% of the operator's
 * lifetime BV. It is also the compliance-safe reading: the payment attaches to
 * identified product sales, which is what hard rule 2 requires, whereas paying
 * on accumulated volume would reward holding a position rather than doing the
 * fulfilment work.
 *
 * Base: `subtotal − discount`, summed over the month's fulfilled orders.
 * GST is excluded because it is tax collected for the government, not company
 * revenue to share; shipping is excluded because it is a pass-through cost.
 * Paying 3% of either would be paying commission on money the company never
 * earned.
 *
 * Paid to: the operating distributor. The company's own primary franchise has
 * no operator and earns nothing.
 *
 * Idempotent per franchise per month, mirroring the ADC engine: the run skips
 * anything already CREDITED, and the rate is snapshotted on the result row so
 * a later plan edit cannot restate history.
 */
final class FranchiseCommissionService
{
    /**
     * Order states that count as fulfilled. `delivered` and `confirmed` only:
     * the franchise is paid for handing the goods over, so an order that is
     * paid but still sitting on the shelf has not earned anything yet. A
     * cancelled or refunded order never appears here at all.
     */
    private const FULFILLED_STATUSES = ['delivered', 'confirmed'];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * @return array{credited: int, skipped_no_orders: int, skipped_no_operator: int, total_gross_paise: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $credited = 0;
        $skippedNoOrders = 0;
        $skippedNoOperator = 0;
        $totalGross = 0;

        $franchises = Franchise::active()->get();

        DB::transaction(function () use (
            $franchises, $monthStart, $monthEnd,
            &$credited, &$skippedNoOrders, &$skippedNoOperator, &$totalGross
        ): void {
            foreach ($franchises as $franchise) {
                // `whereDate`, not `where`. `month_start` carries a `date`
                // cast, so Eloquent writes it as `2026-08-01 00:00:00` on
                // SQLite while a `toDateString()` comparison looks for
                // `2026-08-01`. An equality match therefore never fires, the
                // guard silently passes, and the second run of the month
                // crashes on the unique index instead of skipping. `whereDate`
                // compares the date part on both drivers.
                $alreadyCredited = FranchiseCommissionResult::where('franchise_id', $franchise->id)
                    ->whereDate('month_start', $monthStart->toDateString())
                    ->where('status', FranchiseCommissionResult::STATUS_CREDITED)
                    ->exists();

                if ($alreadyCredited) {
                    continue;
                }

                if (! $franchise->earnsCommission()) {
                    $skippedNoOperator++;

                    continue;
                }

                $fulfilment = $this->fulfilmentForMonth($franchise->id, $monthStart, $monthEnd);

                if ($fulfilment['order_count'] === 0 || $fulfilment['base_paise'] <= 0) {
                    $skippedNoOrders++;

                    continue;
                }

                $rateBp = $franchise->commission_rate_bp ?? $this->plan->franchiseRateBp();
                $gross = (int) floor($fulfilment['base_paise'] * $rateBp / 10_000);

                // Same reason as the guard above: the row has to be found by
                // date part, so `updateOrCreate` (which matches on equality)
                // cannot be used for the lookup half.
                $result = FranchiseCommissionResult::where('franchise_id', $franchise->id)
                    ->whereDate('month_start', $monthStart->toDateString())
                    ->first()
                    ?? new FranchiseCommissionResult([
                        'franchise_id' => $franchise->id,
                        'month_start' => $monthStart->toDateString(),
                    ]);

                $result->fill([
                    'distributor_id' => $franchise->operator_distributor_id,
                    'order_count' => $fulfilment['order_count'],
                    'base_paise' => $fulfilment['base_paise'],
                    'rate_bp' => $rateBp,
                    'gross_paise' => $gross,
                    'status' => FranchiseCommissionResult::STATUS_PENDING,
                ])->save();

                if ($gross > 0) {
                    $this->wallet->credit(
                        distributorId: (int) $franchise->operator_distributor_id,
                        amountPaise: $gross,
                        type: 'franchise_credit',
                        referenceId: $result->id,
                        referenceType: 'franchise_commission_result',
                        memo: 'Franchise commission — '.$franchise->code.' '.$monthStart->format('M Y'),
                    );

                    $totalGross += $gross;
                }

                // Finalise even at zero so the idempotency guard terminates on
                // a re-run. A zero-gross row simply carries no wallet entry.
                $result->update([
                    'status' => FranchiseCommissionResult::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);

                $credited++;
            }
        });

        return [
            'credited' => $credited,
            'skipped_no_orders' => $skippedNoOrders,
            'skipped_no_operator' => $skippedNoOperator,
            'total_gross_paise' => $totalGross,
        ];
    }

    /**
     * What one franchise fulfilled in the month.
     *
     * Dated on `delivered_at` rather than order date: a franchise is paid in
     * the month it did the work, not the month the buyer clicked.
     *
     * @return array{order_count: int, base_paise: int}
     */
    public function fulfilmentForMonth(int $franchiseId, Carbon $monthStart, Carbon $monthEnd): array
    {
        $row = DB::table('orders')
            ->where('franchise_id', $franchiseId)
            ->whereIn('status', self::FULFILLED_STATUSES)
            ->whereBetween('delivered_at', [
                $monthStart->copy()->startOfDay(),
                $monthEnd->copy()->endOfDay(),
            ])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(subtotal_paise - discount_paise), 0) as base_paise')
            ->first();

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'base_paise' => (int) ($row->base_paise ?? 0),
        ];
    }
}
