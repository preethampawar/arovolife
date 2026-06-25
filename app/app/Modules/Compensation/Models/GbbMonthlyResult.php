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

    /** Max AGP a single distributor can earn per month. */
    public const AGP_CAP = 120;

    /** AGP awarded per GSB slab occurrence (slabs 4–7 give 0). */
    public const AGP_BY_SLAB = [
        1 => 12,
        2 => 5,
        3 => 2,
    ];

    protected $fillable = [
        'distributor_id',
        'year_month',
        'agp_earned',
        'company_turnover_paise',
        'pool_paise',
        'total_pool_agp',
        'gbb_gross_paise',
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
