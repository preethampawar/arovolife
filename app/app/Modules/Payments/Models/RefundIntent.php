<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RefundIntent extends Model
{
    protected $table = 'refund_intents';

    public const STATUS_CREATED = 'created';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'payment_intent_id', 'gateway', 'gateway_refund_id',
        'amount_paise', 'status', 'reason_code', 'idempotency_key', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'int',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}
