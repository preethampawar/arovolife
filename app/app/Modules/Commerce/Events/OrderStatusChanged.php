<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Events;

/**
 * Fired after an order transitions between lifecycle states (paid, shipped,
 * delivered, confirmed, cancelled, …). Carries the order id and the old/new
 * status so listeners can reload a fresh order and craft accurate copy.
 */
final class OrderStatusChanged
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}
}
