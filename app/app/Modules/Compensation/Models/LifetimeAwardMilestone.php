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
 * @property int $rank_number
 * @property string $triggered_month
 * @property int $qualification_count
 * @property string $award_description
 * @property string $status
 * @property string|null $disbursement_type
 * @property int|null $gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int|null $net_paise
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 */
final class LifetimeAwardMilestone extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string DISBURSEMENT_GOODS = 'goods';

    public const string DISBURSEMENT_CASH = 'cash';

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'triggered_month',
        'qualification_count',
        'award_description',
        'status',
        'disbursement_type',
        'gross_paise',
        'admin_charge_paise',
        'tds_paise',
        'net_paise',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'qualification_count' => 'int',
            'gross_paise' => 'int',
            'admin_charge_paise' => 'int',
            'tds_paise' => 'int',
            'net_paise' => 'int',
            'delivered_at' => 'datetime',
        ];
    }

    /** Minimum qualification_count required before this milestone may be delivered. */
    public static function releaseThreshold(int $rank): int
    {
        return match (true) {
            $rank <= 2 => 1,
            $rank <= 5 => 2,
            default => 3,
        };
    }

    /** Whether the milestone has met its re-qualification requirement and may be delivered. */
    public function isReleasable(): bool
    {
        return $this->qualification_count >= self::releaseThreshold($this->rank_number);
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
