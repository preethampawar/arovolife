<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Exceptions;

use RuntimeException;

final class KycHasNoDocumentsError extends RuntimeException {}
