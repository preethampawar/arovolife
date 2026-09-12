<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Support;

/**
 * Severity is derived, never stored (plan §10.6). A provider declares the
 * ceiling for its type; an individual item past its SLA is promoted one level
 * up by `AbstractProvider::itemSeverity()`. Nobody maintains a priority column.
 */
final class Severity
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    /** Low to high — the index is the rank used for ordering and promotion. */
    private const ORDER = [self::INFO, self::WARNING, self::CRITICAL];

    public static function rank(string $severity): int
    {
        $rank = array_search($severity, self::ORDER, true);

        return $rank === false ? 0 : $rank;
    }

    /** One level up, saturating at CRITICAL. */
    public static function promote(string $severity): string
    {
        return self::ORDER[min(self::rank($severity) + 1, count(self::ORDER) - 1)];
    }

    /** The higher of two severities. */
    public static function max(string $a, string $b): string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }
}
