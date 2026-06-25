<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $center_id
 * @property int $distributor_id
 * @property string $month_start
 * @property int $member_count
 * @property int $total_member_bv_paise
 * @property int $gross_paise
 * @property int $tds_paise
 * @property int $net_paise
 * @property string $status
 * @property Carbon|null $credited_at
 */
final class AdcBonusResult extends Model
{
    /** 3% of member BV, capped at ₹1,00,000/month (10,000,000 paise). */
    public const float BONUS_RATE = 0.03;

    public const int MONTHLY_CAP_PAISE = 10_000_000;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CREDITED = 'credited';

    public const string STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'center_id',
        'distributor_id',
        'month_start',
        'member_count',
        'total_member_bv_paise',
        'gross_paise',
        'tds_paise',
        'net_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'total_member_bv_paise' => 'integer',
            'gross_paise' => 'integer',
            'tds_paise' => 'integer',
            'net_paise' => 'integer',
            'credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AreteCenter, $this> */
    public function center(): BelongsTo
    {
        return $this->belongsTo(AreteCenter::class, 'center_id');
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }
}
