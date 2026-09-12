<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Support;

/**
 * The six buckets the Action Center screen renders as cards, in the order
 * the plan's catalogue (§4) lists them. A group is presentation only — it
 * carries no permission of its own, because permission scoping is per
 * provider (plan §10.2).
 */
final class ActionGroup
{
    public const ORDERS = 'orders';

    public const STOCK = 'stock';

    public const MONEY = 'money';

    public const PEOPLE = 'people';

    public const COMPLIANCE = 'compliance';

    public const PLATFORM = 'platform';

    /** @var array<string, string> */
    private const LABELS = [
        self::ORDERS => 'Orders & fulfilment',
        self::STOCK => 'Stock',
        self::MONEY => 'Money',
        self::PEOPLE => 'People',
        self::COMPLIANCE => 'Compliance',
        self::PLATFORM => 'Platform',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }
}
