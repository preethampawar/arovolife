<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankAogoGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A month's Rank-1 economics as the engine froze them onto the
 * rank_bonus_results rows — turnover, pool, RAP points, AO-GO points, total
 * points, point value — plus the CURRENT envelope / pool-% settings that feed
 * the pool formula (those two are live plan settings, not snapshots).
 *
 * Read by the admin Rank Bonus pages to show the client-requested header and
 * the point-value formula with the month's actual values.
 */
final class RankBonusMonthSnapshot
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * MAX() is safe because every Rank-1 row of the month carries the same
     * snapshot; qualifier_count is the engine's own payable count (held rows
     * and AO-GO grantee rows already excluded). Null when the month has no
     * Rank-1 rows.
     *
     * @return ?array{
     *     turnover_paise: int, envelope_bp: int, pool_pct: float, pool_paise: int,
     *     qualifiers: int, rap_points: ?int, aogo_points: int, total_points: ?int,
     *     point_value_paise: ?int, computed_at: ?Carbon
     * }
     */
    public function rank1(Carbon $month): ?array
    {
        $agg = DB::table('rank_bonus_results')
            ->where('month_start', $month->toDateString())
            ->where('rank_number', 1)
            ->selectRaw('MAX(company_turnover_paise) as turnover_paise')
            ->selectRaw('MAX(pool_paise) as pool_paise')
            ->selectRaw('MAX(qualifier_count) as qualifier_count')
            ->selectRaw('MAX(rap_points) as rap_points')
            ->selectRaw('MAX(total_points) as total_points')
            ->selectRaw('MAX(point_value_paise) as point_value_paise')
            ->selectRaw('MAX(created_at) as computed_at')
            ->selectRaw('COUNT(*) as row_count')
            ->first();

        if ($agg === null || (int) $agg->row_count === 0) {
            return null;
        }

        $aogoPoints = (int) RankAogoGrant::query()
            ->where('month_start', $month->toDateString())
            ->where('status', '!=', RankAogoGrant::STATUS_VOIDED)
            ->sum('points');

        return [
            'turnover_paise' => (int) $agg->turnover_paise,
            'envelope_bp' => $this->plan->rankEnvelopeBp(),
            'pool_pct' => $this->plan->rankPoolPct(1),
            'pool_paise' => (int) $agg->pool_paise,
            'qualifiers' => (int) $agg->qualifier_count,
            'rap_points' => $agg->rap_points !== null ? (int) $agg->rap_points : null,
            'aogo_points' => $aogoPoints,
            'total_points' => $agg->total_points !== null ? (int) $agg->total_points : null,
            'point_value_paise' => $agg->point_value_paise !== null ? (int) $agg->point_value_paise : null,
            'computed_at' => $agg->computed_at !== null ? Carbon::parse($agg->computed_at) : null,
        ];
    }
}
