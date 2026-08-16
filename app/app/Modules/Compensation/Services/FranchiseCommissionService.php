<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Compensation\Models\FranchiseCommissionResult;
use App\Modules\Compensation\Models\FranchiseCommissionResultOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Franchise commission engine.
 *
 * Earning: a configurable rate (default 3%) of the **net product value of the
 * orders that franchise fulfilled** — Product Owner decision 2026-08-16, taken
 * over the alternative reading of 3% of the operator's lifetime BV. It is also
 * the compliance-safe reading: the payment attaches to identified product
 * sales, which is what hard rule 2 requires, whereas paying on accumulated
 * volume would reward holding a position rather than doing the work.
 *
 * **Base: `subtotal − gst − discount`.** Prices in this catalogue are
 * GST-inclusive — `Cart::totalPaise()` returns the subtotal unchanged and
 * `Cart::gstPaise()` extracts the tax *out of* the line — so subtracting GST
 * is what makes the base the product value rather than 3% of a tax-inclusive
 * figure. Paying a share of GST would be paying commission on money the
 * company merely collects for the government. Shipping is excluded for the
 * same reason in reverse: it is a real third-party cost, not revenue.
 *
 * **When an order counts: the month its return window closes**, not the month
 * it was delivered. The alternative pays the commission roughly two weeks into
 * a thirty-day cooling-off window and sweeps it to a bank account the same
 * morning, so a refund afterwards is unrecoverable — the gap R-23 requires to
 * be designed in rather than discovered. Waiting until the window has closed
 * means a refunded order never reaches the calculation at all, which is a
 * stronger guarantee than any clawback.
 *
 * Paid to: the operating distributor. The company's own primary franchise has
 * no operator and earns nothing.
 *
 * Idempotent per franchise per month. The rate is snapshotted on the result
 * row and every contributing order is recorded on
 * `franchise_commission_result_orders`, so "which sales was this paid on?"
 * stays answerable even after those orders change state (R-22).
 */
final class FranchiseCommissionService
{
    /**
     * Order states that count as fulfilled and final.
     *
     * `delivered` and `confirmed` only — a franchise is paid for handing the
     * goods over, so an order still on the shelf has earned nothing yet. An
     * order that was cancelled or refunded never reaches this list, and
     * because the window has already closed by the time it is counted, it
     * cannot fall out of it afterwards either.
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
                // `whereDate`, not `where`: `month_start` carries a `date`
                // cast, so Eloquent writes `2026-08-01 00:00:00` on SQLite
                // while a `toDateString()` comparison looks for `2026-08-01`.
                // An equality match never fires, the guard silently passes,
                // and the second run of the month crashes on the unique index
                // instead of skipping.
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

                $orders = $this->settledOrdersForMonth($franchise->id, $monthStart, $monthEnd);
                $basePaise = array_sum(array_column($orders, 'base_paise'));

                if ($orders === [] || $basePaise <= 0) {
                    $skippedNoOrders++;

                    continue;
                }

                $rateBp = $franchise->commission_rate_bp ?? $this->plan->franchiseRateBp();
                $gross = (int) floor($basePaise * $rateBp / 10_000);

                $result = FranchiseCommissionResult::where('franchise_id', $franchise->id)
                    ->whereDate('month_start', $monthStart->toDateString())
                    ->first()
                    ?? new FranchiseCommissionResult([
                        'franchise_id' => $franchise->id,
                        'month_start' => $monthStart->toDateString(),
                    ]);

                $result->fill([
                    'distributor_id' => $franchise->operator_distributor_id,
                    'order_count' => count($orders),
                    'base_paise' => $basePaise,
                    'rate_bp' => $rateBp,
                    'gross_paise' => $gross,
                    'status' => FranchiseCommissionResult::STATUS_PENDING,
                ])->save();

                // Record which sales this was paid on, in the same transaction.
                // R-22: an aggregate figure nobody can decompose is not a trace,
                // and re-running the query later would not reproduce it once
                // those orders change state.
                FranchiseCommissionResultOrder::where('result_id', $result->id)->delete();

                foreach ($orders as $order) {
                    FranchiseCommissionResultOrder::create([
                        'result_id' => $result->id,
                        'order_id' => $order['id'],
                        'base_paise' => $order['base_paise'],
                        'delivered_at' => $order['delivered_at'],
                    ]);
                }

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
     * The orders whose return window closed in this month, with the net
     * product value of each.
     *
     * An order delivered on D becomes final on D + the return window, so the
     * month's set is everything delivered between `monthStart − window` and
     * `monthEnd − window`. A refunded order is gone from the set by then, so
     * no clawback is ever needed.
     *
     * @return array<int, array{id: int, base_paise: int, delivered_at: string}>
     */
    public function settledOrdersForMonth(int $franchiseId, Carbon $monthStart, Carbon $monthEnd): array
    {
        $window = $this->returnWindowDays();

        $rows = DB::table('orders')
            ->where('franchise_id', $franchiseId)
            ->whereIn('status', self::FULFILLED_STATUSES)
            ->whereBetween('delivered_at', [
                $monthStart->copy()->subDays($window)->startOfDay(),
                $monthEnd->copy()->subDays($window)->endOfDay(),
            ])
            // Net product value: prices are GST-inclusive, so the tax has to
            // come out before the rate is applied.
            ->select('id', 'delivered_at')
            ->selectRaw('(subtotal_paise - gst_paise - discount_paise) as base_paise')
            ->orderBy('id')
            ->get();

        $orders = [];

        foreach ($rows as $row) {
            $base = (int) $row->base_paise;

            // A line that nets negative — a discount larger than the
            // ex-tax value — contributes nothing rather than reducing
            // someone else's sale.
            if ($base <= 0) {
                continue;
            }

            $orders[] = [
                'id' => (int) $row->id,
                'base_paise' => $base,
                'delivered_at' => (string) $row->delivered_at,
            ];
        }

        return $orders;
    }

    /**
     * Days a buyer has to return an order. Read from the commerce setting so
     * the hold and the published cooling-off window can never disagree.
     */
    public function returnWindowDays(): int
    {
        $configured = DB::table('settings')->where('key', 'commerce.cooling_off.days')->value('value');

        return is_numeric($configured) ? max(0, (int) $configured) : 30;
    }

    /**
     * What a month currently holds, for the admin projection. Same set as the
     * run would take.
     *
     * @return array{order_count: int, base_paise: int}
     */
    public function fulfilmentForMonth(int $franchiseId, Carbon $monthStart, Carbon $monthEnd): array
    {
        $orders = $this->settledOrdersForMonth($franchiseId, $monthStart, $monthEnd);

        return [
            'order_count' => count($orders),
            'base_paise' => array_sum(array_column($orders, 'base_paise')),
        ];
    }
}
