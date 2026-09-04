<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * A callback or webhook whose signature did not verify. Never carries the
 * signature or the secret in its message.
 */
final class SignatureVerificationException extends RuntimeException {}
