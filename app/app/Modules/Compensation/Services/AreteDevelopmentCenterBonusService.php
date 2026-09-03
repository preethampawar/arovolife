<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\AreteCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arete Development Center Bonus engine.
 *
 * Earning: configurable % (default 3%) on the net attributed BV — the BV from
 * orders where a buyer selected this centre as their collection point
 * (orders.arete_center_id). Cancellations and refunds automatically net out:
 * BvLedgerService::reverse() writes a negative entry with the same order_id,
 * so the SUM query deducts them without extra code.
 *
 * Cap: configurable (default ₹1,00,000/month); a per-center
 * `monthly_cap_override_paise` may lower (never raise) it — the client's
 * development-phase penalty for centers that crossed a phase level without
 * proving the facility upgrade.
 * Admin charge + TDS: applied at payout time, not at credit time.
 * Rate, cap, admin charge and TDS are all admin-editable.
 * Paid to: the center's assigned distributor.
 */
final class AreteDevelopmentCenterBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Calculate and credit ADC Bonus for all active centers in the month.
     * Idempotent: skips centers already credited for the month.
     *
     * @return array{credited: int, skipped_no_bv: int, skipped_net_negative: int, total_net_paise: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $centers = AreteCenter::where('status', AreteCenter::STATUS_ACTIVE)->get();

        $credited = 0;
        $skippedNoBv = 0;
        $skippedNetNegative = 0;
        $totalNet = 0;

        DB::transaction(function () use ($centers, $monthStart, $monthEnd, &$credited, &$skippedNoBv, &$skippedNetNegative, &$totalNet): void {
            foreach ($centers as $center) {
                $alreadyCredited = AdcBonusResult::where('center_id', $center->id)
                    ->where('month_start', $monthStart)
                    ->where('status', AdcBonusResult::STATUS_CREDITED)
                    ->exists();

                if ($alreadyCredited) {
                    continue;
                }

                // Company-default centre has no assigned distributor yet — skip payout.
                if ($center->assigned_distributor_id === null) {
                    continue;
                }

                // Sum attributed BV: BV from all orders collected at this centre
                // in the month. `bv_paise` is signed (+ accrual, − reversal from
                // BvLedgerService::reverse()), so the SUM nets out cancelled and
                // refunded orders automatically — no extra cancellation handling needed.
                $totalBv = (int) DB::table('bv_ledger_entries')
                    ->join('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
                    ->where('orders.arete_center_id', $center->id)
                    ->whereBetween('bv_ledger_entries.effective_at', [$monthStart.' 00:00:00', $monthEnd.' 23:59:59'])
                    ->sum('bv_ledger_entries.bv_paise');

                $orderCount = (int) DB::table('orders')
                    ->where('arete_center_id', $center->id)
                    ->whereBetween('placed_at', [$monthStart.' 00:00:00', $monthEnd.' 23:59:59'])
                    ->whereNotIn('status', ['cancelled', 'draft'])
                    ->count();

                // A refund-heavy month can net to zero or below — nothing to
                // pay either way, but the two cases are counted apart: a
                // net-negative centre sold and then refunded, which is worth
                // surfacing separately from a centre that simply had no sales.
                if ($totalBv < 0) {
                    $skippedNetNegative++;

                    continue;
                }

                if ($totalBv === 0) {
                    $skippedNoBv++;

                    continue;
                }

                // Per-center override implements the client's development-phase
                // penalty (2026-08-07): a center that crossed a phase level
                // without emailing proof of the upgrade is held to a lower
                // slab income. The override can only lower the standard cap,
                // never raise it — the plan cap is a hard ceiling.
                $gross = $this->grossFor($totalBv, $center->monthly_cap_override_paise)['gross_paise'];
                $result = AdcBonusResult::updateOrCreate(
                    [
                        'center_id' => $center->id,
                        'month_start' => $monthStart,
                    ],
                    [
                        'distributor_id' => $center->assigned_distributor_id,
                        'order_count' => $orderCount,
                        'total_attributed_bv_paise' => $totalBv,
                        'gross_paise' => $gross,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_paise' => $gross,
                        'status' => AdcBonusResult::STATUS_PENDING,
                    ],
                );

                // Credit the wallet only when there is something to pay.
                if ($gross > 0) {
                    $this->wallet->credit(
                        distributorId: $center->assigned_distributor_id,
                        amountPaise: $gross,
                        type: 'adc_credit',
                        referenceId: $result->id,
                        referenceType: 'adc_bonus_result',
                        memo: 'Arete Dev Center Bonus — '.$center->name.' '.$monthStart,
                    );

                    $totalNet += $gross;
                }

                // Always finalize to CREDITED so the idempotency guard (which
                // matches CREDITED) terminates the result on re-runs, even when
                // net is zero (deductions exhausted the gross). A zero-net
                // result simply carries no wallet entry.
                $result->update([
                    'status' => AdcBonusResult::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);

                $credited++;
            }
        });

        return [
            'credited' => $credited,
            'skipped_no_bv' => $skippedNoBv,
            'skipped_net_negative' => $skippedNetNegative,
            'total_net_paise' => $totalNet,
        ];
    }

    /**
     * Live attributed BV for a single centre in the given month — used by
     * admin reports before the monthly engine has run.
     */
    public function attributedBvPaise(int $centerId, Carbon $month): int
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        return (int) DB::table('bv_ledger_entries')
            ->join('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
            ->where('orders.arete_center_id', $centerId)
            ->whereBetween('bv_ledger_entries.effective_at', [$monthStart.' 00:00:00', $monthEnd.' 23:59:59'])
            ->sum('bv_ledger_entries.bv_paise');
    }

    /**
     * The ADC arithmetic, in one place for the engine and the admin report:
     * flat = ⌊ collected BV × rate ⌋, gross = min(flat, cap), where a centre's
     * own override may only LOWER the plan cap (the plan cap is a ceiling).
     *
     * @return array{flat_paise: int, cap_paise: int, gross_paise: int}
     */
    public function grossFor(int $collectedBvPaise, ?int $capOverridePaise): array
    {
        $capPaise = $this->plan->adcCapPaise();
        if ($capOverridePaise !== null) {
            $capPaise = min($capPaise, $capOverridePaise);
        }

        $flat = max(0, (int) (floor($collectedBvPaise * $this->plan->adcRateBp() / 1_000_000) * 100));

        return [
            'flat_paise' => $flat,
            'cap_paise' => $capPaise,
            'gross_paise' => min($flat, $capPaise),
        ];
    }
}
