<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

final class LineChangeHasDownlineError extends RuntimeException {}
