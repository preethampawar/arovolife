<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Services\DTOs\DatabaseBackup;
use App\Modules\Compliance\Models\AuditLog;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Developer settings → Database backups: a gzipped mysqldump of the default
 * connection on the `db-backups` disk, and a restore that puts one back.
 *
 * There is no table of backups — a restore replaces the whole database, so a
 * table would be rolled back with it. The disk listing is the record, and the
 * running/last-result status lives in the cache as plain scalars (cached
 * objects do not survive `serializable_classes=false`).
 *
 * The password reaches the clients only through `MYSQL_PWD`, never argv, so it
 * cannot appear in a process list or a logged command line.
 */
final class DatabaseBackupService
{
    /** `arovolife-{env}-{Ymd-His}-{trigger}.sql.gz`, without delimiters so routes can use it too. */
    public const FILE_PATTERN = 'arovolife-([a-z0-9]+)-(\d{8}-\d{6})-(scheduled|manual|pre-restore)\.sql\.gz';

    public const TRIGGERS = ['scheduled', 'manual', 'pre-restore'];

    private const STATUS_KEY = 'db-backup:status';

    private const LOCK_KEY = 'db-backup:operation';

    /** Longest a backup or restore may hold the lock before it is presumed dead. */
    private const LOCK_SECONDS = 4 * 3600;

    /** mysqldump's own last line; a dump that stops early never writes it. */
    private const DUMP_TRAILER = '-- Dump completed';

    private const TIMEZONE = 'Asia/Kolkata';

    public static function isValidFileName(string $file): bool
    {
        return preg_match('/\A'.self::FILE_PATTERN.'\z/', $file) === 1;
    }

    /** A backup by name, or null when the name fails the pattern or the file is not on the disk. */
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
        $backups = [];
        foreach ($this->disk()->files() as $file) {
            if (self::isValidFileName($file)) {
                $backups[] = $this->describe($file);
            }
        }

        usort($backups, static fn (DatabaseBackup $a, DatabaseBackup $b): int => [$b->createdAt->getTimestamp(), $b->file] <=> [$a->createdAt->getTimestamp(), $a->file]);

        return $backups;
    }

    /**
     * Dump, gzip, verify and upload. Nothing is uploaded unless mysqldump
     * exited 0 and the decompressed dump ends with its completion trailer.
     */
    public function create(string $trigger): DatabaseBackup
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new RuntimeException("Unknown backup trigger '{$trigger}'.");
        }

        $takenAt = Carbon::now(self::TIMEZONE)->startOfSecond();
        $file = sprintf('arovolife-%s-%s-%s.sql.gz', app()->environment(), $takenAt->format('Ymd-His'), $trigger);
        $local = $this->tempPath($file);

        try {
            $connection = DB::connection();
            $gz = gzopen($local, 'wb9');
            if ($gz === false) {
                throw new RuntimeException('Could not open a temporary file for the dump.');
            }

            $stderr = '';
            try {
                $result = Process::forever()
                    ->env(['MYSQL_PWD' => (string) $connection->getConfig('password')])
                    ->start($this->dumpCommand($connection), static function (string $type, string $buffer) use ($gz, &$stderr): void {
                        if ($type === 'out') {
                            gzwrite($gz, $buffer);
                        } elseif (strlen($stderr) < 4000) {
                            $stderr .= $buffer;
                        }
                    })
                    ->wait();
            } finally {
                gzclose($gz);
            }

            if (! $result->successful()) {
                throw new RuntimeException('mysqldump exited with code '.$result->exitCode().': '.trim($stderr));
            }

            $this->verify($local);

            $stream = fopen($local, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Could not read the verified dump back.');
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

    /** Delete every backup older than the retention period; only pattern-matching files are ever touched. */
    public function prune(): int
    {
        $cutoff = Carbon::now(self::TIMEZONE)->subDays((int) config('db_backup.retention_days', 30));
        $pruned = 0;

        foreach ($this->all() as $backup) {
            if ($backup->createdAt->lt($cutoff)) {
                $this->disk()->delete($backup->file);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Replace the whole database with a backup. Returns the safety backup of
     * the database as it was, taken before anything was dropped.
     *
     * Until the drop, any failure leaves the database untouched and the site
     * up. After the drop, a failed import leaves the site in maintenance mode
     * on purpose: a half-imported database must not take traffic.
     */
    public function restore(string $file, ?int $actorId): DatabaseBackup
    {
        $backup = $this->find($file) ?? throw new RuntimeException("No backup named '{$file}' on this environment.");
        $local = $this->tempPath($file);

        try {
            $source = $this->disk()->readStream($file);
            $target = fopen($local, 'wb');
            if ($source === null || $target === false) {
                throw new RuntimeException('Could not download the backup.');
            }
            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            $this->verify($local);

            Artisan::call('down');

            try {
                $safety = $this->create('pre-restore');
            } catch (Throwable $e) {
                Artisan::call('up');

                throw new RuntimeException('The safety backup failed, so nothing was changed: '.$e->getMessage(), 0, $e);
            }

            try {
                Schema::disableForeignKeyConstraints();
                Schema::dropAllViews();
                Schema::dropAllTables();
                $this->import($local);
            } catch (Throwable $e) {
                Log::critical('db_backup.restore_failed_after_drop', ['file' => $file, 'pre_restore_file' => $safety->file, 'error' => $e->getMessage()]);

                throw new RuntimeException("Restoring {$file} failed after the database was cleared. The site is still in maintenance mode. Recover with: php artisan db:backup:restore {$safety->file} — then php artisan up.", 0, $e);
            }

            DB::purge();
            DB::reconnect();

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'admin.database_restored',
                'subject_type' => 'database_backup',
                'subject_id' => null,
                'before_hash' => AuditLog::digest($safety->file),
                'after_hash' => AuditLog::digest($file),
                'details' => ['file' => $file, 'pre_restore_file' => $safety->file, 'bytes' => $backup->bytes],
                'ip' => null,
            ]);

            Artisan::call('up');

            return $safety;
        } finally {
            @unlink($local);
        }
    }

    /**
     * Run one backup or restore under the operation lock, refusing a second.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function exclusively(Closure $operation): mixed
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        // A short wait, not a single try: the page's own isBusy() probe holds
        // this lock for an instant, and a command that starts in that instant
        // must not be turned away by it.
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Another database backup or restore is already running. Wait for it to finish.');
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    public function isBusy(): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 10);
        if (! $lock->get()) {
            return true;
        }
        $lock->release();

        return false;
    }

    /**
     * The current or last operation. A `running` status with no lock held for
     * over five minutes is reported as failed — the process died or never
     * started, and the page would otherwise refresh forever.
     *
     * @return array{type: string, file: ?string, state: string, started_at: ?string, finished_at: ?string, message: ?string}|null
     */
    public function status(): ?array
    {
        $cached = Cache::get(self::STATUS_KEY);
        if (! is_array($cached)) {
            return null;
        }

        $status = [
            'type' => (string) ($cached['type'] ?? 'backup'),
            'file' => isset($cached['file']) ? (string) $cached['file'] : null,
            'state' => (string) ($cached['state'] ?? 'failed'),
            'started_at' => isset($cached['started_at']) ? (string) $cached['started_at'] : null,
            'finished_at' => isset($cached['finished_at']) ? (string) $cached['finished_at'] : null,
            'message' => isset($cached['message']) ? (string) $cached['message'] : null,
        ];

        if ($status['state'] === 'running' && ! $this->isBusy()
            && ($status['started_at'] === null || Carbon::parse($status['started_at'])->lt(Carbon::now()->subMinutes(5)))) {
            $status['state'] = 'failed';
            $status['message'] = 'The operation stopped without reporting a result. Check the application log.';
        }

        return $status;
    }

    public function markRunning(string $type, ?string $file): void
    {
        Cache::forever(self::STATUS_KEY, [
            'type' => $type,
            'file' => $file,
            'state' => 'running',
            'started_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
            'finished_at' => null,
            'message' => null,
        ]);
    }

    public function markFinished(string $type, ?string $file, bool $succeeded, string $message): void
    {
        $previous = Cache::get(self::STATUS_KEY);

        Cache::forever(self::STATUS_KEY, [
            'type' => $type,
            'file' => $file,
            'state' => $succeeded ? 'succeeded' : 'failed',
            'started_at' => is_array($previous) ? ($previous['started_at'] ?? null) : null,
            'finished_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
            'message' => $message,
        ]);
    }

    /**
     * Start an artisan command detached from this request, so a web request
     * never runs the dump or the import itself (FPM would time it out).
     *
     * @param  list<string>  $arguments
     */
    public function startInBackground(string $command, array $arguments): void
    {
        $php = (string) (config('db_backup.php_binary') ?: (new PhpExecutableFinder)->find(false) ?: 'php');
        $line = implode(' ', array_map('escapeshellarg', [$php, 'artisan', $command, ...$arguments]));

        Process::path(base_path())->run("nohup {$line} > /dev/null 2>&1 &");
    }

    private function import(string $local): void
    {
        $input = fopen('compress.zlib://'.$local, 'rb');
        if ($input === false) {
            throw new RuntimeException('Could not open the backup for import.');
        }

        try {
            $connection = DB::connection();
            $result = Process::forever()
                ->env(['MYSQL_PWD' => (string) $connection->getConfig('password')])
                ->input($input)
                ->run([
                    (string) config('db_backup.mysql_binary', 'mysql'),
                    ...$this->connectionOptions($connection),
                    (string) $connection->getDatabaseName(),
                ]);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        if (! $result->successful()) {
            throw new RuntimeException('mysql exited with code '.$result->exitCode().': '.mb_substr(trim($result->errorOutput()), 0, 4000));
        }
    }

    /**
     * Read the whole gzip stream and require mysqldump's trailer at its end;
     * this also proves the gzip is not truncated or corrupt.
     */
    private function verify(string $local): void
    {
        $gz = gzopen($local, 'rb');
        if ($gz === false) {
            throw new RuntimeException('The backup could not be opened as gzip.');
        }

        $tail = '';
        try {
            while (! gzeof($gz)) {
                $chunk = gzread($gz, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('The backup is not a readable gzip file.');
                }
                $tail = substr($tail.$chunk, -512);
            }
        } finally {
            gzclose($gz);
        }

        if (! str_contains($tail, self::DUMP_TRAILER)) {
            throw new RuntimeException('The dump is incomplete: it does not end with mysqldump\'s completion line.');
        }
    }

    /**
     * @return list<string>
     */
    private function dumpCommand(Connection $connection): array
    {
        return [
            (string) config('db_backup.mysqldump_binary', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--triggers',
            '--no-tablespaces',
            '--skip-lock-tables',
            ...$this->connectionOptions($connection),
            (string) $connection->getDatabaseName(),
        ];
    }

    /**
     * @return list<string>
     */
    private function connectionOptions(Connection $connection): array
    {
        $extra = preg_split('/\s+/', trim((string) config('db_backup.extra_client_options', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            '--default-character-set=utf8mb4',
            '--host='.$connection->getConfig('host'),
            '--port='.$connection->getConfig('port'),
            '--user='.$connection->getConfig('username'),
            ...$extra,
        ];
    }

    private function describe(string $file): DatabaseBackup
    {
        if (preg_match('/\A'.self::FILE_PATTERN.'\z/', $file, $m) !== 1) {
            throw new RuntimeException("'{$file}' is not a backup file name.");
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
