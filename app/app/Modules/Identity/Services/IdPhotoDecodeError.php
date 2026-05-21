<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use RuntimeException;

/**
 * Raised by {@see IdPhotoStorage::replace()} when GD cannot decode the
 * uploaded bytes despite the image / mimes validators passing. Callers
 * catch this and surface a clean 422.
 */
final class IdPhotoDecodeError extends RuntimeException {}
