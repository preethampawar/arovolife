<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Events;

/**
 * Fired once an order has been successfully placed (after the placement
 * transaction commits). Carries only the order id so queued listeners reload a
 * fresh, committed order.
 */
final class OrderPlaced
{
    public function __construct(public readonly int $orderId) {}
}
