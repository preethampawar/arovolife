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
 * @property int $gbb_gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
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

    // AGP cap and per-slab AGP now live in the admin-editable `gsb_slabs` table
    // (agp_per_occurrence column) and the `comp.gbb.agp_cap` setting — read them
    // through CompensationPlanSettingsService, not constants on this model.

    protected $fillable = [
        'distributor_id',
        'year_month',
        'agp_earned',
        'company_turnover_paise',
        'pool_paise',
        'total_pool_agp',
        'gbb_gross_paise',
        'admin_charge_paise',
        'tds_paise',
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
            'gbb_gross_paise' => 'int',
            'admin_charge_paise' => 'int',
            'tds_paise' => 'int',
            'gbb_net_paise' => 'int',
            'credited_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
