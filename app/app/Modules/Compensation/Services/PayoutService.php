<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PayoutService
{
    /** Minimum payout threshold: ₹500 = 50,000 paise. */
    private const MIN_PAYOUT_PAISE = 50_000;

    /** Max repurchase deduction: ₹10,000 = 1,000,000 paise. */
    private const MAX_REPURCHASE_PAISE = 1_000_000;

    public function __construct(private readonly WalletService $wallet) {}

    public function runBatch(Carbon $batchDate): PayoutBatch
    {
        // Idempotent: one batch per date.
        // Use whereDate() to avoid date-cast vs string mismatch on SQLite.
        $batch = PayoutBatch::whereDate('batch_date', $batchDate->toDateString())->first()
            ?? PayoutBatch::create([
                'batch_date' => $batchDate->toDateString(),
                'status' => PayoutBatch::STATUS_PENDING,
            ]);

        // Guard against both COMPLETED (clean finish) and PROCESSING (crash mid-run).
        // A PROCESSING batch that survived a crash must be manually reset to PENDING
        // by an admin before it can be re-run, preventing double-debit on auto-retry.
        if (in_array($batch->status, [PayoutBatch::STATUS_COMPLETED, PayoutBatch::STATUS_PROCESSING], true)) {
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

            $repurchase = $this->repurchaseDeductionPaise((int) $distributorId, $batchDate);
            $net = $balance - $repurchase;

            if ($net < self::MIN_PAYOUT_PAISE) {
                // Below minimum: record the line item but do not touch the wallet.
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

            // Wrap each distributor's wallet ops + line-item write in a transaction so a
            // mid-distributor crash cannot leave the wallet debited with a PENDING line item.
            // Phase 4 stub: marks as transferred immediately; Phase 5 integrates a real
            // bank transfer API with UTR number.
            DB::transaction(function () use (
                $distributorId, $batch, $balance, $repurchase, $net,
                &$totalGross, &$totalDeductions, &$totalNet, &$count,
            ): void {
                $this->wallet->debit(
                    distributorId: (int) $distributorId,
                    amountPaise: $balance,
                    type: 'payout_debit',
                    referenceId: null,      // set after line item is created; Phase 5 may link UTR
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
                    'status' => PayoutLineItem::STATUS_TRANSFERRED,
                ]);

                $totalGross += $balance;
                $totalDeductions += $repurchase;
                $totalNet += $net;
                $count++;
            });
        }

        $batch->update([
            'status' => PayoutBatch::STATUS_COMPLETED,
            'total_gross_paise' => $totalGross,
            'total_deductions_paise' => $totalDeductions,
            'total_net_paise' => $totalNet,
            'distributor_count' => $count,
            'processed_at' => now(),
        ]);

        return $batch;
    }

    /**
     * 10% of prior month's GSB + MB net credits, capped at ₹10,000.
     *
     * Only positive `amount_paise` entries are summed — reversal entries of the
     * same types would carry negative values and must not reduce the deduction.
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
}
