<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Frozen per-month economics of the Growth Booster Bonus pool.
 * Written once per month before any credit and never recomputed —
 * see GrowthBoosterBonusService::runForMonth().
 *
 * The month_start is deliberately NOT date-cast: the cast serialises through
 * the connection's datetime format, which on SQLite stores "2026-07-01
 * 00:00:00" and silently breaks a plain `where('month_start', '2026-07-01')`
 * lookup that works fine against a MySQL DATE column. Keeping it a raw
 * 'Y-m-d' string makes both drivers — and both query styles — agree.
 *
 * @property int $id
 * @property string $month_start
 * @property int $company_bv_paise
 * @property int $pool_rate_bp
 * @property int $pool_paise
 * @property int $total_agp
 * @property int $point_value_paise
 * @property int $payout_paise
 * @property int $leftover_paise
 */
final class GbbMonthlyPool extends Model
{
    protected $table = 'gbb_monthly_pools';

    /**
     * Append-only: the frozen month economics must never move once written —
     * re-runs and later releases price against them. Enforced here, not just by
     * discipline in GrowthBoosterBonusService::runForMonth().
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('gbb_monthly_pools rows are frozen — a month\'s pool economics are never recomputed.');
        });
    }

    protected $fillable = [
        'month_start', 'company_bv_paise', 'pool_rate_bp', 'pool_paise',
        'total_agp', 'point_value_paise', 'payout_paise', 'leftover_paise',
    ];

    protected function casts(): array
    {
        return [
            'company_bv_paise' => 'integer',
            'pool_rate_bp' => 'integer',
            'pool_paise' => 'integer',
            'total_agp' => 'integer',
            'point_value_paise' => 'integer',
            'payout_paise' => 'integer',
            'leftover_paise' => 'integer',
        ];
    }
}
