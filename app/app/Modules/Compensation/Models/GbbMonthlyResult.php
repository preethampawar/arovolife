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
 * @property Carbon $year_month
 * @property int $agp_earned
 * @property int $company_turnover_paise
 * @property int $pool_paise
 * @property int $total_pool_agp
 * @property int|null $point_value_paise
 * @property int $gbb_gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $repurchase_deduction_paise
 * @property int $gbb_net_paise
 * @property string $status
 * @property Carbon|null $credited_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class GbbMonthlyResult extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_REVERSED = 'reversed';

    /**
     * Statuses whose gross was priced against the month's frozen pool. Used by
     * GrowthBoosterBonusService to decide whether a prematurely frozen pool row
     * may still be safely replaced.
     */
    public const POOL_FUNDED_STATUSES = [
        self::STATUS_CREDITED,
        self::STATUS_REVERSED,
    ];

    // Per-slab AGP lives in the admin-editable `gsb_slabs` table
    // (agp_per_occurrence column) and the point value cap in the
    // `comp.gbb.point_value_cap_paise` setting — read them through
    // CompensationPlanSettingsService, not constants on this model. There is no
    // per-distributor AGP cap (retired by the client 2026-10-09).

    protected $fillable = [
        'distributor_id',
        'year_month',
        'agp_earned',
        'company_turnover_paise',
        'pool_paise',
        'total_pool_agp',
        'point_value_paise',
        'gbb_gross_paise',
        'admin_charge_paise',
        'tds_paise',
        'repurchase_deduction_paise',
        'gbb_net_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'agp_earned' => 'int',
            'company_turnover_paise' => 'int',
            'pool_paise' => 'int',
            'total_pool_agp' => 'int',
            'point_value_paise' => 'int',
            'gbb_gross_paise' => 'int',
            'admin_charge_paise' => 'int',
            'tds_paise' => 'int',
            'repurchase_deduction_paise' => 'int',
            'gbb_net_paise' => 'int',
            'credited_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
