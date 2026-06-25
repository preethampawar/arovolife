<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PayoutService
{
    /** Max repurchase deduction: ₹10,000 = 1,000,000 paise. */
    private const MAX_REPURCHASE_PAISE = 1_000_000;

    /** Fallback minimum when the setting is absent. */
    private const DEFAULT_MIN_PAYOUT_PAISE = 50_000;

    /** Fallback when payout.neft_min_bv_paise is absent from settings (3,000 BV). */
    private const DEFAULT_NEFT_MIN_PAISE = 300_000;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
    ) {}

    /**
     * Generate a payout batch for the given date. After generation the batch
     * status is set to PENDING — an admin must call approve() to mark it
     * COMPLETED and confirm the NEFT transfers have been sent.
     *
     * Wallet debits are posted during generation (preventing double-spend
     * between generation and approval). The distributor's wallet therefore
     * shows zero pending balance immediately after the batch runs.
     */
    public function runBatch(Carbon $batchDate, string $batchType = PayoutBatch::TYPE_GSB_WEEKLY): PayoutBatch
    {
        $minPayoutPaise = $this->minPayoutPaise();

        // Idempotent: one batch per date.
        $batch = PayoutBatch::whereDate('batch_date', $batchDate->toDateString())->first()
            ?? PayoutBatch::create([
                'batch_type' => $batchType,
                'batch_date' => $batchDate->toDateString(),
                'status' => PayoutBatch::STATUS_PENDING,
            ]);

        // Guard: do not re-run a batch that is already in flight, generated, or approved.
        // - PROCESSING: currently running (crash-safe: admin resets to PENDING to retry)
        // - COMPLETED: admin-approved, NEFT confirmed
        // - PENDING with processed_at set: wallets already debited, awaiting admin approval
        if ($batch->status === PayoutBatch::STATUS_PROCESSING
            || $batch->status === PayoutBatch::STATUS_COMPLETED
            || ($batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null)) {
            return $batch;
        }

        $batch->update(['status' => PayoutBatch::STATUS_PROCESSING]);

        $distributors = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active')
            ->pluck('id');

        $totalGross = 0;
        $totalDeductions = 0;
        $totalNet = 0;
        $count = 0;

        foreach ($distributors as $distributorId) {
            $balance = $this->wallet->balancePaise((int) $distributorId);

            if ($balance <= 0) {
                continue;
            }

            // 3,000 BV gate — distributors below Retailer title earn web-only credits.
            // Their wallet balance is recorded but NOT included in the NEFT batch.
            $personalBvPaise = $this->bvLedger->totalPersonalBvPaise((int) $distributorId);

            if ($personalBvPaise < $this->neftMinBvPaise()) {
                PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $balance,
                    'repurchase_deduction_paise' => 0,
                    'net_transferred_paise' => 0,
                    'status' => PayoutLineItem::STATUS_WEB_ONLY,
                ]);

                continue;
            }

            $repurchase = $this->repurchaseDeductionPaise((int) $distributorId, $batchDate);
            $net = $balance - $repurchase;

            if ($net < $minPayoutPaise) {
                // Below minimum: record the line item but do not debit the wallet.
                PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $balance,
                    'repurchase_deduction_paise' => $repurchase,
                    'net_transferred_paise' => max(0, $net),
                    'status' => PayoutLineItem::STATUS_BELOW_MINIMUM,
                ]);

                continue;
            }

            // Debit wallet atomically with line-item creation. This locks in
            // the balance so the distributor cannot accrue more credits and
            // double-dip before admin approval of the NEFT batch.
            DB::transaction(function () use (
                $distributorId, $batch, $balance, $repurchase, $net,
                &$totalGross, &$totalDeductions, &$totalNet, &$count,
            ): void {
                $this->wallet->debit(
                    distributorId: (int) $distributorId,
                    amountPaise: $balance,
                    type: 'payout_debit',
                    referenceId: null,
                    referenceType: 'payout_line_item',
                );

                if ($repurchase > 0) {
                    $this->wallet->credit(
                        distributorId: (int) $distributorId,
                        amountPaise: $repurchase,
                        type: 'repurchase_deduction',
                        referenceId: null,
                        referenceType: 'payout_line_item',
                    );
                }

                PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $balance,
                    'repurchase_deduction_paise' => $repurchase,
                    'net_transferred_paise' => $net,
                    'bank_account_last4' => $this->bankLast4ForDistributor((int) $distributorId),
                    'status' => PayoutLineItem::STATUS_PENDING,
                ]);

                $totalGross += $balance;
                $totalDeductions += $repurchase;
                $totalNet += $net;
                $count++;
            });
        }

        // Batch moves from PROCESSING → PENDING (awaiting admin approval).
        $batch->update([
            'status' => PayoutBatch::STATUS_PENDING,
            'total_gross_paise' => $totalGross,
            'total_deductions_paise' => $totalDeductions,
            'total_net_paise' => $totalNet,
            'distributor_count' => $count,
            'processed_at' => now(),
        ]);

        return $batch;
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
     * 10% of prior month's GSB + MB net credits, capped at ₹10,000.
     *
     * Only positive amount_paise entries are summed — reversal entries of the
     * same types carry negative values and must not reduce the deduction.
     */
    private function repurchaseDeductionPaise(int $distributorId, Carbon $batchDate): int
    {
        $priorMonthStart = $batchDate->copy()->subMonth()->startOfMonth();
        $priorMonthEnd = $batchDate->copy()->subMonth()->endOfMonth();

        $earned = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', ['gsb_credit', 'mb_credit'])
            ->where('amount_paise', '>', 0)
            ->whereBetween('created_at', [$priorMonthStart, $priorMonthEnd])
            ->sum('amount_paise');

        return max(0, min((int) round($earned * 0.10), self::MAX_REPURCHASE_PAISE));
    }

    private function minPayoutPaise(): int
    {
        $raw = DB::table('settings')->where('key', 'payout.min_threshold_paise')->value('value');

        return $raw !== null ? (int) $raw : self::DEFAULT_MIN_PAYOUT_PAISE;
    }

    private function neftMinBvPaise(): int
    {
        $raw = DB::table('settings')->where('key', 'payout.neft_min_bv_paise')->value('value');

        return $raw !== null ? (int) $raw : self::DEFAULT_NEFT_MIN_PAISE;
    }

    private function bankLast4ForDistributor(int $distributorId): ?string
    {
        $raw = DB::table('distributors')->where('id', $distributorId)->value('bank_account_enc');

        if ($raw === null || $raw === 'stub') {
            return null;
        }

        return mb_strlen((string) $raw) >= 4 ? mb_substr((string) $raw, -4) : null;
    }
}
