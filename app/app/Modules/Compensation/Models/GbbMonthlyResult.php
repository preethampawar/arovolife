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
     * Repurchase grace window: the bonus was calculated at the frozen point
     * value but not credited. Releasable by ReleaseHeldGbbOnReactivation once
     * the distributor completes their repurchase.
     */
    public const STATUS_REPURCHASE_HELD = 'repurchase_held';

    /**
     * Post-grace repurchase suspension: an audit-only row recording the AGP the
     * distributor earned and forfeited. Gross is 0, the AGP is excluded from
     * the month's denominator, and the row is NEVER released.
     */
    public const STATUS_REPURCHASE_SUSPENDED = 'repurchase_suspended';

    /**
     * The month was earned but the month closed with an unspent repurchase
     * wallet, which is a mandatory GBB qualification gate. Gross is 0, the AGP
     * is excluded from the month's denominator, and the row is NEVER released —
     * an audit row so the distributor is visible on the reports instead of
     * silently vanishing from the month.
     */
    public const STATUS_REPURCHASE_WALLET_BLOCKED = 'repurchase_wallet_blocked';

    /**
     * Statuses whose gross was priced against the month's frozen pool. Used by
     * GrowthBoosterBonusService to decide whether a prematurely frozen pool row
     * may still be safely replaced. Suspended rows carry gross 0 and their AGP
     * is excluded from the denominator, so they never consumed the pool.
     */
    public const POOL_FUNDED_STATUSES = [
        self::STATUS_CREDITED,
        self::STATUS_REPURCHASE_HELD,
        self::STATUS_REVERSED,
    ];

    /**
     * The complement of {@see POOL_FUNDED_STATUSES} over this model's status
     * set: the terminal statuses whose AGP was EXCLUDED from the month's frozen
     * denominator (see GrowthBoosterBonusService::runForMonth(), which sums the
     * denominator over payable + held only). A row in one of these states can
     * never be credited against that pool afterwards — the point value was
     * priced without its AGP, so paying it would overspend the pool and drive
     * gbb_monthly_pools.leftover_paise negative.
     *
     * STATUS_PENDING is deliberately in neither list: it is the in-flight
     * status writeResult() gives a payable row inside the engine transaction on
     * its way to `credited`, and that row's AGP IS in the denominator.
     */
    public const POOL_EXCLUDED_STATUSES = [
        self::STATUS_REPURCHASE_SUSPENDED,
        self::STATUS_REPURCHASE_WALLET_BLOCKED,
    ];

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
