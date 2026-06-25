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
    /** Bonus in paise earned by a participant at each matrix level (0-9). */
    public const array LEVEL_BONUS_PAISE = [
        0 => 339,
        1 => 1017,
        2 => 3050,
        3 => 4579,
        4 => 6888,
        5 => 2500,
        6 => 6000,
        7 => 5500,
        8 => 5100,
        9 => 0,
    ];

    /** Minimum personal BV required by eligibility tier (in paise). */
    public const array BV_REQUIRED_PAISE = [
        'new_joiner' => 300_000,
        'non_ranked' => 60_000,
        'rank_1' => 100_000,
        'rank_2' => 100_000,
        'rank_3' => 100_000,
        'rank_4' => 100_000,
        'rank_5' => 100_000,
    ];

    /** Minimum GSB slabs required by eligibility tier. */
    public const array SLABS_REQUIRED = [
        'new_joiner' => 1,
        'non_ranked' => 1,
        'rank_1' => 4,
        'rank_2' => 6,
        'rank_3' => 8,
        'rank_4' => 10,
        'rank_5' => 12,
    ];

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
