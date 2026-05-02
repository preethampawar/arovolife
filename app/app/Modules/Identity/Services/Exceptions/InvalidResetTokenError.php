<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services\Exceptions;

use RuntimeException;

final class InvalidResetTokenError extends RuntimeException {}
