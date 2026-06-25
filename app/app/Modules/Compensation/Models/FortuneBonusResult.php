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
 * @property int $gross_paise
 * @property int $tds_paise
 * @property int $net_paise
 * @property string $status
 * @property Carbon|null $credited_at
 */
final class FortuneBonusResult extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CREDITED = 'credited';

    public const string STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'distributor_id',
        'month_start',
        'position',
        'matrix_level',
        'gross_paise',
        'tds_paise',
        'net_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'matrix_level' => 'integer',
            'gross_paise' => 'integer',
            'tds_paise' => 'integer',
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
