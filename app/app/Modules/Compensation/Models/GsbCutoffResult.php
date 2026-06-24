<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $cutoff_date
 * @property int $left_bv_paise
 * @property int $right_bv_paise
 * @property int $weaker_bv_paise
 * @property int|null $slab
 * @property int $gross_gsb_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $net_gsb_paise
 * @property int $power_cf_before_paise
 * @property int $power_cf_after_paise
 * @property string|null $power_side_after
 * @property int $slab1_weaker_cf_before_paise
 * @property int $slab1_weaker_cf_after_paise
 * @property string $status
 * @property string|null $failure_reason
 */
final class GsbCutoffResult extends Model
{
    public const STATUS_NO_MATCH = 'no_match';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_BELOW_600BV = 'below_600bv';

    protected $table = 'gsb_cutoff_results';

    protected $fillable = [
        'distributor_id', 'cutoff_date',
        'left_bv_paise', 'right_bv_paise', 'weaker_bv_paise',
        'slab', 'gross_gsb_paise', 'admin_charge_paise', 'tds_paise', 'net_gsb_paise',
        'power_cf_before_paise', 'power_cf_after_paise', 'power_side_after',
        'slab1_weaker_cf_before_paise', 'slab1_weaker_cf_after_paise',
        'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'left_bv_paise' => 'integer',
            'right_bv_paise' => 'integer',
            'weaker_bv_paise' => 'integer',
            'slab' => 'integer',
            'gross_gsb_paise' => 'integer',
            'admin_charge_paise' => 'integer',
            'tds_paise' => 'integer',
            'net_gsb_paise' => 'integer',
            'power_cf_before_paise' => 'integer',
            'power_cf_after_paise' => 'integer',
            'slab1_weaker_cf_before_paise' => 'integer',
            'slab1_weaker_cf_after_paise' => 'integer',
        ];
    }
}
