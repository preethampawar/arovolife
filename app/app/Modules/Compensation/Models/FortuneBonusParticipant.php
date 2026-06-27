<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $month_start
 * @property int $position
 * @property int $matrix_level
 * @property string $eligibility_tier
 * @property string|null $first_gsb_date
 * @property Carbon $enrolled_at
 */
final class FortuneBonusParticipant extends Model
{
    // Level bonuses live in the admin-editable `fortune_bonus_levels` table and
    // the per-tier BV/slab gates in `fortune_bonus_tiers` — read them through
    // CompensationPlanSettingsService, not constants on this model.

    protected $fillable = [
        'distributor_id',
        'month_start',
        'position',
        'matrix_level',
        'eligibility_tier',
        'first_gsb_date',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'matrix_level' => 'integer',
            'enrolled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * Compute the matrix level (0-9) for a 1-indexed FCFS position.
     * Uses cumulative node count per level to avoid floating-point errors.
     */
    public static function levelFromPosition(int $position): int
    {
        if ($position <= 0) {
            return 0;
        }

        $cumulative = 0;

        for ($level = 0; $level <= 9; $level++) {
            $cumulative += (int) round(3 ** $level);

            if ($position <= $cumulative) {
                return $level;
            }
        }

        return 9;
    }
}
