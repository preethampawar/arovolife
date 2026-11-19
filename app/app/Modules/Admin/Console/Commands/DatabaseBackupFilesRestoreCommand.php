<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Modules\Admin\Services\UploadedFilesBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copy the saved uploads (files/{disk}/ on the db-backups disk) back to a local
 * disk after a server loss. Adds missing files only: nothing is overwritten
 * and nothing is deleted. See docs/runbooks/database-backup-restore.md.
 */
final class DatabaseBackupFilesRestoreCommand extends Command
{
    protected $signature = 'db:backup:files-restore
                            {disk : Local disk to restore, e.g. public}';

    protected $description = 'Copy saved uploaded files back to a local disk (only files that are missing)';

    public function handle(UploadedFilesBackupService $files): int
    {
        $disk = (string) $this->argument('disk');
        $allowed = array_map('strval', (array) config('db_backup.file_disks', []));

        if (! in_array($disk, $allowed, true)) {
            $this->error("'{$disk}' is not a backed-up disk. Choose one of: ".implode(', ', $allowed).'.');

            return self::FAILURE;
        }

        try {
            $restored = $files->restoreMissing($disk);
        } catch (Throwable $e) {
            Log::error('db_backup.files_restore_failed', ['disk' => $disk, 'error' => $e->getMessage()]);
            $this->error('Restoring uploaded files failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('db_backup.files_restored', ['disk' => $disk, 'files' => $restored]);
        $this->info("Restored {$restored} missing file(s) to the {$disk} disk.");

        return self::SUCCESS;
    }
}
