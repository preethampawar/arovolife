<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Releases Fortune Bonus income that was withheld while the distributor's
 * repurchase cycle was failed, the day they fulfil it (client 2026-09-06,
 * rule 8).
 *
 * Held rows were enrolled, positioned and priced exactly like the paid ones —
 * the roster and the level cascade were frozen with them in it — so the release
 * pays the gross the month was divided at. The twin of
 * {@see ReleaseHeldGbbOnReactivation}; idempotent for the same reason, the
 * status flipping to CREDITED inside the row-locked transaction.
 */
final class ReleaseHeldFortuneOnReactivation
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    public function handle(IncomeReactivated $event): void
    {
        $heldRowIds = FortuneBonusResult::query()
            ->where('distributor_id', $event->distributorId)
            ->where('status', FortuneBonusResult::STATUS_REPURCHASE_HELD)
            ->orderBy('month_start')
            ->pluck('id');

        foreach ($heldRowIds as $rowId) {
            DB::transaction(function () use ($rowId, $event): void {
                /** @var FortuneBonusResult|null $row */
                $row = FortuneBonusResult::query()
                    ->whereKey($rowId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null || $row->status !== FortuneBonusResult::STATUS_REPURCHASE_HELD) {
                    return;
                }

                $gross = (int) $row->gross_paise;
                $monthStart = Carbon::parse((string) $row->month_start)->startOfMonth();
                $repurchaseDeduction = 0;

                if ($gross > 0) {
                    // Released income is income: it takes the repurchase
                    // deduction like every other credit, and charges the month
                    // it was earned for however late it is paid.
                    $repurchaseDeduction = $this->wallet->creditWithRepurchaseDeduction(
                        distributorId: $event->distributorId,
                        grossPaise: $gross,
                        bonusType: 'fortune_credit',
                        referenceId: $row->id,
                        referenceType: 'fortune_bonus_result',
                        bonusMonth: $monthStart,
                        memo: 'Fortune Bonus '.$monthStart->toDateString().' — released after repurchase completion (cycle '.$event->cycleId.')',
                    )->repurchaseDeductionPaise;
                }

                $row->update([
                    'status' => FortuneBonusResult::STATUS_CREDITED,
                    'credited_at' => now(),
                    'repurchase_deduction_paise' => $repurchaseDeduction,
                    'net_paise' => $gross - $repurchaseDeduction,
                ]);
            });
        }
    }
}
