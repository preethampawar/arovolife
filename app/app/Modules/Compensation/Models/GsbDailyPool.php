<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Frozen per-day economics of the GSB pro-rated pool (KP 2026-07-29).
 * Written once per cut-off date before any credit and never recomputed —
 * see GsbDailyPoolService::freezePoolForDate().
 *
 * @property int $id
 * @property Carbon $cutoff_date
 * @property int $company_bv_paise
 * @property int $pool_rate_bp
 * @property int $pool_paise
 * @property int $fixed_payout_paise
 * @property int $variable_total_score
 * @property int $variable_score_value_cap_paise
 * @property int $variable_score_value_paise
 * @property int $variable_payout_paise
 * @property int $leftover_paise
 * @property Carbon|null $created_at
 */
final class GsbDailyPool extends Model
{
    protected $table = 'gsb_daily_pools';

    /**
     * Append-only: the frozen day economics must never move once written —
     * re-runs and retries price against them. Enforced here, not just by
     * discipline in GsbDailyPoolService::freezePoolForDate().
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('gsb_daily_pools rows are frozen — a day\'s pool economics are never recomputed.');
        });
    }

    protected $fillable = [
        'cutoff_date', 'company_bv_paise', 'pool_rate_bp', 'pool_paise',
        'fixed_payout_paise', 'variable_total_score',
        'variable_score_value_cap_paise', 'variable_score_value_paise',
        'variable_payout_paise', 'leftover_paise',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'company_bv_paise' => 'integer',
            'pool_rate_bp' => 'integer',
            'pool_paise' => 'integer',
            'fixed_payout_paise' => 'integer',
            'variable_total_score' => 'integer',
            'variable_score_value_cap_paise' => 'integer',
            'variable_score_value_paise' => 'integer',
            'variable_payout_paise' => 'integer',
            'leftover_paise' => 'integer',
        ];
    }
}
