<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Modules\Admin\Services\AuditLogArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

/**
 * Save each finished month of the audit log as its own file on the archive
 * disk. Export only: no audit row is ever removed. A failed month is logged
 * and reported, and the next one still runs.
 */
final class AuditLogArchiveCommand extends Command
{
    protected $signature = 'audit-log:archive
                            {--month= : YYYY-MM; default is every finished month not yet archived}
                            {--force : Rewrite an existing archive}';

    protected $description = 'Archive finished months of the audit log to the audit-archives disk (export only)';

    public function handle(AuditLogArchiveService $archives): int
    {
        $month = $this->option('month');

        if ($month !== null && preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', (string) $month) !== 1) {
            $this->error('--month must be in YYYY-MM form.');

            return self::FAILURE;
        }

        try {
            $months = $month !== null ? [(string) $month] : $archives->pendingMonths();
        } catch (Throwable $e) {
            Log::critical('audit_archive.failed', ['month' => null, 'error' => $e->getMessage()]);
            $this->error('Could not work out which months are pending: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($months === []) {
            $this->info('No audit log months are waiting to be archived.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($months as $m) {
            try {
                $archive = $archives->archiveMonth($m, (bool) $this->option('force'));
                $rows = $archive->rows === null ? 'already archived' : $archive->rows.' row(s)';
                $this->info("{$archive->file}: {$rows}, ".Number::fileSize($archive->bytes, 1));
                if ($archive->rows !== null) {
                    Log::info('audit_archive.created', ['month' => $m, 'file' => $archive->file, 'rows' => $archive->rows, 'bytes' => $archive->bytes]);
                }
            } catch (Throwable $e) {
                $failed = true;
                Log::critical('audit_archive.failed', ['month' => $m, 'error' => $e->getMessage()]);
                $this->error("{$m} failed: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
