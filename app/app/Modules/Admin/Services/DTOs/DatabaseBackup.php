<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\DTOs;

use Illuminate\Support\Carbon;

/**
 * One gzipped database dump on the `db-backups` disk, read off its file name
 * (time and trigger) and the disk (size).
 */
final readonly class DatabaseBackup
{
    public function __construct(
        public string $file,
        public string $trigger,
        public Carbon $createdAt,
        public int $bytes,
    ) {}
}
