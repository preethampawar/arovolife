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
 * @property string $award_description
 * @property string $status
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 */
final class LifetimeAwardMilestone extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'triggered_month',
        'award_description',
        'status',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'delivered_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
