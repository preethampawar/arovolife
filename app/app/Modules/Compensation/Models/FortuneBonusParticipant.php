<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
    // Per-level FB points live in the admin-editable `fortune_bonus_levels`
    // table and the per-tier BV/slab gates in `fortune_bonus_tiers` — read them
    // through CompensationPlanSettingsService, not constants on this model.

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

    /**
     * The matrix is levels 0–9 only: 3^0 + … + 3^9 = 29,524 positions. Once
     * a month's matrix is full, no further qualifier is entered for that
     * month (the client, 2026-09-03).
     */
    public const int MAX_POSITIONS = 29_524;

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * The 1-indexed position of the node directly above $position in the 3-wide
     * forced matrix, or null for the root (position 1) and any invalid input.
     *
     * The matrix is filled sequentially, so the three children of node k sit at
     * positions 3k−1, 3k and 3k+1 — inverting that gives parent(p) =
     * intdiv(p + 1, 3). This is the exact inverse of the level arithmetic in
     * {@see self::levelFromPosition()}: a parent always sits one level up.
     */
    public static function parentPosition(int $position): ?int
    {
        if ($position <= 1) {
            return null;
        }

        return intdiv($position + 1, 3);
    }

    /**
     * Compute the matrix level (0–9) for a 1-indexed FCFS position: level L
     * holds 3^L positions. Uses cumulative node counts to avoid floating-point
     * errors. There is no level 10 — a position past MAX_POSITIONS is refused,
     * so the ruling that the matrix ends at level 9 is enforced here as well
     * as at enrolment.
     *
     * @throws InvalidArgumentException for a position outside the matrix
     */
    public static function levelFromPosition(int $position): int
    {
        if ($position <= 0) {
            return 0;
        }

        if ($position > self::MAX_POSITIONS) {
            throw new InvalidArgumentException('Position '.$position.' is outside the 29,524-position Fortune matrix.');
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
