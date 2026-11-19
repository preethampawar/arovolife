<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\DTOs;

/**
 * One month of the audit log, saved as a gzipped JSON-lines file on the
 * `audit-archives` disk. `month` is `YYYY-MM` (IST); `rows` is known only for
 * an archive written in this run — listing never opens the files.
 */
final readonly class AuditLogArchive
{
    public function __construct(
        public string $file,
        public string $month,
        public int $bytes,
        public ?int $rows = null,
    ) {}
}
