<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sponsor_id
 * @property int $sponsee_id
 * @property Carbon $cutoff_date
 * @property int $sponsee_gsb_paise
 * @property int|null $slab sponsee's matched GSB slab (points engine; null on legacy ladder rows)
 * @property int|null $msb_points slab msb_score snapshot at credit time (null on legacy ladder rows)
 * @property int|null $msb_point_value_paise per-point value snapshot at credit time (null on legacy ladder rows)
 * @property int|null $mb_rate_pct legacy rate-ladder field; null on points-engine rows
 * @property int $mb_gross_paise
 * @property int $repurchase_deduction_paise moved to the repurchase wallet at credit time
 * @property int $mb_admin_charge_paise
 * @property int $mb_tds_paise
 * @property int $mb_net_paise gross − repurchase deduction; what landed in the main wallet
 * @property int|null $sponsee_cumulative_gsb_paise legacy rate-ladder field; null on points-engine rows
 * @property string $status credited | failed | repurchase_gated
 * @property string|null $failure_reason on a repurchase_gated row: the verdict's reason (bv_short, wallet_nonzero, both)
 */
final class MentorshipBonusResult extends Model
{
    public const STATUS_CREDITED = 'credited';

    public const STATUS_FAILED = 'failed';

    /**
     * Client 2026-10-09: the sponsor was below the royalty rank and failed on
     * their repurchase condition on the cut-off day. The row carries the points
     * that would have accrued at ₹0 and the verdict's reason in failure_reason;
     * the points were kept out of the day's denominator.
     */
    public const STATUS_REPURCHASE_GATED = 'repurchase_gated';

    protected $table = 'mentorship_bonus_results';

    protected $fillable = [
        'sponsor_id', 'sponsee_id', 'cutoff_date',
        'sponsee_gsb_paise', 'slab', 'msb_points', 'msb_point_value_paise', 'mb_rate_pct',
        'mb_gross_paise', 'repurchase_deduction_paise', 'mb_admin_charge_paise', 'mb_tds_paise', 'mb_net_paise',
        'sponsee_cumulative_gsb_paise', 'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'sponsee_gsb_paise' => 'integer',
            'slab' => 'integer',
            'msb_points' => 'integer',
            'msb_point_value_paise' => 'integer',
            'mb_rate_pct' => 'integer',
            'mb_gross_paise' => 'integer',
            'repurchase_deduction_paise' => 'integer',
            'mb_admin_charge_paise' => 'integer',
            'mb_tds_paise' => 'integer',
            'mb_net_paise' => 'integer',
            'sponsee_cumulative_gsb_paise' => 'integer',
        ];
    }

    public function sponsee(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'sponsee_id');
    }
}
