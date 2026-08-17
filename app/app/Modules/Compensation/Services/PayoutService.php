<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Http\Middleware\RequireKycApproval;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PayoutService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Weekly payout batch (Group A: GSB + Mentorship).
     *
     * Sweeps all unswept gsb_credit and mb_credit entries. Deductions, in order:
     * repurchase (10% of prior-month Group A+B credits, shared month-wide with
     * the monthly batch), Group-A admin charge (3%, capped at ₹25k), TDS (5% of
     * payable).
     */
    public function runWeeklyBatch(Carbon $cycleEnd): PayoutBatch
    {
        $dateStr = $cycleEnd->toDateString();
        $minPayoutPaise = $this->plan->minPayoutPaise();
        $adminCapPaise = $this->plan->adminChargeWeeklyCapPaise();
        $adminRateBp = $this->plan->adminChargeRateBp();
        $tdsRateBp = $this->plan->tdsRateBp();
        $groupTypes = CompensationPlanSettingsService::GROUP_A_TYPES;

        // Idempotent: one weekly batch per date. whereDate() — the date cast
        // stores 'Y-m-d 00:00:00', so a bare where() on the string misses.
        $batch = PayoutBatch::whereDate('batch_date', $dateStr)
            ->where('batch_type', PayoutBatch::TYPE_WEEKLY)
            ->first()
            ?? PayoutBatch::create([
                'batch_type' => PayoutBatch::TYPE_WEEKLY,
                'batch_date' => $dateStr,
                'status' => PayoutBatch::STATUS_PENDING,
            ]);

        if ($batch->status === PayoutBatch::STATUS_PROCESSING
            || $batch->status === PayoutBatch::STATUS_COMPLETED
            || ($batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null)) {
            return $batch;
        }

        $batch->update(['status' => PayoutBatch::STATUS_PROCESSING]);

        $distributorIds = WalletLedgerEntry::whereIn('type', $groupTypes)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->distinct()
            ->pluck('distributor_id');

        foreach ($distributorIds as $rawId) {
            $distributorId = (int) $rawId;

            // Crash-resume guard: a prior partial run of this batch may already
            // have written this distributor's line item (paid lines are also
            // protected by the sweep marker, but WEB_ONLY / BELOW_MINIMUM lines
            // are not and would duplicate).
            if (PayoutLineItem::where('payout_batch_id', $batch->id)
                ->where('distributor_id', $distributorId)
                ->exists()) {
                continue;
            }

            $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);

            if ($personalBvPaise < $this->plan->neftMinBvPaise()) {
                $grossForDisplay = $this->wallet->sumUnsweptByTypes($distributorId, $groupTypes);
                if ($grossForDisplay > 0) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $grossForDisplay,
                        'gross_paise' => $grossForDisplay,
                        'repurchase_deduction_paise' => 0,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_transferred_paise' => 0,
                        'status' => PayoutLineItem::STATUS_WEB_ONLY,
                    ]);
                }

                continue;
            }

            // KYC gate: income accrues and stays fully visible to every
            // distributor, but the bank release is held until their KYC is
            // verified (users.status === 'active' — the same definition
            // RequireKycApproval uses). Partner instruction 2026-07-08: "the
            // distributor should see everything but payouts should not happen
            // unless their KYC is verified."
            if (! $this->isKycVerified($distributorId)) {
                $grossForDisplay = $this->wallet->sumUnsweptByTypes($distributorId, $groupTypes);
                if ($grossForDisplay > 0) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $grossForDisplay,
                        'gross_paise' => $grossForDisplay,
                        'repurchase_deduction_paise' => 0,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_transferred_paise' => 0,
                        'status' => PayoutLineItem::STATUS_KYC_PENDING,
                    ]);
                }

                continue;
            }

            // Bank gate: every income gate passed but no bank account is on
            // file (the optional registration step was skipped). Hold the
            // balance in the wallet — no debit, no sweep — so the first batch
            // after bank details arrive pays it out. Registration promises "we
            // cannot release any commission payout until your bank account is
            // on file"; without this gate the line would go out as pending and
            // approve() would mark an impossible NEFT as transferred.
            if (! $this->hasBankAccountOnFile($distributorId)) {
                $grossForDisplay = $this->wallet->sumUnsweptByTypes($distributorId, $groupTypes);
                if ($grossForDisplay > 0) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $grossForDisplay,
                        'gross_paise' => $grossForDisplay,
                        'repurchase_deduction_paise' => 0,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_transferred_paise' => 0,
                        'status' => PayoutLineItem::STATUS_NO_BANK_ACCOUNT,
                    ]);
                }

                continue;
            }

            $bankLast4 = $this->bankLast4ForDistributor($distributorId);

            DB::transaction(function () use (
                $distributorId, $batch, $cycleEnd, $groupTypes, $bankLast4,
                $adminRateBp, $adminCapPaise, $tdsRateBp, $minPayoutPaise,
            ): void {
                $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
                    ->whereIn('type', $groupTypes)
                    ->whereNull('swept_by_payout_batch_id')
                    ->where('amount_paise', '>', 0)
                    ->lockForUpdate()
                    ->get();

                $gsbSum = (int) $entries->where('type', 'gsb_credit')->sum('amount_paise');
                $mbSum = (int) $entries->where('type', 'mb_credit')->sum('amount_paise');

                if ($gsbSum + $mbSum <= 0) {
                    return;
                }

                // ₹50L combined monthly cap (KP 2026-06-26): the five cash
                // bonuses (GSB, MB, GBB, Rank, Fortune) share one monthly gross
                // ceiling. Fill the remaining room GSB-first; whatever exceeds
                // it is forfeited below with an explicit ledger debit.
                $capRoom = max(0, $this->plan->monthlyIncomeCapPaise()
                    - $this->monthToDateCappedGrossPaise($distributorId, $cycleEnd));
                $gsbEffective = min($gsbSum, $capRoom);
                $mbEffective = min($mbSum, $capRoom - $gsbEffective);
                $gross = $gsbEffective + $mbEffective;
                $capForfeit = ($gsbSum + $mbSum) - $gross;

                // Deduct at most this week's gross — an undeductable remainder
                // stays uncollected and repurchaseDeductionPaise() picks it up
                // on the next weekly run of the same month.
                $repurchase = min($this->repurchaseDeductionPaise($distributorId, $cycleEnd), $gross);
                // Admin charge honours the per-bonus applies_to toggles.
                $adminCharge = $this->adminChargeFor(
                    [[BonusType::Gsb, $gsbEffective], [BonusType::Mentorship, $mbEffective]],
                    $adminRateBp,
                    $adminCapPaise,
                );
                $payable = max(0, $gross - $repurchase - $adminCharge);
                $tds = (int) round($payable * $tdsRateBp / 10_000);
                $net = max(0, $payable - $tds);

                if ($net < $minPayoutPaise) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $gross,
                        'gross_paise' => $gross,
                        'repurchase_deduction_paise' => $repurchase,
                        'admin_charge_paise' => $adminCharge,
                        'tds_paise' => $tds,
                        'net_transferred_paise' => max(0, $net),
                        'status' => PayoutLineItem::STATUS_BELOW_MINIMUM,
                    ]);

                    return;
                }

                WalletLedgerEntry::whereIn('id', $entries->pluck('id')->all())
                    ->update(['swept_by_payout_batch_id' => $batch->id]);

                // The ledger enforces uniqueness on (type, reference_type,
                // reference_id), so the debit must reference this distributor's
                // LINE ITEM — referencing the shared batch id would collide on
                // the second paid distributor. Line item first, then the debit.
                $lineItem = PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $gross,
                    'gross_paise' => $gross,
                    'repurchase_deduction_paise' => $repurchase,
                    'admin_charge_paise' => $adminCharge,
                    'tds_paise' => $tds,
                    'net_transferred_paise' => $net,
                    'bank_account_last4' => $bankLast4,
                    'status' => PayoutLineItem::STATUS_PENDING,
                ]);

                $this->wallet->debit(
                    distributorId: $distributorId,
                    amountPaise: $gross,
                    type: 'payout_debit',
                    referenceId: $lineItem->id,
                    referenceType: 'payout_line_item',
                );

                // Credits above the monthly cap were swept with the rest, so an
                // explicit debit is needed or the excess lingers as a phantom
                // wallet balance forever.
                if ($capForfeit > 0) {
                    $this->wallet->debit(
                        distributorId: $distributorId,
                        amountPaise: $capForfeit,
                        type: 'income_cap_forfeit',
                        referenceId: $lineItem->id,
                        referenceType: 'payout_line_item',
                        memo: 'Cash income above the combined monthly income cap',
                    );
                }

                if ($repurchase > 0) {
                    $this->wallet->credit(
                        distributorId: $distributorId,
                        amountPaise: $repurchase,
                        type: 'repurchase_deduction',
                        referenceId: $lineItem->id,
                        referenceType: 'payout_line_item',
                    );
                }
            });
        }

        $this->finalizeBatchTotals($batch);

        return $batch;
    }

    /**
     * Monthly payout batch (Groups B/C/D: GBB, Rank, Fortune, Awards, ADC).
     *
     * Sweeps all unswept entries for the five monthly bonus streams. Applies the
     * ₹50L per-distributor rank cap (KP Round-4), the repurchase deduction
     * remainder not already collected by the month's weekly batches, per-group
     * admin charges (each capped at ₹25k), and TDS (5% on payable). Deduction
     * order: gross → repurchase → admin charge → TDS.
     */
    public function runMonthlyBatch(Carbon $month): PayoutBatch
    {
        $dateStr = $month->copy()->startOfMonth()->toDateString();
        $minPayoutPaise = $this->plan->minPayoutPaise();
        $adminCapPaise = $this->plan->adminChargeMonthlyCapPaise();
        $adminRateBp = $this->plan->adminChargeRateBp();
        $tdsRateBp = $this->plan->tdsRateBp();
        $incomeCapPaise = $this->plan->monthlyIncomeCapPaise();

        $allMonthlyTypes = array_merge(
            CompensationPlanSettingsService::GROUP_B_TYPES,
            CompensationPlanSettingsService::GROUP_C_TYPES,
            CompensationPlanSettingsService::GROUP_D_TYPES,
        );

        // Idempotent: one monthly batch per month-start date. whereDate() — the
        // date cast stores 'Y-m-d 00:00:00', so a bare where() on the string misses.
        $batch = PayoutBatch::whereDate('batch_date', $dateStr)
            ->where('batch_type', PayoutBatch::TYPE_MONTHLY)
            ->first()
            ?? PayoutBatch::create([
                'batch_type' => PayoutBatch::TYPE_MONTHLY,
                'batch_date' => $dateStr,
                'status' => PayoutBatch::STATUS_PENDING,
            ]);

        if ($batch->status === PayoutBatch::STATUS_PROCESSING
            || $batch->status === PayoutBatch::STATUS_COMPLETED
            || ($batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null)) {
            return $batch;
        }

        $batch->update(['status' => PayoutBatch::STATUS_PROCESSING]);

        $distributorIds = WalletLedgerEntry::whereIn('type', $allMonthlyTypes)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->distinct()
            ->pluck('distributor_id');

        foreach ($distributorIds as $rawId) {
            $distributorId = (int) $rawId;

            // Crash-resume guard — see runWeeklyBatch().
            if (PayoutLineItem::where('payout_batch_id', $batch->id)
                ->where('distributor_id', $distributorId)
                ->exists()) {
                continue;
            }

            $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);

            if ($personalBvPaise < $this->plan->neftMinBvPaise()) {
                $grossForDisplay = $this->wallet->sumUnsweptByTypes($distributorId, $allMonthlyTypes);
                if ($grossForDisplay > 0) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $grossForDisplay,
                        'gross_paise' => $grossForDisplay,
                        'repurchase_deduction_paise' => 0,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_transferred_paise' => 0,
                        'status' => PayoutLineItem::STATUS_WEB_ONLY,
                    ]);
                }

                continue;
            }

            // KYC gate — same rule as the weekly batch: income accrues and is
            // visible, but the bank release is held until KYC is verified
            // (users.status === 'active'). Partner instruction 2026-07-08.
            if (! $this->isKycVerified($distributorId)) {
                $grossForDisplay = $this->wallet->sumUnsweptByTypes($distributorId, $allMonthlyTypes);
                if ($grossForDisplay > 0) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $grossForDisplay,
                        'gross_paise' => $grossForDisplay,
                        'repurchase_deduction_paise' => 0,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_transferred_paise' => 0,
                        'status' => PayoutLineItem::STATUS_KYC_PENDING,
                    ]);
                }

                continue;
            }

            // Bank gate — same rule as the weekly batch: no bank account on
            // file means the balance is held in the wallet, never debited or
            // swept, until details arrive.
            if (! $this->hasBankAccountOnFile($distributorId)) {
                $grossForDisplay = $this->wallet->sumUnsweptByTypes($distributorId, $allMonthlyTypes);
                if ($grossForDisplay > 0) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $grossForDisplay,
                        'gross_paise' => $grossForDisplay,
                        'repurchase_deduction_paise' => 0,
                        'admin_charge_paise' => 0,
                        'tds_paise' => 0,
                        'net_transferred_paise' => 0,
                        'status' => PayoutLineItem::STATUS_NO_BANK_ACCOUNT,
                    ]);
                }

                continue;
            }

            $bankLast4 = $this->bankLast4ForDistributor($distributorId);

            DB::transaction(function () use (
                $distributorId, $batch, $allMonthlyTypes, $incomeCapPaise, $month, $bankLast4,
                $adminRateBp, $adminCapPaise, $tdsRateBp, $minPayoutPaise,
            ): void {
                $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
                    ->whereIn('type', $allMonthlyTypes)
                    ->whereNull('swept_by_payout_batch_id')
                    ->where('amount_paise', '>', 0)
                    ->lockForUpdate()
                    ->get();

                if ($entries->isEmpty()) {
                    return;
                }

                // Group B: GBB + Rank + Fortune — all five cash bonuses share
                // the combined ₹50L monthly cap (the month's weekly GSB/MB
                // batches already consumed part of the room). Fill the
                // remaining room Fortune → GBB → Rank, so rank (the largest
                // pool) is forfeited first when the cap is breached.
                $gbbSum = (int) $entries->where('type', 'gbb_credit')->sum('amount_paise');
                $rankSum = (int) $entries->where('type', 'rank_credit')->sum('amount_paise');
                $fortuneSum = (int) $entries->where('type', 'fortune_credit')->sum('amount_paise');
                $capRoom = max(0, $incomeCapPaise - $this->monthToDateCappedGrossPaise($distributorId, $month));
                $fortuneEffective = min($fortuneSum, $capRoom);
                $gbbEffective = min($gbbSum, $capRoom - $fortuneEffective);
                $rankEffective = min($rankSum, $capRoom - $fortuneEffective - $gbbEffective);
                $grossB = $gbbEffective + $rankEffective + $fortuneEffective;
                $capForfeit = ($gbbSum + $rankSum + $fortuneSum) - $grossB;

                // Group C: Awards.
                $grossC = (int) $entries->where('type', 'awards_credit')->sum('amount_paise');

                // Group D: ADC + franchise commission.
                //
                // Both types are in GROUP_D_TYPES and are therefore SWEPT by
                // this batch. Summing only `adc_credit` here would mark a
                // franchise credit as paid while excluding it from the gross,
                // the debit and the line item — a franchise-only earner would
                // never be paid at all, and a mixed earner would carry a
                // phantom wallet balance no later batch could clear.
                $grossD = (int) $entries->whereIn('type', ['adc_credit', 'franchise_credit'])->sum('amount_paise');
                $grossAdc = (int) $entries->where('type', 'adc_credit')->sum('amount_paise');
                $grossFranchise = (int) $entries->where('type', 'franchise_credit')->sum('amount_paise');

                $gross = $grossB + $grossC + $grossD;

                if ($gross <= 0) {
                    return;
                }

                // Per-group admin charge caps (each independent ₹25k ceiling).
                // Within each group the charge honours the per-bonus applies_to
                // toggles, so an exempt stream is excluded from the chargeable base.
                $adminB = $this->adminChargeFor([
                    [BonusType::GrowthBooster, $gbbEffective],
                    [BonusType::Rank, $rankEffective],
                    [BonusType::Fortune, $fortuneEffective],
                ], $adminRateBp, $adminCapPaise);
                $adminC = $this->adminChargeFor([[BonusType::LifetimeAwards, $grossC]], $adminRateBp, $adminCapPaise);
                // Franchise commission is listed separately so its
                // `applies_to_franchise` toggle is honoured rather than
                // inheriting ADC's. It defaults to exempt — the commission
                // pays for fulfilment work, not for a position in the plan.
                $adminD = $this->adminChargeFor([
                    [BonusType::Arete, $grossAdc],
                    [BonusType::Franchise, $grossFranchise],
                ], $adminRateBp, $adminCapPaise);
                $adminCharge = $adminB + $adminC + $adminD;

                // Repurchase is a MONTHLY figure computed off prior-month bonus
                // credits; repurchaseDeductionPaise() nets off whatever the
                // month's weekly batches already collected, so only the
                // remainder is taken here. Capped at this batch's gross — an
                // undeductable remainder stays uncollected for the next run.
                // Deduction order (KP-confirmed): gross → repurchase → admin → TDS.
                $repurchase = min($this->repurchaseDeductionPaise($distributorId, $month), $gross);

                $payable = max(0, $gross - $repurchase - $adminCharge);
                $tds = (int) round($payable * $tdsRateBp / 10_000);
                $net = max(0, $payable - $tds);

                if ($net < $minPayoutPaise) {
                    PayoutLineItem::create([
                        'payout_batch_id' => $batch->id,
                        'distributor_id' => $distributorId,
                        'wallet_balance_paise' => $gross,
                        'gross_paise' => $gross,
                        'repurchase_deduction_paise' => $repurchase,
                        'admin_charge_paise' => $adminCharge,
                        'tds_paise' => $tds,
                        'net_transferred_paise' => max(0, $net),
                        'status' => PayoutLineItem::STATUS_BELOW_MINIMUM,
                    ]);

                    return;
                }

                WalletLedgerEntry::whereIn('id', $entries->pluck('id')->all())
                    ->update(['swept_by_payout_batch_id' => $batch->id]);

                // Line item first — the ledger's (type, reference_type,
                // reference_id) unique index requires the debit to reference
                // this distributor's line item, not the shared batch id.
                $lineItem = PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $gross,
                    'gross_paise' => $gross,
                    'repurchase_deduction_paise' => $repurchase,
                    'admin_charge_paise' => $adminCharge,
                    'tds_paise' => $tds,
                    'net_transferred_paise' => $net,
                    'bank_account_last4' => $bankLast4,
                    'status' => PayoutLineItem::STATUS_PENDING,
                ]);

                // Debit the effective gross (rank cap may reduce the actual debit).
                $this->wallet->debit(
                    distributorId: $distributorId,
                    amountPaise: $gross,
                    type: 'payout_debit',
                    referenceId: $lineItem->id,
                    referenceType: 'payout_line_item',
                );

                // Credits above the monthly cap are forfeited, not carried:
                // their entries were swept above, so an explicit debit is needed
                // or the excess would linger as a phantom wallet balance forever.
                if ($capForfeit > 0) {
                    $this->wallet->debit(
                        distributorId: $distributorId,
                        amountPaise: $capForfeit,
                        type: 'income_cap_forfeit',
                        referenceId: $lineItem->id,
                        referenceType: 'payout_line_item',
                        memo: 'Cash income above the combined monthly income cap',
                    );
                }

                if ($repurchase > 0) {
                    $this->wallet->credit(
                        distributorId: $distributorId,
                        amountPaise: $repurchase,
                        type: 'repurchase_deduction',
                        referenceId: $lineItem->id,
                        referenceType: 'payout_line_item',
                    );
                }
            });
        }

        $this->finalizeBatchTotals($batch);

        return $batch;
    }

    /**
     * Batch rollup computed from the actual persisted line items (paid lines
     * only), not from in-memory accumulators — a crash-resumed run therefore
     * reports the full batch, not just the distributors processed after the
     * restart.
     */
    private function finalizeBatchTotals(PayoutBatch $batch): void
    {
        $paid = PayoutLineItem::where('payout_batch_id', $batch->id)
            ->where('status', PayoutLineItem::STATUS_PENDING)
            ->selectRaw('COALESCE(SUM(gross_paise),0) AS gross, COALESCE(SUM(repurchase_deduction_paise + admin_charge_paise + tds_paise),0) AS deductions, COALESCE(SUM(net_transferred_paise),0) AS net, COUNT(*) AS cnt')
            ->first();

        $batch->update([
            'status' => PayoutBatch::STATUS_PENDING,
            'total_gross_paise' => (int) $paid->gross,
            'total_deductions_paise' => (int) $paid->deductions,
            'total_net_paise' => (int) $paid->net,
            'distributor_count' => (int) $paid->cnt,
            'processed_at' => now(),
        ]);
    }

    /**
     * Combined gross of the five capped cash-bonus streams (GSB, MB, GBB,
     * Rank, Fortune) already swept into payout batches whose batch_date falls
     * in the same calendar month. Forfeited amounts count too: once the cap is
     * reached it stays reached — a forfeit never frees up room.
     */
    private function monthToDateCappedGrossPaise(int $distributorId, Carbon $batchDate): int
    {
        $batchIds = PayoutBatch::whereBetween('batch_date', [
            $batchDate->copy()->startOfMonth()->format('Y-m-d 00:00:00'),
            $batchDate->copy()->endOfMonth()->format('Y-m-d 23:59:59'),
        ])->pluck('id');

        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', CompensationPlanSettingsService::MONTHLY_CAP_TYPES)
            ->where('amount_paise', '>', 0)
            ->whereIn('swept_by_payout_batch_id', $batchIds)
            ->sum('amount_paise');
    }

    /**
     * Admin-initiated approval: mark the batch as completed and all pending
     * line items as transferred. Records who approved and when.
     */
    public function approve(PayoutBatch $batch, int $approvedByUserId): PayoutBatch
    {
        if ($batch->status !== PayoutBatch::STATUS_PENDING) {
            return $batch;
        }

        DB::transaction(function () use ($batch, $approvedByUserId): void {
            $batch->lineItems()
                ->where('status', PayoutLineItem::STATUS_PENDING)
                ->update(['status' => PayoutLineItem::STATUS_TRANSFERRED]);

            $batch->update([
                'status' => PayoutBatch::STATUS_COMPLETED,
                'approved_by' => $approvedByUserId,
                'approved_at' => now(),
            ]);
        });

        return $batch->refresh();
    }

    /**
     * Lifetime money actually settled to a distributor's bank: the sum of every
     * transferred line item's net, after repurchase, admin charge and TDS.
     * Pending and failed lines are excluded — nothing left the company for them.
     *
     * The wallet page's "Total paid out" and the ID card's "Total Withdrawal
     * Income" both read this, so the status filter lives in one place.
     */
    public function totalTransferredPaise(int $distributorId): int
    {
        return (int) PayoutLineItem::where('distributor_id', $distributorId)
            ->where('status', PayoutLineItem::STATUS_TRANSFERRED)
            ->sum('net_transferred_paise');
    }

    /**
     * Admin charge for a set of bonus streams: the rate is levied only on the
     * grosses whose per-bonus `applies_to` toggle is ON (admins can exempt a
     * stream from the admin charge), then the total is capped.
     *
     * @param  list<array{0: BonusType, 1: int}>  $streams  [bonus type, gross paise] pairs
     */
    private function adminChargeFor(array $streams, int $rateBp, int $capPaise): int
    {
        $chargeableBase = 0;
        foreach ($streams as [$type, $gross]) {
            if ($this->plan->adminChargeAppliesTo($type)) {
                $chargeableBase += $gross;
            }
        }

        return (int) min((int) round($chargeableBase * $rateBp / 10_000), $capPaise);
    }

    /**
     * Configurable % of prior month's bonus credits, capped. KP-confirmed pool:
     * GSB + Mentorship + Growth Booster + Fortune + Rank net credits.
     *
     * The deduction is a MONTHLY figure but the weekly batch runs 4–5 times a
     * month and the monthly batch once more, so whatever was already collected
     * this month (the repurchase_deduction credits posted by earlier batches of
     * either type) is subtracted — only the remainder is taken. Without this,
     * the same 10% would be re-deducted every Tuesday and again month-end.
     *
     * Only positive amount_paise entries are summed — reversal entries of the
     * same types carry negative values and must not reduce the deduction.
     */
    private function repurchaseDeductionPaise(int $distributorId, Carbon $batchDate): int
    {
        // startOfMonth() BEFORE subMonth(), never after: PHP's relative-month
        // arithmetic overflows, so 31 Jul − 1 month is 31 Jun → 1 Jul, and the
        // "prior month" window would land back on the current month and deduct
        // repurchase against this very batch's credits. Day 1 can never overflow.
        $priorMonthStart = $batchDate->copy()->startOfMonth()->subMonth();
        $priorMonthEnd = $priorMonthStart->copy()->endOfMonth();

        $earned = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', ['gsb_credit', 'mb_credit', 'gbb_credit', 'fortune_credit', 'rank_credit'])
            ->where('amount_paise', '>', 0)
            ->whereBetween('created_at', [$priorMonthStart, $priorMonthEnd])
            ->sum('amount_paise');

        $monthlyTarget = max(0, min(
            (int) round($earned * $this->plan->repurchaseRateBp() / 10_000),
            $this->plan->repurchaseCapPaise(),
        ));

        $alreadyCollectedThisMonth = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_deduction')
            ->where('amount_paise', '>', 0)
            ->whereBetween('created_at', [
                $batchDate->copy()->startOfMonth(),
                $batchDate->copy()->endOfMonth(),
            ])
            ->sum('amount_paise');

        return max(0, $monthlyTarget - $alreadyCollectedThisMonth);
    }

    /**
     * A bank account is "on file" when the encrypted column holds anything at
     * all — including the Phase-1 'stub' placeholder, which represents details
     * captured before real encryption went live. NULL/empty means the optional
     * bank step was skipped and payouts must be held (STATUS_NO_BANK_ACCOUNT).
     */
    private function hasBankAccountOnFile(int $distributorId): bool
    {
        $raw = DB::table('distributors')->where('id', $distributorId)->value('bank_account_enc');

        return $raw !== null && $raw !== '';
    }

    /**
     * KYC is "verified" when the distributor's user account is active — the
     * same definition {@see RequireKycApproval}
     * uses ('active' carries the "Verified" pill). Until then the bank release
     * is held (STATUS_KYC_PENDING); income still accrues and stays visible.
     */
    private function isKycVerified(int $distributorId): bool
    {
        return DB::table('distributors')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->where('distributors.id', $distributorId)
            ->value('users.status') === 'active';
    }

    private function bankLast4ForDistributor(int $distributorId): ?string
    {
        $raw = DB::table('distributors')->where('id', $distributorId)->value('bank_account_enc');

        if ($raw === null || $raw === 'stub') {
            return null;
        }

        // The column holds PII ciphertext — the last 4 must come from the
        // DECRYPTED account number or the NEFT export shows garbage that
        // finance cannot reconcile against the bank.
        try {
            $accountNumber = PiiCrypter::decryptString((string) $raw);
        } catch (Throwable) {
            return null;
        }

        return mb_strlen($accountNumber) >= 4 ? mb_substr($accountNumber, -4) : null;
    }
}
