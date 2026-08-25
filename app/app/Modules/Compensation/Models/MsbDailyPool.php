<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Frozen per-day economics of the Mentorship Bonus pool (KP 2026-07-30).
 * Written once per cut-off date before any credit and never recomputed —
 * see MsbDailyPoolService::freezePoolForDate().
 *
 * @property int $id
 * @property Carbon $cutoff_date
 * @property int $company_bv_paise
 * @property int $pool_rate_bp
 * @property int $pool_paise
 * @property int $total_points
 * @property int $point_value_paise
 * @property int $payout_paise
 * @property int $leftover_paise
 * @property Carbon|null $created_at
 */
final class MsbDailyPool extends Model
{
    protected $table = 'msb_daily_pools';

    /**
     * Append-only: the frozen day economics must never move once written —
     * re-runs and retries price against them. Enforced here, not just by
     * discipline in MsbDailyPoolService::freezePoolForDate().
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('msb_daily_pools rows are frozen — a day\'s pool economics are never recomputed.');
        });
    }

    protected $fillable = [
        'cutoff_date', 'company_bv_paise', 'pool_rate_bp', 'pool_paise',
        'total_points', 'point_value_paise', 'payout_paise', 'leftover_paise',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'company_bv_paise' => 'integer',
            'pool_rate_bp' => 'integer',
            'pool_paise' => 'integer',
            'total_points' => 'integer',
            'point_value_paise' => 'integer',
            'payout_paise' => 'integer',
            'leftover_paise' => 'integer',
        ];
    }
}
