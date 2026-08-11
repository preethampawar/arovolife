<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Frozen per-month economics of the Fortune Bonus pool (KP 2026-08-07).
 * Written once per month before any credit and never recomputed —
 * see FortuneBonusService::runForMonth().
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
 * @property int $total_points
 * @property int|null $point_value_paise null for KP 2026-08-09 cascade months — the values live on the level rows
 * @property int $payout_paise
 * @property int $leftover_paise
 * @property int|null $min_commission_paise
 * @property int|null $guaranteed_total_paise
 * @property bool $is_shortfall
 * @property int|null $shortfall_per_head_paise
 */
final class FortuneMonthlyPool extends Model
{
    protected $table = 'fortune_monthly_pools';

    /**
     * Append-only: the frozen month economics must never move once written —
     * re-runs price against them. Enforced here, not just by discipline in
     * FortuneBonusService::runForMonth().
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('fortune_monthly_pools rows are frozen — a month\'s pool economics are never recomputed.');
        });
    }

    protected $fillable = [
        'month_start', 'company_bv_paise', 'pool_rate_bp', 'pool_paise',
        'total_points', 'point_value_paise', 'payout_paise', 'leftover_paise',
        'min_commission_paise', 'guaranteed_total_paise', 'is_shortfall', 'shortfall_per_head_paise',
    ];

    protected function casts(): array
    {
        return [
            'company_bv_paise' => 'integer',
            'pool_rate_bp' => 'integer',
            'pool_paise' => 'integer',
            'total_points' => 'integer',
            'point_value_paise' => 'integer',
            'payout_paise' => 'integer',
            'leftover_paise' => 'integer',
            'min_commission_paise' => 'integer',
            'guaranteed_total_paise' => 'integer',
            'is_shortfall' => 'boolean',
            'shortfall_per_head_paise' => 'integer',
        ];
    }

    /** @return HasMany<FortuneMonthlyPoolLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(FortuneMonthlyPoolLevel::class, 'fortune_monthly_pool_id')->orderBy('matrix_level');
    }
}
