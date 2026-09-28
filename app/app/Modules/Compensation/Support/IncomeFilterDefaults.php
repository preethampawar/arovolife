<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Default date range for the My Income bonus pages (client, 2026-09-28):
 * daily bonuses open on yesterday, monthly bonuses on last month. Applied only
 * to a first visit — a submitted or cleared filter form carries `f=1` and is
 * left exactly as the distributor set it.
 */
final class IncomeFilterDefaults
{
    public static function apply(Request $request, string $grain, ?Carbon $today = null): void
    {
        if ($request->has('f') || $request->filled('from') || $request->filled('to')) {
            return;
        }

        $today ??= Carbon::today('Asia/Kolkata');

        $value = $grain === 'monthly'
            ? $today->copy()->startOfMonth()->subMonth()->format('Y-m')
            : $today->copy()->subDay()->toDateString();

        $request->query->set('from', $value);
        $request->query->set('to', $value);
    }
}
