<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycDocumentVault;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Delete the KYC scans of registrations nobody finished (DPDP Act 2023
 * §8(7) storage limitation).
 *
 * The wizard writes scans to `reg_<session id>/` on the kyc disk before any
 * distributor exists; `POST /register/complete` then points kyc_documents
 * rows at those keys. A folder no row points at, whose newest file is older
 * than the window, belongs to a registration that can no longer finish — the
 * wizard state lived in a session that has long expired. Completed
 * registrations keep their `reg_` folder, so the row check is what protects
 * them; `kyc:purge-expired-documents` handles those once their retention
 * period runs.
 *
 * The audit row records counts only: a folder name carries a session id.
 */
final class PurgeAbandonedRegistrationUploadsCommand extends Command
{
    public const int ABANDONED_AFTER_HOURS = 24;

    protected $signature = 'kyc:purge-abandoned-uploads
        {--hours= : Override the abandonment window (default 24; never below the session lifetime)}
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete KYC scans uploaded by registrations that were never completed';

    public function handle(KycDocumentVault $vault): int
    {
        // A session idle for longer than its lifetime is gone, so a shorter
        // window could delete the scans of a joiner who is still mid-wizard.
        $floor = (int) ceil((int) config('session.lifetime') / 60) + 1;
        $hours = $this->option('hours') !== null ? max($floor, (int) $this->option('hours')) : self::ABANDONED_AFTER_HOURS;
        $cutoff = Carbon::now()->subHours($hours)->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');
        $disk = $vault->disk();

        $folders = 0;
        $files = 0;

        foreach ($disk->directories() as $prefix) {
            // Same allowlist as PlatformResetAction: session ids are mixed-case alphanumerics.
            if (! preg_match('/^reg_[A-Za-z0-9]+$/', $prefix)) {
                continue;
            }

            $referenced = KycDocument::query()
                ->where('object_storage_key', 'like', str_replace('_', '\\_', $prefix).'/%')
                ->exists();
            if ($referenced) {
                continue;
            }

            $paths = $disk->allFiles($prefix);
            $newest = collect($paths)->map(fn (string $path): int => $disk->lastModified($path))->max() ?? 0;
            if ($newest > $cutoff) {
                continue;
            }

            if (! $dryRun) {
                $disk->deleteDirectory($prefix);
            }
            $folders++;
            $files += count($paths);
        }

        if ($dryRun) {
            $this->warn("DRY RUN — {$folders} abandoned registration folder(s), {$files} file(s), older than {$hours} hours would be deleted.");

            return self::SUCCESS;
        }

        if ($folders > 0) {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'kyc.abandoned_uploads.purged',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => ['folders' => $folders, 'files' => $files, 'abandoned_after_hours' => $hours],
                'ip' => null,
            ]);
        }

        $this->info("Deleted {$folders} abandoned registration folder(s), {$files} file(s), older than {$hours} hours.");

        return self::SUCCESS;
    }
}
