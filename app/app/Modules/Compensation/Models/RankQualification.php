<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $rank_number
 * @property string $month_start
 * @property int|null $left_genos_bv_paise
 * @property int|null $right_genos_bv_paise
 * @property int $occurrence_in_month
 * @property bool $is_carry_forward
 * @property string|null $carry_forward_from_month
 * @property string $status
 */
final class RankQualification extends Model
{
    public const string STATUS_QUALIFIED = 'qualified';

    public const string STATUS_VOIDED = 'voided';

    /** Human-readable rank names (1-indexed). */
    public const array RANK_NAMES = [
        1 => 'Silver Partner',
        2 => 'Pearl Partner',
        3 => 'Emerald Partner',
        4 => 'Gold Partner',
        5 => 'Diamond Partner',
        6 => 'Blue Diamond Partner',
        7 => 'Royal Diamond Partner',
        8 => 'Crown Diamond Partner',
        9 => 'Elite Diamond Partner',
    ];

    /** Pool percentage per rank (as float, e.g. 7.0 = 7%). */
    public const array POOL_PCT = [
        1 => 7.0,
        2 => 4.0,
        3 => 3.0,
        4 => 2.3,
        5 => 1.7,
        6 => 1.2,
        7 => 0.9,
        8 => 0.6,
        9 => 0.3,
    ];

    /**
     * Number of PYP occurrences required in a month to be paid.
     * Ranks 1-2: 1 occurrence. Ranks 3-5: 2 occurrences. Ranks 6-9: 3 occurrences.
     */
    public const array PYP_REQUIRED = [
        1 => 1,
        2 => 1,
        3 => 2,
        4 => 2,
        5 => 2,
        6 => 3,
        7 => 3,
        8 => 3,
        9 => 3,
    ];

    /** Minimum personal BV (paise) required for each rank. */
    public const array PERSONAL_BV_REQUIRED = [
        1 => 500_000,
        2 => 1_500_000,
        3 => 5_000_000,
        4 => 10_000_000,
        5 => 20_000_000,
        6 => 30_000_000,
        7 => 30_000_000,
        8 => 30_000_000,
        9 => 30_000_000,
    ];

    /** Monthly group BV thresholds (paise) for ranks 1 and 2 (left AND right must meet). */
    public const array GROUP_BV_REQUIRED = [
        1 => 30_000_000,  // 3L BV per side
        2 => 50_000_000,  // 5L BV per side
    ];

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'month_start',
        'left_genos_bv_paise',
        'right_genos_bv_paise',
        'occurrence_in_month',
        'is_carry_forward',
        'carry_forward_from_month',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'left_genos_bv_paise' => 'int',
            'right_genos_bv_paise' => 'int',
            'occurrence_in_month' => 'int',
            'is_carry_forward' => 'bool',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
