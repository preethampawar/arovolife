<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Services\DTOs\AuditLogArchive;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

/**
 * Each finished IST calendar month of `audit_log`, written once as a gzipped
 * JSON-lines file on the `audit-archives` disk and kept there.
 *
 * Export only: nothing in this class deletes, updates or truncates an
 * `audit_log` row, and the archive disk is never pruned. Line 1 of a file is a
 * manifest, every following line is one row with its hash columns in hex, so
 * the chain can be re-checked from the file alone.
 */
final class AuditLogArchiveService
{
    /** `arovolife-{env}-audit-log-{YYYY-MM}.jsonl.gz`, without delimiters so routes can use it too. */
    public const FILE_PATTERN = 'arovolife-([a-z0-9]+)-audit-log-(\d{4}-(?:0[1-9]|1[0-2]))\.jsonl\.gz';

    private const TIMEZONE = 'Asia/Kolkata';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function isValidFileName(string $file): bool
    {
        return preg_match('/\A'.self::FILE_PATTERN.'\z/', $file) === 1;
    }

    /** An archive by name, or null when the name fails the pattern or the file is not on the disk. */
    public function find(string $file): ?AuditLogArchive
    {
        if (! self::isValidFileName($file) || ! $this->disk()->exists($file)) {
            return null;
        }

        return $this->describe($file);
    }

    /**
     * @return list<AuditLogArchive> newest month first
     */
    public function all(): array
    {
        $archives = [];
        foreach ($this->disk()->files() as $file) {
            if (self::isValidFileName($file)) {
                $archives[] = $this->describe($file);
            }
        }

        usort($archives, static fn (AuditLogArchive $a, AuditLogArchive $b): int => [$b->month, $b->file] <=> [$a->month, $a->file]);

        return $archives;
    }

    /**
     * Every finished month from the earliest audit row up to last month that
     * has no archive file yet.
     *
     * @return list<string> `YYYY-MM`, oldest first
     */
    public function pendingMonths(): array
    {
        $earliest = DB::table('audit_log')->min('created_at');
        if ($earliest === null) {
            return [];
        }

        $cursor = Carbon::parse((string) $earliest, (string) config('app.timezone'))->timezone(self::TIMEZONE)->startOfMonth();
        $lastFinished = Carbon::now(self::TIMEZONE)->startOfMonth()->subMonthNoOverflow();

        $archived = [];
        foreach ($this->all() as $archive) {
            $archived[$archive->month] = true;
        }

        $pending = [];
        while ($cursor->lte($lastFinished)) {
            if (! isset($archived[$cursor->format('Y-m')])) {
                $pending[] = $cursor->format('Y-m');
            }
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return $pending;
    }

    /**
     * Write one finished month. An existing archive is returned untouched
     * unless `$force`. Nothing is uploaded unless the file read back from disk
     * has the manifest plus exactly the expected rows.
     */
    public function archiveMonth(string $month, bool $force = false): AuditLogArchive
    {
        if (preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) !== 1) {
            throw new RuntimeException("'{$month}' is not a month in YYYY-MM form.");
        }

        $start = Carbon::create((int) substr($month, 0, 4), (int) substr($month, 5, 2), 1, 0, 0, 0, self::TIMEZONE);
        $end = $start->copy()->addMonthNoOverflow();

        if ($end->isFuture()) {
            throw new RuntimeException("{$month} has not ended yet in IST, so it cannot be archived.");
        }

        $file = sprintf('arovolife-%s-audit-log-%s.jsonl.gz', app()->environment(), $month);

        if (! $force && $this->disk()->exists($file)) {
            return $this->describe($file);
        }

        $appTimezone = (string) config('app.timezone');
        $from = $start->copy()->timezone($appTimezone)->format('Y-m-d H:i:s.v');
        $to = $end->copy()->timezone($appTimezone)->format('Y-m-d H:i:s.v');
        $range = static fn () => DB::table('audit_log')->where('created_at', '>=', $from)->where('created_at', '<', $to);

        $rows = (int) $range()->count();
        $firstId = $range()->min('id');
        $lastId = $range()->max('id');
        $firstId = $firstId === null ? null : (int) $firstId;
        $lastId = $lastId === null ? null : (int) $lastId;
        $first = $firstId === null ? null : $range()->where('id', $firstId)->first();
        $last = $lastId === null ? null : $range()->where('id', $lastId)->first();

        $local = $this->tempPath($file);

        try {
            $gz = gzopen($local, 'wb9');
            if ($gz === false) {
                throw new RuntimeException('Could not open a temporary file for the archive.');
            }

            try {
                gzwrite($gz, json_encode([
                    'manifest' => true,
                    'environment' => app()->environment(),
                    'month' => $month,
                    'timezone' => self::TIMEZONE,
                    'rows' => $rows,
                    'first_id' => $firstId,
                    'last_id' => $lastId,
                    'first_prev_hash' => $this->hex($first?->prev_hash),
                    'last_row_hash' => $this->hex($last?->row_hash),
                    'generated_at' => Carbon::now()->toIso8601String(),
                ], self::JSON_FLAGS)."\n");

                foreach ($range()->lazyById(1000) as $row) {
                    gzwrite($gz, json_encode([
                        'id' => (int) $row->id,
                        'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                        'action' => $row->action,
                        'subject_type' => $row->subject_type,
                        'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
                        'before_hash' => $this->hex($row->before_hash),
                        'after_hash' => $this->hex($row->after_hash),
                        'row_hash' => $this->hex($row->row_hash),
                        'prev_hash' => $this->hex($row->prev_hash),
                        'hash_version' => $row->hash_version === null ? null : (int) $row->hash_version,
                        'details' => $row->details === null ? null : json_decode((string) $row->details, false, 512, JSON_THROW_ON_ERROR),
                        'ip' => $row->ip,
                        'created_at' => $row->created_at,
                    ], self::JSON_FLAGS)."\n");
                }
            } finally {
                gzclose($gz);
            }

            $this->verify($local, $rows, $lastId);

            $stream = fopen($local, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Could not read the verified archive back.');
            }
            try {
                $this->disk()->writeStream($file, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return new AuditLogArchive($file, $month, (int) filesize($local), $rows);
        } finally {
            @unlink($local);
        }
    }

    /** Read the file back: manifest plus `$rows` lines, all valid JSON, ending on `$lastId`. */
    private function verify(string $local, int $rows, ?int $lastId): void
    {
        $gz = gzopen($local, 'rb');
        if ($gz === false) {
            throw new RuntimeException('The archive could not be opened to verify it.');
        }

        $lines = 0;
        $lastDecoded = null;
        try {
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                try {
                    $lastDecoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new RuntimeException('The archive holds a line that is not valid JSON, so it was not saved.');
                }
                $lines++;
            }
        } finally {
            gzclose($gz);
        }

        if ($lines !== $rows + 1) {
            throw new RuntimeException("The archive holds {$lines} lines but {$rows} rows plus a manifest were expected, so it was not saved.");
        }

        if ($lastId !== null && (! is_array($lastDecoded) || ($lastDecoded['id'] ?? null) !== $lastId)) {
            throw new RuntimeException('The archive does not end on the last row of the month, so it was not saved.');
        }
    }

    private function hex(mixed $value): ?string
    {
        return $value === null ? null : bin2hex((string) $value);
    }

    private function describe(string $file): AuditLogArchive
    {
        if (preg_match('/\A'.self::FILE_PATTERN.'\z/', $file, $m) !== 1) {
            throw new RuntimeException("'{$file}' is not an audit log archive file name.");
        }

        return new AuditLogArchive(file: $file, month: $m[2], bytes: $this->disk()->size($file));
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
        return Storage::disk((string) config('db_backup.audit_archive_disk', 'audit-archives'));
    }
}
