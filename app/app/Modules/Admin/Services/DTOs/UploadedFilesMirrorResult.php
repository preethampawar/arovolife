<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\DTOs;

/**
 * What one mirror of the local uploads did: files and bytes newly copied, and
 * files that were already saved and left alone.
 */
final readonly class UploadedFilesMirrorResult
{
    public function __construct(
        public int $copied,
        public int $bytes,
        public int $alreadyPresent,
    ) {}
}
