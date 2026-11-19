<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Services\DTOs\DatabaseBackup;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The server settings file (.env) as a password-protected ZIP on the backup
 * disk, beside the database dumps. A dump alone cannot be read back on a new
 * server without the encryption keys in this file.
 *
 * An unprotected copy is never written: with no usable password, or no
 * settings file, nothing is created. The password and the file's contents
 * never reach a log line, exception message or output.
 */
final class SettingsFileBackupService
{
    /** `arovolife-{env}-{Ymd-His}-{trigger}.env.zip`, without delimiters so routes can use it too. */
    public const FILE_PATTERN = 'arovolife-([a-z0-9]+)-(\d{8}-\d{6})-(scheduled|manual|pre-restore)\.env\.zip';

    public const MIN_PASSWORD_LENGTH = 20;

    private const ENTRY = '.env';

    private const TIMEZONE = 'Asia/Kolkata';

    public static function isValidFileName(string $file): bool
    {
        return preg_match('/\A'.self::FILE_PATTERN.'\z/', $file) === 1;
    }

    public static function passwordIsUsable(): bool
    {
        $password = config('db_backup.settings_password');

        return is_string($password) && strlen(trim($password)) >= self::MIN_PASSWORD_LENGTH;
    }

    /** A settings copy by name, or null when the name fails the pattern or the file is not on the disk. */
    public function find(string $file): ?DatabaseBackup
    {
        if (! self::isValidFileName($file) || ! $this->disk()->exists($file)) {
            return null;
        }

        return $this->describe($file);
    }

    /**
     * @return list<DatabaseBackup>
     */
    public function all(): array
    {
        $copies = [];
        foreach ($this->disk()->files() as $file) {
            if (self::isValidFileName($file)) {
                $copies[] = $this->describe($file);
            }
        }

        usort($copies, static fn (DatabaseBackup $a, DatabaseBackup $b): int => [$b->createdAt->getTimestamp(), $b->file] <=> [$a->createdAt->getTimestamp(), $a->file]);

        return $copies;
    }

    /**
     * Zip the settings file with AES-256, prove the copy opens with the
     * password and matches the source byte for byte, then upload it.
     *
     * @throws SettingsFileBackupSkipped when there is no usable password or no settings file
     */
    public function create(string $trigger, Carbon $takenAt): DatabaseBackup
    {
        if (! in_array($trigger, DatabaseBackupService::TRIGGERS, true)) {
            throw new RuntimeException("Unknown backup trigger '{$trigger}'.");
        }

        if (! self::passwordIsUsable()) {
            throw new SettingsFileBackupSkipped(
                trim((string) config('db_backup.settings_password')) === ''
                    ? 'no password is set on the server'
                    : 'the password is shorter than '.self::MIN_PASSWORD_LENGTH.' characters'
            );
        }

        $password = trim((string) config('db_backup.settings_password'));
        $source = (string) config('db_backup.settings_file');
        $content = is_file($source) ? file_get_contents($source) : false;
        if ($content === false) {
            throw new SettingsFileBackupSkipped('the settings file was not found');
        }

        $file = sprintf('arovolife-%s-%s-%s.env.zip', app()->environment(), $takenAt->format('Ymd-His'), $trigger);
        $local = $this->tempPath($file);

        try {
            $this->buildZip($local, $content, $password);
            $this->verifyZip($local, $content, $password);

            $stream = fopen($local, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Could not read the verified settings copy back.');
            }
            try {
                $this->disk()->writeStream($file, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return new DatabaseBackup($file, $trigger, $takenAt, (int) filesize($local));
        } finally {
            @unlink($local);
        }
    }

    /** Delete every settings copy older than the retention period; only pattern-matching files are ever touched. */
    public function prune(): int
    {
        $cutoff = Carbon::now(self::TIMEZONE)->subDays((int) config('db_backup.retention_days', 30));
        $pruned = 0;

        foreach ($this->all() as $copy) {
            if ($copy->createdAt->lt($cutoff)) {
                $this->disk()->delete($copy->file);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function buildZip(string $local, string $content, string $password): void
    {
        $zip = new ZipArchive;
        if ($zip->open($local, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the settings copy.');
        }

        $ok = $zip->addFromString(self::ENTRY, $content)
            && $zip->setEncryptionName(self::ENTRY, ZipArchive::EM_AES_256, $password);
        $closed = $zip->close();

        if (! $ok || ! $closed) {
            throw new RuntimeException('Could not write the password-protected settings copy.');
        }
    }

    private function verifyZip(string $local, string $content, string $password): void
    {
        $zip = new ZipArchive;
        if ($zip->open($local, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The settings copy could not be opened to verify it.');
        }

        try {
            // Without the password the entry must not be readable.
            $unprotected = $zip->getFromName(self::ENTRY);
            if ($unprotected !== false) {
                throw new RuntimeException('The settings copy is not password-protected.');
            }

            if (! $zip->setPassword($password)) {
                throw new RuntimeException('The settings copy could not be verified.');
            }
            $extracted = $zip->getFromName(self::ENTRY);
        } finally {
            $zip->close();
        }

        if ($extracted === false || ! hash_equals(hash('sha256', $content), hash('sha256', $extracted))) {
            throw new RuntimeException('The settings copy does not match the settings file, so it was not saved.');
        }
    }

    private function describe(string $file): DatabaseBackup
    {
        if (preg_match('/\A'.self::FILE_PATTERN.'\z/', $file, $m) !== 1) {
            throw new RuntimeException("'{$file}' is not a settings copy file name.");
        }

        return new DatabaseBackup(
            file: $file,
            trigger: $m[3],
            createdAt: Carbon::createFromFormat('Ymd-His', $m[2], self::TIMEZONE) ?: Carbon::now(self::TIMEZONE),
            bytes: $this->disk()->size($file),
        );
    }

    private function tempPath(string $file): string
    {
        $directory = storage_path('app/private/db-backups-tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory.'/'.bin2hex(random_bytes(6)).'-'.$file;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('db_backup.disk', 'db-backups'));
    }
}
