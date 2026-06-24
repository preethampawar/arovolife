<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $batch_date
 * @property string $status
 * @property int $total_gross_paise
 * @property int $total_deductions_paise
 * @property int $total_net_paise
 * @property int $distributor_count
 * @property Carbon|null $processed_at
 */
final class PayoutBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'payout_batches';

    protected $fillable = [
        'batch_date', 'status',
        'total_gross_paise', 'total_deductions_paise', 'total_net_paise',
        'distributor_count', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'processed_at' => 'datetime',
            'total_gross_paise' => 'integer',
            'total_deductions_paise' => 'integer',
            'total_net_paise' => 'integer',
            'distributor_count' => 'integer',
        ];
    }
}
