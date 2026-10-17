<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Frozen per-month, per-rank economics of the Rank Bonus pool.
 * Written once per (month, rank) before any credit and never recomputed —
 * see RankBonusService::runForMonth().
 *
 * The month_start is deliberately NOT date-cast: the cast serialises through
 * the connection's datetime format, which on SQLite stores "2026-07-01
 * 00:00:00" and silently breaks a plain `where('month_start', '2026-07-01')`
 * lookup that works fine against a MySQL DATE column. Keeping it a raw
 * 'Y-m-d' string makes both drivers — and both query styles — agree.
 *
 * Since the client's 2026-10-05 Rank Income Point System the month is priced
 * in two passes ({@see RankMonthlyPass}): pool_paise is this rank's allotment
 * (total_points × the pass's point value) and leftover_paise is 0 — the pass
 * row carries the leftover. Months frozen earlier keep the per-rank pool rule
 * they were priced under and read pass 1.
 *
 * @property int $id
 * @property string $month_start
 * @property int $rank_number
 * @property int $pass
 * @property int $company_turnover_paise
 * @property int $envelope_bp
 * @property int $pool_paise
 * @property int|null $rap_points
 * @property int $payable_count
 * @property int $aogo_points
 * @property int|null $total_points
 * @property int|null $point_value_paise
 * @property int $gross_per_qualifier_paise
 * @property int $payout_paise
 * @property int $leftover_paise
 * @property Carbon|null $created_at
 */
final class RankMonthlyPool extends Model
{
    protected $table = 'rank_monthly_pools';

    /**
     * Append-only: the frozen month economics must never move once written —
     * re-runs price against them. Enforced here, not just by discipline in
     * RankBonusService::freezeMonth().
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('rank_monthly_pools rows are frozen — a month\'s pool economics are never recomputed.');
        });
    }

    protected $fillable = [
        'month_start', 'rank_number', 'pass', 'company_turnover_paise', 'envelope_bp',
        'pool_paise', 'rap_points', 'payable_count', 'aogo_points',
        'total_points', 'point_value_paise', 'gross_per_qualifier_paise',
        'payout_paise', 'leftover_paise',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'integer',
            'company_turnover_paise' => 'integer',
            'envelope_bp' => 'integer',
            'pass' => 'integer',
            'pool_paise' => 'integer',
            'rap_points' => 'integer',
            'payable_count' => 'integer',
            'aogo_points' => 'integer',
            'total_points' => 'integer',
            'point_value_paise' => 'integer',
            'gross_per_qualifier_paise' => 'integer',
            'payout_paise' => 'integer',
            'leftover_paise' => 'integer',
        ];
    }
}
