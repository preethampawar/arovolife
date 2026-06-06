<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Order;

/**
 * Single source of truth for how an order status is shown to a human: its
 * label and its badge colour. Used by the admin order views and the
 * distributor "My Orders" list/detail so both stay in lock-step.
 *
 * Colour classes are deliberately drawn from the palette already compiled by
 * the admin order views, so the badge renders correctly without a fresh
 * Tailwind build.
 */
final class OrderStatusBadge
{
    /**
     * status => [human label, tailwind badge classes].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const MAP = [
        Order::STATUS_DRAFT => ['Draft', 'bg-gray-100 text-gray-600 border-gray-200'],
        Order::STATUS_PLACED => ['Placed', 'bg-blue-50 text-blue-700 border-blue-200'],
        Order::STATUS_PAID => ['Paid', 'bg-sky-50 text-sky-700 border-sky-200'],
        Order::STATUS_READY_TO_SHIP => ['Ready to ship', 'bg-blue-50 text-blue-700 border-blue-200'],
        Order::STATUS_SHIPPED => ['Shipped', 'bg-indigo-50 text-indigo-700 border-indigo-200'],
        Order::STATUS_DELIVERED => ['Delivered', 'bg-amber-50 text-amber-700 border-amber-200'],
        Order::STATUS_CONFIRMED => ['Confirmed', 'bg-green-50 text-green-700 border-green-200'],
        Order::STATUS_CANCELLED => ['Cancelled', 'bg-gray-100 text-gray-600 border-gray-200'],
        Order::STATUS_REFUND_REQUESTED => ['Refund requested', 'bg-amber-50 text-amber-700 border-amber-200'],
        Order::STATUS_REFUND_INSPECTION => ['Refund inspection', 'bg-amber-50 text-amber-700 border-amber-200'],
        Order::STATUS_REFUNDED => ['Refunded', 'bg-red-50 text-red-700 border-red-200'],
    ];

    private const FALLBACK_CLASSES = 'bg-gray-100 text-gray-600 border-gray-200';

    /**
     * Statuses offered as filter chips in the admin order list, in workflow
     * order. A subset of the full status set (draft / intermediate / refund
     * sub-states aren't useful as top-level filters).
     *
     * @var list<string>
     */
    public const FILTERABLE = [
        Order::STATUS_PLACED,
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_DELIVERED,
        Order::STATUS_CONFIRMED,
        Order::STATUS_CANCELLED,
        Order::STATUS_REFUNDED,
    ];

    public static function label(string $status): string
    {
        return self::MAP[$status][0] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function classes(string $status): string
    {
        return self::MAP[$status][1] ?? self::FALLBACK_CLASSES;
    }
}
