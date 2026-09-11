<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycDocumentVault;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Delete KYC scans that have outlived the published retention period
 * (DPDP Act 2023 §8(7); risk register R-31; client decision 2026-09-11).
 *
 * The clock runs from the review the scan served: `verified_at` for an
 * approved document, the upload time for one that was never verified. The
 * period is the admin-owned setting `kyc.document_retention_days`. The
 * distributor row, the PAN hash and the last-4 digits are untouched — only
 * the images go, and the audit log records which ones.
 */
final class PurgeExpiredDocumentsCommand extends Command
{
    protected $signature = 'kyc:purge-expired-documents
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete KYC document scans older than the published retention period';

    public function handle(KycDocumentVault $vault): int
    {
        $days = $vault->retentionDays();
        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $expired = KycDocument::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('verified_at', '<=', $cutoff)
                    ->orWhere(function ($pending) use ($cutoff): void {
                        $pending->whereNull('verified_at')->where('created_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->get();

        if ($expired->isEmpty()) {
            $this->info("Nothing past the {$days}-day retention period.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("DRY RUN — {$expired->count()} document(s) older than {$days} days would be deleted.");

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($expired->groupBy('distributor_id') as $distributorId => $documents) {
            $ids = [];

            /** @var KycDocument $document */
            foreach ($documents as $document) {
                $before = AuditDigests::of($document);
                $vault->delete($document->object_storage_key);
                $document->delete();
                $ids[] = ['id' => (int) $document->id, 'type' => $document->type, 'digest' => bin2hex($before)];
                $deleted++;
            }

            AuditLog::create([
                'actor_id' => null,
                'action' => 'kyc.documents.retention_purged',
                'subject_type' => 'distributor',
                'subject_id' => (int) $distributorId,
                'details' => ['documents' => $ids, 'retention_days' => $days],
                'ip' => null,
            ]);
        }

        $this->info("Deleted {$deleted} document(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
