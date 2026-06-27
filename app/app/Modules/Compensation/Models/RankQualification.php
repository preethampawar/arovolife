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

    // Rank names, pool %, PYP, personal-BV and group-BV requirements now live in
    // the admin-editable `rank_tiers` table — read them through
    // CompensationPlanSettingsService, not constants on this model.

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
