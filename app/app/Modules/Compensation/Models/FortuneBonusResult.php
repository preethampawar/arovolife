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
 * @property int|null $points
 * @property int|null $point_value_paise the value applied at this row's matrix level (cascade months) or the month-wide value (legacy rows)
 * @property int|null $min_commission_paise
 * @property int|null $cap_paise
 * @property int $gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $repurchase_deduction_paise
 * @property int $net_paise
 * @property string $status
 * @property Carbon|null $credited_at
 */
final class FortuneBonusResult extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CREDITED = 'credited';

    public const string STATUS_SKIPPED = 'skipped';

    /**
     * Enrolled and positioned for the month, but the distributor still held
     * repurchase-wallet money at the last instant of it — a mandatory monthly
     * qualification condition (client 2026-09-05, re-confirmed 2026-09-07).
     * Gross is 0, nothing is credited, the matrix position is kept, and the
     * share the cascade allowed for stays with the company as leftover. The
     * verdict is written once and NEVER released.
     */
    public const string STATUS_REPURCHASE_WALLET_BLOCKED = 'repurchase_wallet_blocked';

    protected $fillable = [
        'distributor_id',
        'month_start',
        'position',
        'matrix_level',
        'points',
        'point_value_paise',
        'min_commission_paise',
        'cap_paise',
        'gross_paise',
        'admin_charge_paise',
        'tds_paise',
        'repurchase_deduction_paise',
        'net_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'matrix_level' => 'integer',
            'points' => 'integer',
            'point_value_paise' => 'integer',
            'min_commission_paise' => 'integer',
            'cap_paise' => 'integer',
            'gross_paise' => 'integer',
            'admin_charge_paise' => 'integer',
            'tds_paise' => 'integer',
            'repurchase_deduction_paise' => 'integer',
            'net_paise' => 'integer',
            'credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
