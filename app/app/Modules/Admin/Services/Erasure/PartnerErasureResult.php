<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Erasure;

/**
 * Outcome of a completed erasure. Files are removed after the commit and
 * best-effort, so a failed file delete never undoes the database erase.
 */
final readonly class PartnerErasureResult
{
    public function __construct(
        public int $erasedCount,
        public int $filesDeleted,
        public int $filesFailed,
        public ?string $backupFile,
    ) {}
}
