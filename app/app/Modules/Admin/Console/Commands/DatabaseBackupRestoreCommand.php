<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Modules\Admin\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Replace the database with one backup from the db-backups disk.
 *
 * A safety backup of the current database is taken first; if the import fails
 * after the drop, the site stays in maintenance mode and the error names the
 * safety backup to restore. See docs/runbooks/database-backup-restore.md.
 */
final class DatabaseBackupRestoreCommand extends Command
{
    protected $signature = 'db:backup:restore
                            {file : Backup file name, e.g. arovolife-staging-20261119-050000-scheduled.sql.gz}
                            {--actor= : User id recorded on the audit row}';

    protected $description = 'Restore the database from a backup on the db-backups disk (replaces everything)';

    public function handle(DatabaseBackupService $backups): int
    {
        $file = (string) $this->argument('file');
        $actor = $this->option('actor');
        $actorId = is_numeric($actor) ? (int) $actor : null;

        if ($backups->find($file) === null) {
            $backups->markFinished('restore', $file, false, "No backup named {$file} on this environment.");
            $this->error("No backup named {$file} on this environment.");

            return self::FAILURE;
        }

        $locked = false;

        try {
            $safety = $backups->exclusively(function () use ($backups, $file, $actorId, &$locked) {
                $locked = true;
                $backups->markRunning('restore', $file);

                return $backups->restore($file, $actorId);
            });
        } catch (Throwable $e) {
            if ($locked) {
                $backups->markFinished('restore', $file, false, $e->getMessage());
            }
            Log::error('db_backup.restore_failed', ['file' => $file, 'error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $backups->markFinished('restore', $file, true, "Restored {$file}. The database as it was before is saved as {$safety->file}.");
        Log::info('db_backup.restored', ['file' => $file, 'pre_restore_file' => $safety->file, 'actor_id' => $actorId]);
        $this->info("Restored {$file}. Safety backup: {$safety->file}.");

        return self::SUCCESS;
    }
}
