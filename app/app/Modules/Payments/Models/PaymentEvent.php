<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Commerce\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gateway interaction. Append-only; never updated after creation.
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $payment_intent_id
 * @property int|null $refund_intent_id
 * @property string $gateway
 * @property string $direction
 * @property string $event_type
 * @property string|null $gateway_event_id
 * @property string|null $gateway_payment_id
 * @property bool $signature_verified
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $payload
 * @property string|null $error
 * @property Carbon $created_at
 */
final class PaymentEvent extends Model
{
    protected $table = 'payment_events';

    public const UPDATED_AT = null;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_CALLBACK = 'callback';

    public const DIRECTION_WEBHOOK = 'webhook';

    public const DIRECTION_SYSTEM = 'system';

    protected $fillable = [
        'order_id', 'payment_intent_id', 'refund_intent_id',
        'gateway', 'direction', 'event_type', 'gateway_event_id', 'gateway_payment_id',
        'signature_verified', 'http_status', 'duration_ms', 'payload', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified' => 'bool',
            'http_status' => 'int',
            'duration_ms' => 'int',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** @return BelongsTo<RefundIntent, $this> */
    public function refundIntent(): BelongsTo
    {
        return $this->belongsTo(RefundIntent::class);
    }
}
