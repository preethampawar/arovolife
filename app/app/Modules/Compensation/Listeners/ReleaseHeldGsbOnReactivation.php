<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Releases GSB income that was held during a repurchase grace window once the
 * distributor completes their repurchase (KP 2026-06-28, final answer):
 *
 *   "…on the day he fulfils his re-purchase condition, the total income
 *    withheld from that day will be calculated as usual and released to his
 *    bank account."
 *
 * Only GRACE-window rows ({@see GsbCutoffResult::STATUS_REPURCHASE_HELD}) are
 * released — they were *calculated but not credited*. Rows that fell in the
 * post-grace suspension ({@see GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED})
 * are forfeited for the lapsed period and are intentionally NOT released.
 *
 * Mentorship and Rank are never suspended, so there is nothing to release for
 * them. Growth Booster now persists its own monthly held rows and is released
 * by {@see ReleaseHeldGbbOnReactivation}, registered beside this listener.
 * Fortune remains a monthly batch that simply skips an ineligible distributor
 * rather than persist a held record, so it has no held artefact to release.
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

                $row->update(['status' => GsbCutoffResult::STATUS_CREDITED]);

                $this->wallet->credit(
                    distributorId: $event->distributorId,
                    amountPaise: (int) $row->gross_gsb_paise,
                    type: 'gsb_credit',
                    referenceId: $row->id,
                    referenceType: 'gsb_cutoff_result',
                    memo: 'Released after repurchase completion (cycle '.$event->cycleId.')',
                );
            });
        }
    }
}
