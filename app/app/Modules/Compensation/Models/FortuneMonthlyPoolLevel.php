<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen per-absolute-matrix-level economics of a Fortune Bonus month
 * (KP 2026-08-09 cascade) — see FortuneMonthlyPool. Append-only for the same
 * reason as the parent: re-runs reconstruct incomes from these rows, so they
 * must never move once written.
 *
 * @property int $id
 * @property int $fortune_monthly_pool_id
 * @property int $matrix_level
 * @property string $payout_mode
 * @property int|null $cap_paise
 * @property int $participants
 * @property int $points
 * @property int $point_value_paise
 * @property int $paid_paise
 */
final class FortuneMonthlyPoolLevel extends Model
{
    protected $table = 'fortune_monthly_pool_levels';

    /** Append-only: frozen level economics are never recomputed. */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('fortune_monthly_pool_levels rows are frozen — a month\'s level economics are never recomputed.');
        });
    }

    protected $fillable = [
        'fortune_monthly_pool_id', 'matrix_level', 'payout_mode', 'cap_paise',
        'participants', 'points', 'point_value_paise', 'paid_paise',
    ];

    protected function casts(): array
    {
        return [
            'fortune_monthly_pool_id' => 'integer',
            'matrix_level' => 'integer',
            'cap_paise' => 'integer',
            'participants' => 'integer',
            'points' => 'integer',
            'point_value_paise' => 'integer',
            'paid_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<FortuneMonthlyPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(FortuneMonthlyPool::class, 'fortune_monthly_pool_id');
    }
}
