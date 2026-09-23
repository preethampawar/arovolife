<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Console\Commands;

use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OfflinePaymentProofVault;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delete offline-payment proof files that have outlived their retention
 * period (DPDP Act 2023 §8(7); risk register R-107).
 *
 * Two clocks, both admin-owned settings:
 *   - a confirmed payment's proof backs a sale in the books, so it is kept for
 *     `commerce.offline_payment_proof_retention_days` from confirmation;
 *   - a rejected payment's proof, or a pending one whose order was cancelled,
 *     backs nothing, so it is kept only for
 *     `commerce.offline_payment_rejected_proof_retention_days`.
 * The payment row itself stays — only the file goes, and the row records when.
 */
final class PurgeOfflinePaymentProofsCommand extends Command
{
    protected $signature = 'commerce:purge-offline-payment-proofs
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete offline-payment proof files older than their retention period';

    public function handle(OfflinePaymentProofVault $vault): int
    {
        $confirmedCutoff = Carbon::now()->subDays($vault->retentionDays());
        $unconfirmedCutoff = Carbon::now()->subDays($vault->rejectedRetentionDays());

        $expired = OfflinePayment::query()
            ->whereNotNull('proof_storage_key')
            ->where(function (Builder $query) use ($confirmedCutoff, $unconfirmedCutoff): void {
                $query->where(fn (Builder $q) => $q->where('status', OfflinePayment::STATUS_CONFIRMED)->where('confirmed_at', '<=', $confirmedCutoff))
                    ->orWhere(fn (Builder $q) => $q->where('status', OfflinePayment::STATUS_REJECTED)->where('rejected_at', '<=', $unconfirmedCutoff))
                    ->orWhere(fn (Builder $q) => $q->where('status', OfflinePayment::STATUS_PENDING)
                        ->whereHas('order', fn (Builder $o) => $o->where('status', Order::STATUS_CANCELLED)->where('cancelled_at', '<=', $unconfirmedCutoff)));
            })
            ->orderBy('id')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No offline-payment proof is past its retention period.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn("DRY RUN — {$expired->count()} proof file(s) would be deleted.");

            return self::SUCCESS;
        }

        foreach ($expired as $payment) {
            $key = (string) $payment->proof_storage_key;

            // Record first, delete second: a row that says "purged" with the
            // file still present is re-deletable and harmless; a deleted file
            // with the row still pointing at it would be an unaudited loss.
            DB::transaction(function () use ($payment): void {
                $payment->update(['proof_storage_key' => null, 'proof_purged_at' => Carbon::now()]);

                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'offline_payment.proof_purged',
                    'subject_type' => 'order',
                    'subject_id' => $payment->order_id,
                    'before_hash' => AuditLog::digest((string) $payment->proof_sha256),
                    'details' => ['offline_payment_id' => $payment->id, 'status' => $payment->status],
                ]);
            });

            try {
                $vault->delete($key);
            } catch (Throwable $e) {
                Log::error('Offline-payment proof purge: file delete failed after the row was marked purged', [
                    'offline_payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Deleted {$expired->count()} offline-payment proof file(s).");

        return self::SUCCESS;
    }
}
