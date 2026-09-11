<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services\Exceptions;

use RuntimeException;

/**
 * A movement was refused because it would take stock below zero.
 *
 * The message is shown to an operator (and, via the checkout hook, to a
 * customer), so it names the SKU and the two numbers rather than the ids.
 */
final class InsufficientStockException extends RuntimeException
{
    public static function forVariant(string $label, int $needed, int $available, string $warehouseCode): self
    {
        return new self(sprintf(
            '%s: need %d, have %d in %s.',
            $label,
            $needed,
            $available,
            $warehouseCode,
        ));
    }
}
