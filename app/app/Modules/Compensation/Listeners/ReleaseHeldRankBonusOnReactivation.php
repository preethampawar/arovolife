<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Services\RankBonusService;
use Illuminate\Support\Facades\DB;

/**
 * Releases Rank Bonus income that was withheld while the distributor's
 * repurchase cycle was failed, the day they fulfil it (client 2026-09-06,
 * rule 8: "as soon as the distributor fulfills his repurchase condition, the
 * exempted facilities will be reinstated").
 *
 * Held rows were frozen with the SAME gross and point value as the paid ones
 * and left in the month's denominator precisely so this release pays the rate
 * everyone else was priced at.
 *
 * Idempotent: each row is credited only while it is still held, and the status
 * flips to CREDITED inside the same row-locked transaction, so a re-fired event
 * cannot double-credit. The twin of {@see ReleaseHeldGbbOnReactivation}.
 */
final class ReleaseHeldRankBonusOnReactivation
{
    public function __construct(
        private readonly RankBonusService $rankBonus,
    ) {}

    public function handle(IncomeReactivated $event): void
    {
        $heldRowIds = RankBonusResult::query()
            ->where('distributor_id', $event->distributorId)
            ->where('status', RankBonusResult::STATUS_REPURCHASE_HELD)
            ->orderBy('month_start')
            ->pluck('id');

        foreach ($heldRowIds as $rowId) {
            DB::transaction(function () use ($rowId): void {
                /** @var RankBonusResult|null $row */
                $row = RankBonusResult::query()
                    ->whereKey($rowId)
                    ->lockForUpdate()
                    ->first();

                // Re-check under the lock: another run of this listener may have
                // released it already, or a re-freeze may have moved it off held.
                if ($row === null || $row->status !== RankBonusResult::STATUS_REPURCHASE_HELD) {
                    return;
                }

                if ((int) $row->gross_paise <= 0) {
                    $row->update(['status' => RankBonusResult::STATUS_CREDITED, 'credited_at' => now()]);

                    return;
                }

                $this->rankBonus->releaseHeldRow($row);
            });
        }
    }
}
