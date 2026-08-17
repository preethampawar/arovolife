<?php

declare(strict_types=1);

namespace App\Modules\Shared\Security;

use RuntimeException;

/**
 * No scanner could examine the file.
 *
 * Distinct from `InfectedFileException` because the two want different
 * handling: an infected file is a rejection, an unavailable scanner is an
 * incident. Collapsing them would let a dead scanner look like a clean run.
 */
final class ScannerUnavailableException extends RuntimeException {}
