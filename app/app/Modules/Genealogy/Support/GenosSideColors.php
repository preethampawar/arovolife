<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Support;

/**
 * The one place that decides how a Genos side is coloured: Left blue, Right
 * green (client, 2026-09-28). Literal class strings so Tailwind keeps them.
 */
final class GenosSideColors
{
    private const LEFT = [
        'bar' => 'bg-gradient-to-r from-sky-400 to-sky-600',
        'text' => 'text-sky-700',
        'chip' => 'border-sky-200 bg-sky-50 text-sky-700',
        'card' => 'border-sky-200 bg-gradient-to-br from-sky-50 to-white',
        'dot' => 'bg-sky-500',
    ];

    private const RIGHT = [
        'bar' => 'bg-gradient-to-r from-emerald-400 to-emerald-600',
        'text' => 'text-emerald-700',
        'chip' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'card' => 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white',
        'dot' => 'bg-emerald-500',
    ];

    /** @return array{bar: string, text: string, chip: string, card: string, dot: string} */
    public static function for(string $side): array
    {
        return in_array(strtolower($side), ['l', 'left'], true) ? self::LEFT : self::RIGHT;
    }
}
