<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Services\DTOs\UploadedFilesMirrorResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Locally stored uploads (the catalogue images on the `public` disk) copied to
 * the backup disk under `files/{disk}/…`, and back again after a server loss.
 *
 * Additive only, in both directions: a file is copied when it is absent on the
 * other side (mirror: or its size differs); nothing is ever overwritten on
 * restore and nothing is ever deleted on either side. The listing of database
 * backups reads only the disk root, so this tree never appears among them.
 */
final class UploadedFilesBackupService
{
    private const PREFIX = 'files';

    public function mirror(): UploadedFilesMirrorResult
    {
        $backup = $this->backupDisk();
        $copied = 0;
        $bytes = 0;
        $present = 0;

        foreach ($this->disks() as $name) {
            $local = Storage::disk($name);

            foreach ($local->allFiles() as $path) {
                if (str_starts_with(basename($path), '.')) {
                    continue;
                }

                $target = self::PREFIX.'/'.$name.'/'.$path;
                $size = $local->size($path);

                if ($backup->exists($target) && $backup->size($target) === $size) {
                    $present++;

                    continue;
                }

                $this->copy($local, $path, $backup, $target);
                $copied++;
                $bytes += $size;
            }
        }

        return new UploadedFilesMirrorResult($copied, $bytes, $present);
    }

    /** Copy back every saved file that is absent on the local disk; returns how many. */
    public function restoreMissing(string $disk): int
    {
        if (! in_array($disk, $this->disks(), true)) {
            throw new RuntimeException("'{$disk}' is not a disk that is backed up.");
        }

        $backup = $this->backupDisk();
        $local = Storage::disk($disk);
        $prefix = self::PREFIX.'/'.$disk.'/';
        $restored = 0;

        foreach ($backup->allFiles($prefix) as $saved) {
            $path = substr($saved, strlen($prefix));
            if ($path === '' || $local->exists($path)) {
                continue;
            }

            $this->copy($backup, $saved, $local, $path);
            $restored++;
        }

        return $restored;
    }

    /** @return list<string> */
    private function disks(): array
    {
        return array_values(array_map('strval', (array) config('db_backup.file_disks', [])));
    }

    private function copy(Filesystem $from, string $source, Filesystem $to, string $target): void
    {
        $stream = $from->readStream($source);
        if ($stream === null || $stream === false) {
            throw new RuntimeException("Could not read {$source}.");
        }

        try {
            $written = $to->writeStream($target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new RuntimeException("Could not write {$target}.");
        }
    }

    private function backupDisk(): Filesystem
    {
        return Storage::disk((string) config('db_backup.disk', 'db-backups'));
    }
}
