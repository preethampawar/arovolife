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
 * @property int $rank_number
 * @property int $company_turnover_paise
 * @property int $pool_paise
 * @property int $qualifier_count
 * @property int $gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $net_paise
 * @property string $status
 * @property Carbon|null $credited_at
 */
final class RankBonusResult extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CREDITED = 'credited';

    public const string STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'distributor_id',
        'month_start',
        'rank_number',
        'company_turnover_paise',
        'pool_paise',
        'qualifier_count',
        'gross_paise',
        'admin_charge_paise',
        'tds_paise',
        'net_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'company_turnover_paise' => 'int',
            'pool_paise' => 'int',
            'qualifier_count' => 'int',
            'gross_paise' => 'int',
            'admin_charge_paise' => 'int',
            'tds_paise' => 'int',
            'net_paise' => 'int',
            'credited_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
