<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services\Exceptions;

use RuntimeException;

final class CoolingOffWindowExpiredError extends RuntimeException {}
