<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Releases Growth Booster income that was held during a repurchase grace window
 * once the distributor completes their repurchase (KP 2026-06-28, final answer):
 *
 *   "…on the day he fulfils his re-purchase condition, the total income
 *    withheld from that day will be calculated as usual and released to his
 *    bank account."
 *
 * Only GRACE-window rows ({@see GbbMonthlyResult::STATUS_REPURCHASE_HELD}) are
 * released — they were *calculated at the month's frozen point value but not
 * credited*, and their AGP was left in the month's denominator precisely so a
 * release pays the same rupees everyone else was priced at. Rows that fell in
 * the post-grace suspension ({@see GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED})
 * are audit-only records of forfeited AGP: gross 0, excluded from the month's
 * denominator, and intentionally NEVER released.
 *
 * Idempotent: each row is credited only while it is still HELD, flipped to
 * CREDITED inside the same row-locked transaction, so a re-fired event can never
 * double-credit. The CREDITED status also stops a later GBB re-run for the same
 * month from pushing the row back to HELD.
 */
final class ReleaseHeldGbbOnReactivation
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    public function handle(IncomeReactivated $event): void
    {
        $heldRowIds = GbbMonthlyResult::query()
            ->where('distributor_id', $event->distributorId)
            ->where('status', GbbMonthlyResult::STATUS_REPURCHASE_HELD)
            ->orderBy('year_month')
            ->pluck('id');

        foreach ($heldRowIds as $rowId) {
            DB::transaction(function () use ($rowId, $event): void {
                /** @var GbbMonthlyResult|null $row */
                $row = GbbMonthlyResult::query()
                    ->whereKey($rowId)
                    ->lockForUpdate()
                    ->first();

                // Re-check under the lock: another run of this listener may have
                // released it already, or a re-run may have moved it off HELD.
                if ($row === null || $row->status !== GbbMonthlyResult::STATUS_REPURCHASE_HELD) {
                    return;
                }

                $gross = (int) $row->gbb_gross_paise;

                // Released income is income: it takes the repurchase deduction
                // like every other credit. Crediting through the plain credit()
                // here paid the distributor the full gross in cash and left the
                // repurchase wallet — which the FB/GBB/RB wallet-zero gates all
                // read — without the money that should have funded it.
                $repurchaseDeduction = 0;

                if ($gross > 0) {
                    $repurchaseDeduction = $this->wallet->creditWithRepurchaseDeduction(
                        distributorId: $event->distributorId,
                        grossPaise: $gross,
                        bonusType: 'gbb_credit',
                        referenceId: $row->id,
                        referenceType: 'gbb_monthly_result',
                        // The released row is still that month's income however
                        // late it is paid, so it charges that month's ceiling.
                        bonusMonth: Carbon::parse($row->year_month)->startOfMonth(),
                        memo: 'Released after repurchase completion (cycle '.$event->cycleId.')',
                    )->repurchaseDeductionPaise;
                }

                $row->update([
                    'status' => GbbMonthlyResult::STATUS_CREDITED,
                    'credited_at' => now(),
                    'repurchase_deduction_paise' => $repurchaseDeduction,
                    'gbb_net_paise' => $gross - $repurchaseDeduction,
                ]);
            });
        }
    }
}
