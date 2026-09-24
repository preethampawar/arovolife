<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Services\PayoutBankFileVault;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delete stored payout bank files that have outlived their retention period
 * (DPDP Act 2023 §8(7)).
 *
 * The clock is the admin-owned setting `payout.bank_file_retention_days`,
 * counted from the download or upload. Only the encrypted file goes: the
 * file's row and its parsed rows stay, so the batch timeline and the import
 * comparisons still read the same afterwards.
 */
final class PurgeExpiredPayoutBankFilesCommand extends Command
{
    protected $signature = 'payout:purge-expired-bank-files
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete stored payout bank files older than their retention period';

    public function handle(PayoutBankFileVault $vault): int
    {
        $cutoff = Carbon::now()->subDays($vault->retentionDays());

        // Past retention, or already recorded as purged but whose object
        // delete failed last time — the key is only cleared once it is gone.
        $expired = PayoutBankFile::query()
            ->whereNotNull('storage_key')
            ->where(fn ($q) => $q->where('created_at', '<=', $cutoff)->orWhereNotNull('purged_at'))
            ->orderBy('id')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No payout bank file is past its retention period.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn("DRY RUN — {$expired->count()} bank file(s) would be deleted.");

            return self::SUCCESS;
        }

        foreach ($expired as $file) {
            $key = (string) $file->storage_key;

            // Record first, delete second, clear the key last: a row that says
            // "purged" but still holds its key is retried by the next run; a
            // deleted object with no record would be an unaudited loss.
            if ($file->purged_at === null) {
                DB::transaction(function () use ($file): void {
                    $file->forceFill(['purged_at' => Carbon::now()])->save();

                    AuditLog::create([
                        'actor_id' => null,
                        'action' => 'payout.bank_file.purged',
                        'subject_type' => 'payout_batch',
                        'subject_id' => (int) $file->payout_batch_id,
                        // The file's own SHA-256 (the model stores the hex as the
                        // same 32 bytes), so this row matches the digest on the
                        // export or download that handled the file.
                        'before_hash' => $file->sha256,
                        'details' => [
                            'payout_bank_file_id' => (int) $file->id,
                            'direction' => $file->direction,
                        ],
                    ]);
                });
            }

            try {
                $vault->delete($key);
            } catch (Throwable $e) {
                Log::error('Payout bank file purge: object delete failed — the next run retries it', [
                    'payout_bank_file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $file->forceFill(['storage_key' => null])->save();
        }

        $this->info("Deleted {$expired->count()} payout bank file(s).");

        return self::SUCCESS;
    }
}
