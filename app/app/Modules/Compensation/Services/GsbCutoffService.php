<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GsbCutoffService
{
    /** Power-side carry-forward hard cap: 450,000 BV = 45,000,000 paise. */
    private const POWER_CF_CAP_PAISE = 45_000_000;

    /** Minimum personal BV to participate: 600 BV = 60,000 paise. */
    private const MIN_PERSONAL_BV_PAISE = 60_000;

    /** Max admin charge: ₹30,000 = 3,000,000 paise. */
    private const MAX_ADMIN_CHARGE_PAISE = 3_000_000;

    /**
     * [slab_index => [threshold_bv_paise, incentive_money_paise]]
     * Threshold in BV paise (BV × 100); incentive in money paise (₹ × 100).
     */
    private const SLABS = [
        1 => [1_500_000, 100_000],
        2 => [3_000_000, 300_000],
        3 => [9_000_000, 600_000],
        4 => [27_000_000, 1_200_000],
        5 => [80_000_000, 2_400_000],
        6 => [240_000_000, 4_000_000],
        7 => [720_000_000, 6_000_000],
    ];

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly WalletService $wallet,
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

        // Eligibility gate: 600 BV minimum personal purchase.
        $personalBvPaise = (int) BvLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->sum('bv_paise');

        if ($personalBvPaise < self::MIN_PERSONAL_BV_PAISE) {
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

        // Add slab-1 carry-forward to weaker side for matching purposes.
        $weakerTotal = $weakerEffective + $cf->slab1_weaker_bv_paise;

        // Find the highest matching slab, capped by personal title.
        $matchedSlab = null;

        foreach (array_reverse(self::SLABS, preserve_keys: true) as $slabIndex => [$threshold, $incentive]) {
            if ($slabIndex <= $title->maxGsbSlab && $weakerTotal >= $threshold) {
                $matchedSlab = ['index' => $slabIndex, 'threshold' => $threshold, 'incentive' => $incentive];
                break;
            }
        }

        if ($matchedSlab === null) {
            // No match — update carry-forward: weaker accumulates for slab 1, power carries forward.
            $newPowerCf = min($strongerEffective, self::POWER_CF_CAP_PAISE);
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
        $adminCharge = (int) min(round($gross * 0.03), self::MAX_ADMIN_CHARGE_PAISE);
        $tds = (int) round(($gross - $adminCharge) * 0.05);
        $net = $gross - $adminCharge - $tds;

        $newPowerCf = min(
            max(0, $strongerEffective - $matchedSlab['threshold']),
            self::POWER_CF_CAP_PAISE,
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
        if ($distributor->gsb_frozen_at !== null) {
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
