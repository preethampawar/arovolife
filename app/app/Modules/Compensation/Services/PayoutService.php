<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;

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
        // Use whereDate() to avoid cast-vs-string mismatch on SQLite.
        $batch = PayoutBatch::whereDate('batch_date', $batchDate->toDateString())->first()
            ?? PayoutBatch::create([
                'batch_date' => $batchDate->toDateString(),
                'status' => PayoutBatch::STATUS_PENDING,
            ]);

        if ($batch->status === PayoutBatch::STATUS_COMPLETED) {
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

            $lineStatus = $net < self::MIN_PAYOUT_PAISE
                ? PayoutLineItem::STATUS_BELOW_MINIMUM
                : PayoutLineItem::STATUS_PENDING;

            $line = PayoutLineItem::create([
                'payout_batch_id' => $batch->id,
                'distributor_id' => $distributorId,
                'wallet_balance_paise' => $balance,
                'repurchase_deduction_paise' => $repurchase,
                'net_transferred_paise' => max(0, $net),
                'status' => $lineStatus,
            ]);

            if ($lineStatus === PayoutLineItem::STATUS_PENDING) {
                // Phase 4 stub: debit the full wallet balance, credit back the repurchase deduction.
                // Phase 5 will integrate a real bank transfer API with UTR number.
                $this->wallet->debit(
                    distributorId: (int) $distributorId,
                    amountPaise: $balance,
                    type: 'payout_debit',
                    referenceId: $line->id,
                    referenceType: 'payout_line_item',
                );

                if ($repurchase > 0) {
                    $this->wallet->credit(
                        distributorId: (int) $distributorId,
                        amountPaise: $repurchase,
                        type: 'repurchase_deduction',
                        referenceId: $line->id,
                        referenceType: 'payout_line_item',
                    );
                }

                $line->update(['status' => PayoutLineItem::STATUS_TRANSFERRED]);

                $totalGross += $balance;
                $totalDeductions += $repurchase;
                $totalNet += $net;
                $count++;
            }
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

    /** 10% of prior month's GSB + MB net credits, capped at ₹10,000. */
    private function repurchaseDeductionPaise(int $distributorId, Carbon $batchDate): int
    {
        $priorMonthStart = $batchDate->copy()->subMonth()->startOfMonth();
        $priorMonthEnd = $batchDate->copy()->subMonth()->endOfMonth();

        $earned = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', ['gsb_credit', 'mb_credit'])
            ->whereBetween('created_at', [$priorMonthStart, $priorMonthEnd])
            ->sum('amount_paise');

        return min((int) round($earned * 0.10), self::MAX_REPURCHASE_PAISE);
    }
}
