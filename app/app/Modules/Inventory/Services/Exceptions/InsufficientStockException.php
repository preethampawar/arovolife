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

    /** The buyer-facing refusal at checkout: what to do, not just the count. */
    public static function forCheckout(string $productName, int $available): self
    {
        return new self(self::checkoutMessage($productName, $available));
    }

    public static function checkoutMessage(string $productName, int $available): string
    {
        return $available > 0
            ? sprintf('Only %d left of %s, reduce the quantity to continue.', $available, $productName)
            : sprintf('%s is out of stock. Remove it from your cart to continue.', $productName);
    }
}
