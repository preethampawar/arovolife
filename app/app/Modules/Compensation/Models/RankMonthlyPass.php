<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Frozen per-month, per-pass economics of the Rank Bonus (client 2026-10-05
 * Rank Income Point System). Pass 1 divides the whole envelope among the AGO
 * offer and Ranks 1..N; pass 2 divides what pass 1 left among Ranks N+1..9.
 * Written once, in the same transaction as the month's rank_monthly_pools
 * rows, before any credit — see RankBonusService::freezeMonth().
 *
 * month_start is deliberately NOT date-cast, for the reason given on
 * {@see RankMonthlyPool}: a raw 'Y-m-d' string keeps both drivers and both
 * query styles agreeing.
 *
 * @property int $id
 * @property string $month_start
 * @property int $pass
 * @property int $company_turnover_paise
 * @property int $envelope_bp
 * @property int $envelope_paise
 * @property int $pool_paise
 * @property int $total_points
 * @property int $raw_point_value_paise
 * @property int $point_value_cap_paise
 * @property int $point_value_paise
 * @property int $payout_paise
 * @property int $leftover_paise
 * @property Carbon|null $created_at
 */
final class RankMonthlyPass extends Model
{
    protected $table = 'rank_monthly_passes';

    /**
     * Append-only, like the pool rows: re-runs price against them.
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('rank_monthly_passes rows are frozen — a month\'s pass economics are never recomputed.');
        });
    }

    protected $fillable = [
        'month_start', 'pass', 'company_turnover_paise', 'envelope_bp',
        'envelope_paise', 'pool_paise', 'total_points', 'raw_point_value_paise',
        'point_value_cap_paise', 'point_value_paise', 'payout_paise',
        'leftover_paise',
    ];

    protected function casts(): array
    {
        return [
            'pass' => 'integer',
            'company_turnover_paise' => 'integer',
            'envelope_bp' => 'integer',
            'envelope_paise' => 'integer',
            'pool_paise' => 'integer',
            'total_points' => 'integer',
            'raw_point_value_paise' => 'integer',
            'point_value_cap_paise' => 'integer',
            'point_value_paise' => 'integer',
            'payout_paise' => 'integer',
            'leftover_paise' => 'integer',
        ];
    }
}
