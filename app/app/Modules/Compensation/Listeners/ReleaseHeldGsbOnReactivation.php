<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Releases GSB income withheld while the distributor's repurchase cycle was
 * failed, the day they fulfil it (client 2026-09-06 rule 8; KP 2026-06-28's
 * original wording still holds):
 *
 *   "…on the day he fulfils his re-purchase condition, the total income
 *    withheld from that day will be calculated as usual and released to his
 *    bank account."
 *
 * Only HELD rows ({@see GsbCutoffResult::STATUS_REPURCHASE_HELD}) are
 * released — they were *calculated but not credited*. Legacy
 * {@see GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED} rows, written before
 * rule 8 made withheld income payable, are forfeited and intentionally NOT
 * released; no new ones are written.
 *
 * Mentorship is never withheld, so there is nothing to release for it. The
 * other three withheld bonuses have their own twins of this listener —
 * {@see ReleaseHeldGbbOnReactivation}, {@see ReleaseHeldRankBonusOnReactivation}
 * and {@see ReleaseHeldFortuneOnReactivation} — all registered together.
 *
 * Idempotent: each row is credited only while it is still HELD, flipped to
 * CREDITED inside the same row-locked transaction, so a re-fired event (or a
 * grace→suspended→completed path that fires reactivation once) can never
 * double-credit.
 */
final class ReleaseHeldGsbOnReactivation
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    public function handle(IncomeReactivated $event): void
    {
        $heldRowIds = GsbCutoffResult::query()
            ->where('distributor_id', $event->distributorId)
            ->where('status', GsbCutoffResult::STATUS_REPURCHASE_HELD)
            ->orderBy('cutoff_date')
            ->pluck('id');

        foreach ($heldRowIds as $rowId) {
            DB::transaction(function () use ($rowId, $event): void {
                /** @var GsbCutoffResult|null $row */
                $row = GsbCutoffResult::query()
                    ->whereKey($rowId)
                    ->lockForUpdate()
                    ->first();

                // Re-check under the lock: another run of this listener may have
                // released it already, or a re-cut-off may have moved it off HELD.
                if ($row === null || $row->status !== GsbCutoffResult::STATUS_REPURCHASE_HELD) {
                    return;
                }

                $gross = (int) $row->gross_gsb_paise;

                // Released income is income: it takes the repurchase deduction
                // like every other credit. Crediting through the plain credit()
                // here paid the distributor the full gross in cash — ₹200 more
                // on a ₹2,000 row than someone never held — and left the
                // repurchase wallet, which the FB/GBB/RB wallet-zero gates all
                // read, without the money that should have funded it.
                // A starved pool day can price a matched slab at ₹0; skip the
                // ledger noise, exactly as GsbCutoffService::settle() does.
                $repurchaseDeduction = 0;

                if ($gross > 0) {
                    $repurchaseDeduction = $this->wallet->creditWithRepurchaseDeduction(
                        distributorId: $event->distributorId,
                        grossPaise: $gross,
                        bonusType: 'gsb_credit',
                        referenceId: $row->id,
                        referenceType: 'gsb_cutoff_result',
                        // The released row is still August's income however late
                        // it is paid, so it charges August's deduction ceiling.
                        bonusMonth: $row->cutoff_date->copy()->startOfMonth(),
                        memo: 'Released after repurchase completion (cycle '.$event->cycleId.')',
                    )->repurchaseDeductionPaise;
                }

                $row->update([
                    'status' => GsbCutoffResult::STATUS_CREDITED,
                    'repurchase_deduction_paise' => $repurchaseDeduction,
                    'net_gsb_paise' => $gross - $repurchaseDeduction,
                ]);
            });
        }
    }
}
