<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Erase the premises documents of rejected ADC applications once the
 * retention window has passed (DPDP Act 2023 §8(7); risk register R-62).
 *
 * The application row itself is kept — it is the record that a decision was
 * taken and why — but the deed, bills and photos have no purpose once the
 * application is closed and the grievance window has run.
 */
final class AdcPurgeRejectedDocumentsCommand extends Command
{
    public const int RETENTION_DAYS = 90;

    protected $signature = 'adc:purge-rejected-documents {--days= : Override the retention window (default 90)}';

    protected $description = 'Delete uploaded documents of ADC applications rejected more than 90 days ago';

    public function handle(AreteCenterApplicationService $applications): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : self::RETENTION_DAYS;
        $cutoff = Carbon::now()->subDays($days);

        $candidates = AreteCenterApplication::query()
            ->where('status', AreteCenterApplication::STATUS_REJECTED)
            ->where('reviewed_at', '<=', $cutoff)
            ->whereHas('documents')
            ->get();

        $files = 0;
        foreach ($candidates as $application) {
            $removed = $applications->purgeDocuments($application);
            $files += $removed;

            AuditLog::create([
                'actor_id' => null,
                'action' => 'adc.application.documents_purged',
                'subject_type' => 'arete_center_application',
                'subject_id' => $application->id,
                'details' => ['documents' => $removed, 'retention_days' => $days],
                'ip' => null,
            ]);
        }

        $this->info("Purged {$files} document(s) across {$candidates->count()} rejected application(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
