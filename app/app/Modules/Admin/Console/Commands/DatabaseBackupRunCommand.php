<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Modules\Admin\Notifications\DatabaseBackupStatusNotification;
use App\Modules\Admin\Services\AuditLogArchiveService;
use App\Modules\Admin\Services\DatabaseBackupService;
use App\Modules\Admin\Services\DTOs\UploadedFilesMirrorResult;
use App\Modules\Admin\Services\SettingsFileBackupService;
use App\Modules\Admin\Services\SettingsFileBackupSkipped;
use App\Modules\Admin\Services\UploadedFilesBackupService;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

/**
 * Take a database backup, then prune the ones past retention.
 *
 * Pruning runs only after a successful backup, so a run of failures can never
 * eat into the backups that still exist. After the database backup, in the
 * same lock, the password-protected settings-file copy and the uploaded-files
 * mirror each run on their own: a failure of either is logged and reported
 * but never fails or undoes the database backup. The scheduled run mails every
 * active admin and developer the result, one mail each.
 */
final class DatabaseBackupRunCommand extends Command
{
    protected $signature = 'db:backup:run
                            {--trigger=scheduled : scheduled or manual}
                            {--no-mail : Send no notification}';

    protected $description = 'Back up the database to the db-backups disk and prune backups past retention';

    public function handle(DatabaseBackupService $backups, SettingsFileBackupService $settingsCopies, UploadedFilesBackupService $files, AuditLogArchiveService $auditArchives): int
    {
        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, ['scheduled', 'manual'], true)) {
            $this->error('--trigger must be scheduled or manual.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $takenAt = Carbon::now('Asia/Kolkata')->format('d M Y, h:i A');
        $locked = false;

        try {
            [$backup, $pruned, $settings, $mirror] = $backups->exclusively(function () use ($backups, $settingsCopies, $files, $trigger, &$locked): array {
                $locked = true;
                $backups->markRunning('backup', null);
                $backup = $backups->create($trigger);
                $pruned = $backups->prune();

                return [$backup, $pruned, $this->copySettingsFile($settingsCopies, $trigger, $backup->createdAt), $this->mirrorUploads($files)];
            });
        } catch (Throwable $e) {
            if ($locked) {
                $backups->markFinished('backup', null, false, $e->getMessage());
            }
            Log::error('db_backup.failed', ['trigger' => $trigger, 'error' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            $this->notify($trigger, new DatabaseBackupStatusNotification(
                succeeded: false,
                environment: app()->environment(),
                takenAt: $takenAt,
                file: null,
                bytes: null,
                durationSeconds: microtime(true) - $startedAt,
                heldCount: $this->heldCount($backups),
                prunedCount: 0,
                error: $e->getMessage(),
            ));

            return self::FAILURE;
        }

        $size = Number::fileSize($backup->bytes, 1);
        $settingsClause = $settings['bytes'] !== null
            ? 'settings file copy saved ('.Number::fileSize($settings['bytes'], 1).')'
            : 'settings file copy NOT saved ('.$settings['error'].')';
        $filesLine = $mirror['error'] === null ? $this->filesLine($mirror['result']) : 'copy FAILED — '.$mirror['error'];
        $filesClause = 'uploaded files: '.$filesLine;

        $backups->markFinished('backup', $backup->file, true, "Backed up to {$backup->file} ({$size}); {$pruned} old backup(s) pruned; {$settingsClause}; {$filesClause}.");
        Log::info('db_backup.created', ['file' => $backup->file, 'bytes' => $backup->bytes, 'pruned' => $pruned]);
        $this->info("Backed up to {$backup->file} ({$size}); {$pruned} pruned.");
        $this->line(ucfirst($settingsClause).'.');
        $this->line(ucfirst($filesClause).'.');

        $this->notify($trigger, new DatabaseBackupStatusNotification(
            succeeded: true,
            environment: app()->environment(),
            takenAt: $takenAt,
            file: $backup->file,
            bytes: $backup->bytes,
            durationSeconds: microtime(true) - $startedAt,
            heldCount: $this->heldCount($backups),
            prunedCount: $pruned,
            settingsBytes: $settings['bytes'],
            settingsError: $settings['error'],
            filesLine: $filesLine,
            auditMonthsPending: $this->auditMonthsPending($auditArchives),
        ));

        return self::SUCCESS;
    }

    /**
     * The settings-file copy and its pruning. Never throws: the database
     * backup is already safe on the disk. A skip carries a fixed phrase, an
     * unexpected failure its message (which holds neither password nor contents).
     *
     * @return array{bytes: ?int, error: ?string}
     */
    private function copySettingsFile(SettingsFileBackupService $settingsCopies, string $trigger, Carbon $takenAt): array
    {
        try {
            $result = ['bytes' => $settingsCopies->create($trigger, $takenAt)->bytes, 'error' => null];
        } catch (SettingsFileBackupSkipped $e) {
            Log::error('db_backup.settings_copy_skipped', ['error' => $e->getMessage()]);
            $result = ['bytes' => null, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('db_backup.settings_copy_failed', ['error' => $e->getMessage()]);
            $result = ['bytes' => null, 'error' => $e->getMessage()];
        }

        try {
            $settingsCopies->prune();
        } catch (Throwable $e) {
            Log::error('db_backup.settings_prune_failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /** @return array{result: ?UploadedFilesMirrorResult, error: ?string} */
    private function mirrorUploads(UploadedFilesBackupService $files): array
    {
        try {
            return ['result' => $files->mirror(), 'error' => null];
        } catch (Throwable $e) {
            Log::error('db_backup.files_mirror_failed', ['error' => $e->getMessage()]);

            return ['result' => null, 'error' => $e->getMessage()];
        }
    }

    private function filesLine(?UploadedFilesMirrorResult $result): string
    {
        return $result === null ? '' : "{$result->copied} new file(s) copied (".Number::fileSize($result->bytes, 1)."), {$result->alreadyPresent} already saved";
    }

    /**
     * One mail per recipient, never a shared To line. A failure here (the
     * database may be what broke) is logged and never changes the exit code.
     */
    private function notify(string $trigger, DatabaseBackupStatusNotification $notification): void
    {
        if ($trigger !== 'scheduled' || $this->option('no-mail')) {
            return;
        }

        try {
            $recipients = User::query()
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'developer']))
                ->get();

            foreach ($recipients as $recipient) {
                $recipient->notify($notification);
            }
        } catch (Throwable $e) {
            Log::critical('db_backup.notify_failed', ['error' => $e->getMessage()]);
        }
    }

    /** Finished audit-log months with no archive yet; null when that cannot be worked out. */
    private function auditMonthsPending(AuditLogArchiveService $auditArchives): ?int
    {
        try {
            return count($auditArchives->pendingMonths());
        } catch (Throwable) {
            return null;
        }
    }

    private function heldCount(DatabaseBackupService $backups): int
    {
        try {
            return count($backups->all());
        } catch (Throwable) {
            return 0;
        }
    }
}
