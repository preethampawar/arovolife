<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function daysRemaining(): int
    {
        if ($this->status !== self::STATUS_OPEN) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->ends_at, false));
    }
}
