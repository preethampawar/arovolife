<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $status
 * @property Carbon $ends_at
 */
final class OrderCoolingOff extends Model
{
    protected $table = 'order_cooling_off';

    public const STATUS_OPEN = 'open';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'opened_at', 'ends_at', 'status', 'refund_trigger_event_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whole days the buyer still has, counting the day they are on.
     *
     * Rounded up, not truncated: a window opened today and closing in 30 days
     * is 29.x days away by the clock, and telling the buyer of a statutory
     * 30-day window that they have 29 days left on the day it opened is the
     * kind of off-by-one a customer argues about on the last day (QA F59).
     */
    public function daysRemaining(): int
    {
        if ($this->status !== self::STATUS_OPEN) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInDays($this->ends_at, false)));
    }
}
