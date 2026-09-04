<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Commerce\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $payment_intent_id
 * @property string $gateway
 * @property string|null $gateway_refund_id
 * @property string|null $mode
 * @property string|null $speed
 * @property int $amount_paise
 * @property string $status
 * @property string $reason_code
 * @property string $idempotency_key
 * @property Carbon|null $held_at
 * @property string|null $hold_reason
 * @property Carbon|null $released_at
 * @property int|null $released_by_user_id
 * @property string|null $error_code
 * @property string|null $error_description
 * @property int $attempt_count
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $failed_at
 * @property string|null $settled_via
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read PaymentIntent|null $paymentIntent
 */
final class RefundIntent extends Model
{
    protected $table = 'refund_intents';

    public const STATUS_CREATED = 'created';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const HOLD_AWAITING_RETURN = 'awaiting_return';

    public const SETTLED_VIA_GATEWAY = 'gateway';

    public const SETTLED_VIA_MANUAL_NEFT = 'manual_neft';

    protected $fillable = [
        'order_id', 'payment_intent_id', 'gateway', 'gateway_refund_id', 'mode', 'speed',
        'amount_paise', 'status', 'reason_code', 'idempotency_key',
        'held_at', 'hold_reason', 'released_at', 'released_by_user_id',
        'error_code', 'error_description', 'attempt_count', 'last_synced_at',
        'processed_at', 'failed_at', 'settled_via',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'int',
            'attempt_count' => 'int',
            'held_at' => 'datetime',
            'released_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** Created but deliberately not sent yet — waiting for the returned goods. */
    public function isHeld(): bool
    {
        return $this->held_at !== null && $this->released_at === null;
    }

    /** Sent (or sendable) and not yet confirmed processed by the gateway. */
    public function isOutstanding(): bool
    {
        return $this->status !== self::STATUS_PROCESSED;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }
}
