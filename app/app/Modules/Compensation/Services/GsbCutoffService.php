<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GsbCutoffService
{
    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
        private readonly BonusDeductionService $deductions,
    ) {}

    /**
     * Run (or re-run) the 23:59 cut-off for one distributor on one date.
     * Idempotent: if a 'credited' result already exists for this date, return it unchanged.
     */
    public function runForDistributor(int $distributorId, Carbon $date): GsbCutoffResult
    {
        // Idempotency: never double-credit.
        $existing = GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->first();

        if ($existing !== null && $existing->status === GsbCutoffResult::STATUS_CREDITED) {
            return $existing;
        }

        $distributor = Distributor::findOrFail($distributorId);

        // Eligibility gate: configurable minimum personal BV (default 600 BV).
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);

        if ($personalBvPaise < $this->plan->gsbMinBvPaise()) {
            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'left_bv_paise' => 0,
                'right_bv_paise' => 0,
                'weaker_bv_paise' => 0,
                'gross_gsb_paise' => 0,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_gsb_paise' => 0,
                'power_cf_before_paise' => 0,
                'power_cf_after_paise' => 0,
                'slab1_weaker_cf_before_paise' => 0,
                'slab1_weaker_cf_after_paise' => 0,
                'status' => GsbCutoffResult::STATUS_BELOW_600BV,
            ]);
        }

        $title = $this->titleService->forBvPaise($personalBvPaise);

        // Today's accumulated group BV (may be 0 if no orders in their group today).
        $dailyBv = GroupBvDaily::where('distributor_id', $distributorId)
            ->whereDate('date', $date->toDateString())
            ->first();

        $leftToday = $dailyBv?->left_bv_paise ?? 0;
        $rightToday = $dailyBv?->right_bv_paise ?? 0;

        // Carry-forward state (create row if this distributor's first cut-off).
        $cf = GsbCarryforward::firstOrCreate(
            ['distributor_id' => $distributorId],
            ['power_side_bv_paise' => 0, 'power_side' => null, 'slab1_weaker_bv_paise' => 0],
        );

        // Add power CF to the side it belongs to.
        $leftEffective = $leftToday + ($cf->power_side === 'L' ? $cf->power_side_bv_paise : 0);
        $rightEffective = $rightToday + ($cf->power_side === 'R' ? $cf->power_side_bv_paise : 0);

        if ($leftEffective >= $rightEffective) {
            $strongerSide = 'L';
            $strongerEffective = $leftEffective;
            $weakerEffective = $rightEffective;
        } else {
            $strongerSide = 'R';
            $strongerEffective = $rightEffective;
            $weakerEffective = $leftEffective;
        }

        // Slab 1 carry-forward is lifetime-accumulated (spec: "no daily cutoff, no time limit").
        // Slabs 2–7 use fresh weaker BV ONLY (spec: "no carry-forward; calculated fresh each day").
        // $weakerTotal is computed once here — also used by the no-match path to accumulate CF.
        $weakerTotal = $weakerEffective + $cf->slab1_weaker_bv_paise;

        $matchedSlab = null;

        // Slabs 7→2: fresh weaker BV only — slab1 CF must NOT apply here.
        // Inactive slabs (e.g. slab 7 while its bonus is TBD) are skipped.
        foreach ([7, 6, 5, 4, 3, 2] as $slabIndex) {
            $slabRow = $this->plan->gsbSlab($slabIndex);
            if ($slabRow === null || ! $slabRow['is_active'] || $slabRow['bonus_paise'] === null) {
                continue;
            }
            $threshold = $slabRow['matched_bv_paise'];
            $incentive = $slabRow['bonus_paise'];
            if ($slabIndex <= $title->maxGsbSlab && $weakerEffective >= $threshold) {
                $matchedSlab = ['index' => $slabIndex, 'threshold' => $threshold, 'incentive' => $incentive];
                break;
            }
        }

        // Slab 1: lifetime accumulation (includes today's fresh + historical CF).
        // Both the accumulated weaker-side total AND the stronger side (effective) must
        // reach the 15,000 BV threshold — "15K/15K" requires both Genos sides to qualify.
        $slab1 = $this->plan->gsbSlab(1);
        if ($matchedSlab === null
            && $slab1 !== null
            && $slab1['is_active']
            && $slab1['bonus_paise'] !== null
            && $title->maxGsbSlab >= 1
            && $weakerTotal >= $slab1['matched_bv_paise']
            && $strongerEffective >= $slab1['matched_bv_paise']) {
            $matchedSlab = ['index' => 1, 'threshold' => $slab1['matched_bv_paise'], 'incentive' => $slab1['bonus_paise']];
        }

        if ($matchedSlab === null) {
            // No match — update carry-forward: weaker accumulates for slab 1, power carries forward.
            $newPowerCf = min($strongerEffective, $this->plan->gsbPowerCfCapPaise());
            $newSlab1Cf = $weakerTotal;  // accumulates until 15K matched

            $cfBeforePower = $cf->power_side_bv_paise;
            $cfBeforeSlab1 = $cf->slab1_weaker_bv_paise;

            $cf->update([
                'power_side_bv_paise' => $newPowerCf,
                'power_side' => $strongerSide,
                'slab1_weaker_bv_paise' => $newSlab1Cf,
            ]);

            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'left_bv_paise' => $leftToday,
                'right_bv_paise' => $rightToday,
                'weaker_bv_paise' => $weakerTotal,
                'power_cf_before_paise' => $cfBeforePower,
                'power_cf_after_paise' => $newPowerCf,
                'power_side_after' => $strongerSide,
                'slab1_weaker_cf_before_paise' => $cfBeforeSlab1,
                'slab1_weaker_cf_after_paise' => $newSlab1Cf,
                'status' => GsbCutoffResult::STATUS_NO_MATCH,
            ]);
        }

        // Slab matched — compute deductions.
        $gross = $matchedSlab['incentive'];
        $deduction = $this->deductions->for(BonusType::Gsb, $gross);
        $adminCharge = $deduction->adminChargePaise;
        $tds = $deduction->tdsPaise;
        $net = $deduction->netPaise;

        $newPowerCf = min(
            max(0, $strongerEffective - $matchedSlab['threshold']),
            $this->plan->gsbPowerCfCapPaise(),
        );

        $cfBeforePower = $cf->power_side_bv_paise;
        $cfBeforeSlab1 = $cf->slab1_weaker_bv_paise;

        $baseData = [
            'distributor_id' => $distributorId,
            'cutoff_date' => $date->toDateString(),
            'left_bv_paise' => $leftToday,
            'right_bv_paise' => $rightToday,
            'weaker_bv_paise' => $weakerTotal,
            'slab' => $matchedSlab['index'],
            'gross_gsb_paise' => $gross,
            'admin_charge_paise' => $adminCharge,
            'tds_paise' => $tds,
            'net_gsb_paise' => $net,
            'power_cf_before_paise' => $cfBeforePower,
            'power_cf_after_paise' => $newPowerCf,
            'power_side_after' => $strongerSide,
            'slab1_weaker_cf_before_paise' => $cfBeforeSlab1,
            'slab1_weaker_cf_after_paise' => 0,
        ];

        // Frozen distributors: calculate but do not credit wallet.
        // Advance CF identically to the no-match path so stale slab1 BV doesn't
        // phantom-accumulate during the freeze and double-credit on unfreeze.
        if ($distributor->gsb_frozen_at !== null) {
            $cf->update([
                'power_side_bv_paise' => $newPowerCf,
                'power_side' => $strongerSide,
                'slab1_weaker_bv_paise' => 0,
            ]);

            return $this->saveResult($existing, [
                ...$baseData,
                'status' => GsbCutoffResult::STATUS_FROZEN,
            ]);
        }

        // Credit wallet inside a transaction — CF update is atomic with the wallet credit.
        try {
            $savedResult = null;

            DB::transaction(function () use ($distributorId, $net, $baseData, $existing, $cf, $strongerSide, $newPowerCf, &$savedResult): void {
                // Move carry-forward update inside the transaction so it rolls back if credit fails.
                $cf->update([
                    'power_side_bv_paise' => $newPowerCf,
                    'power_side' => $strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                $savedResult = $this->saveResult($existing, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_CALCULATED,
                ]);

                $this->wallet->credit(
                    distributorId: $distributorId,
                    amountPaise: $net,
                    type: 'gsb_credit',
                    referenceId: $savedResult->id,
                    referenceType: 'gsb_cutoff_result',
                );

                // STATUS_CALCULATED is transient; the daily command should treat past-date CALCULATED rows as failed on restart.
                $savedResult->update(['status' => GsbCutoffResult::STATUS_CREDITED]);
            });
        } catch (Throwable $e) {
            return $this->saveResult($existing, [
                ...$baseData,
                'slab1_weaker_cf_after_paise' => $cfBeforeSlab1,  // CF was NOT zeroed (transaction rolled back)
                'status' => GsbCutoffResult::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
            ]);
        }

        return GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->firstOrFail();
    }

    private function saveResult(?GsbCutoffResult $existing, array $data): GsbCutoffResult
    {
        if ($existing !== null) {
            $existing->fill($data)->save();

            return $existing->fresh();
        }

        return GsbCutoffResult::create($data);
    }
}
