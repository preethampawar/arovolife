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
 * @property string $gateway
 * @property string|null $gateway_intent_id
 * @property string|null $gateway_order_id
 * @property string|null $gateway_payment_id
 * @property string|null $mode
 * @property string|null $method
 * @property string|null $error_code
 * @property string|null $error_description
 * @property string|null $cancel_reason
 * @property string|null $confirmed_via
 * @property int $attempt_count
 * @property int $amount_paise
 * @property string $status
 * @property string $idempotency_key
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $authorised_at
 * @property Carbon|null $captured_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $signature_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 */
final class PaymentIntent extends Model
{
    protected $table = 'payment_intents';

    public const STATUS_CREATED = 'created';

    public const STATUS_AUTHORISED = 'authorised';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const GATEWAY_STUB = 'stub';

    public const GATEWAY_RAZORPAY = 'razorpay';

    public const CONFIRMED_VIA_CALLBACK = 'callback';

    public const CONFIRMED_VIA_WEBHOOK = 'webhook';

    public const CONFIRMED_VIA_RECONCILE = 'reconcile';

    public const CONFIRMED_VIA_ADMIN = 'admin';

    public const CONFIRMED_VIA_ZERO_CASH = 'zero_cash';

    public const CONFIRMED_VIA_STUB = 'stub';

    protected $fillable = [
        'order_id', 'gateway', 'gateway_intent_id', 'gateway_order_id', 'gateway_payment_id',
        'mode', 'method', 'error_code', 'error_description', 'cancel_reason', 'confirmed_via',
        'attempt_count', 'amount_paise', 'status', 'idempotency_key', 'raw_payload',
        'authorised_at', 'captured_at', 'failed_at', 'expires_at', 'last_synced_at', 'signature_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'amount_paise' => 'int',
            'attempt_count' => 'int',
            'authorised_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'signature_verified_at' => 'datetime',
        ];
    }

    /** Still waiting on the buyer or the gateway — neither confirmed nor closed. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_CREATED, self::STATUS_AUTHORISED], true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }
}
